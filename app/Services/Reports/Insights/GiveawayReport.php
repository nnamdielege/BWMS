<?php

namespace App\Services\Reports\Insights;

use App\Enums\OrderStatus;
use App\Models\Setting;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports → Samples, gifts & favours: everything given away in the dates, by reason,
 * recipient, product and person, at cost; spend against the monthly budget; and, for
 * samples to customers, whether the customer then ordered that product.
 *
 * Conversion: a sample to a customer converted within N days (30, 60) when the customer
 * placed an order (not cancelled) with that product in the N days after the sample.
 * Until N days have passed with no order it's "pending" and left out of the %.
 */
class GiveawayReport
{
    public const GROUPS = ['reason', 'recipient', 'product', 'person'];

    public const WINDOWS = [30, 60];

    public const YES = 'yes';

    public const NO = 'no';

    public const PENDING = 'pending';

    public function build(InsightFilters $f): array
    {
        [$start, $end] = $f->window();
        $rows = $this->rows($f, $start, $end);
        $samples = $this->conversion($rows->where('code', 'SAMPLE')->whereNotNull('customer_id'));

        $groups = [];
        foreach (self::GROUPS as $by) {
            $groups[$by] = $this->group($rows, $by, $samples);
        }

        return [
            'rows' => $rows->values()->all(),
            'totals' => [
                'count' => $rows->count(),
                'units' => (int) $rows->sum('units'),
                'value' => round($rows->sum('value'), 2),
                'samples_to_customers' => $samples->count(),
            ] + self::rates($samples),
            'groups' => $groups,
            'samples' => $samples->values()->all(),
            'budget' => $this->budget($f, $rows),
        ];
    }

    /** One row per give-away in the dates. */
    private function rows(InsightFilters $f, $start, $end): Collection
    {
        $tz = config('bwms.business_timezone');
        $q = DB::table('giveaways as g')
            ->join('stock_movements as m', 'm.id', '=', 'g.stock_movement_id')
            ->join('reason_codes as rc', 'rc.id', '=', 'g.reason_code_id')
            ->join('products as p', 'p.id', '=', 'g.product_id')
            ->leftJoin('product_units as pu', 'pu.id', '=', 'g.product_unit_id')
            ->leftJoin('customers as c', 'c.id', '=', 'g.customer_id')
            ->join('users as by', 'by.id', '=', 'g.created_by')
            ->join('users as ap', 'ap.id', '=', 'g.approved_by')
            ->whereBetween('g.occurred_at', [$start, $end]);
        $f->apply($q, 'g.product_id', 'm.location_id');

        return $q->orderBy('g.occurred_at')->get([
            'g.id', 'g.stock_movement_id', 'g.occurred_at', 'rc.id as reason_id', 'rc.code', 'rc.name as reason', 'p.id as product_id', 'p.sku', 'p.name',
            'p.base_unit', 'pu.name as pack', 'g.packs', 'g.qty_base', 'g.value', 'g.customer_id', 'c.name as customer', 'g.recipient_name',
            'g.purpose', 'by.id as person_id', 'by.name as person', 'ap.name as approved_by',
        ])->map(function ($g) use ($tz) {
            $g->date = Carbon::parse($g->occurred_at, 'UTC')->setTimezone($tz)->toDateString();
            $g->recipient = $g->customer ?? $g->recipient_name;
            $g->recipient_key = $g->customer_id ? "c{$g->customer_id}" : 'n'.mb_strtolower(trim((string) $g->recipient_name));
            $g->units = (int) $g->qty_base;
            $g->value = (float) $g->value;
            $g->packs = (float) $g->packs;

            return $g;
        });
    }

    /** Each sample to a customer, with whether they ordered the product within 30 and 60 days. */
    private function conversion(Collection $samples): Collection
    {
        if ($samples->isEmpty()) {
            return collect();
        }
        $tz = config('bwms.business_timezone');
        $today = Carbon::parse(BusinessDate::today());
        $orders = DB::table('orders as o')->join('order_lines as ol', 'ol.order_id', '=', 'o.id')
            ->whereIn('o.customer_id', $samples->pluck('customer_id')->unique())
            ->whereIn('ol.product_id', $samples->pluck('product_id')->unique())
            ->where('o.status', '!=', OrderStatus::Cancelled->value)
            ->where('o.created_at', '>=', Carbon::parse($samples->min('occurred_at'), 'UTC'))
            ->get(['o.customer_id', 'ol.product_id', 'o.created_at', 'o.number'])
            ->groupBy(fn ($o) => "{$o->customer_id}:{$o->product_id}");

        return $samples->map(function ($s) use ($orders, $today, $tz) {
            $at = Carbon::parse($s->occurred_at, 'UTC');
            $first = ($orders["{$s->customer_id}:{$s->product_id}"] ?? collect())
                ->filter(fn ($o) => Carbon::parse($o->created_at, 'UTC')->gt($at))
                ->sortBy('created_at')->first();
            $days = $first ? $at->diffInDays(Carbon::parse($first->created_at, 'UTC')) : null;
            $row = (array) $s + [
                'first_order' => $first?->number,
                'first_order_date' => $first ? Carbon::parse($first->created_at, 'UTC')->setTimezone($tz)->toDateString() : null,
                'days_to_order' => $days === null ? null : (int) floor($days),
            ];
            foreach (self::WINDOWS as $n) {
                $row["within_{$n}"] = self::outcome($days, $n, (int) Carbon::parse($s->date)->diffInDays($today));
            }

            return $row;
        })->values();
    }

    /** yes: ordered within $window days; no: the window has passed without an order; pending: not yet. */
    public static function outcome(?float $daysToOrder, int $window, int $daysSinceSample): string
    {
        return match (true) {
            $daysToOrder !== null && $daysToOrder <= $window => self::YES,
            $daysSinceSample >= $window => self::NO,
            default => self::PENDING,
        };
    }

    /** @return array{converted_30: int, decided_30: int, conversion_30: ?float, converted_60: int, decided_60: int, conversion_60: ?float} */
    private static function rates(Collection $samples): array
    {
        $out = [];
        foreach (self::WINDOWS as $n) {
            $yes = $samples->where("within_{$n}", self::YES)->count();
            $decided = $yes + $samples->where("within_{$n}", self::NO)->count();
            $out["converted_{$n}"] = $yes;
            $out["decided_{$n}"] = $decided;
            $out["conversion_{$n}"] = $decided ? round($yes / $decided * 100, 1) : null;
        }

        return $out;
    }

    /** @return list<array> biggest cost first */
    private function group(Collection $rows, string $by, Collection $samples): array
    {
        [$key, $label, $sampleKey] = match ($by) {
            'reason' => ['reason_id', fn ($r) => $r->reason, 'reason_id'],
            'recipient' => ['recipient_key', fn ($r) => $r->recipient.($r->customer_id ? '' : ' (not a customer)'), 'recipient_key'],
            'product' => ['product_id', fn ($r) => trim("{$r->sku} {$r->name}"), 'product_id'],
            'person' => ['person_id', fn ($r) => $r->person, 'person_id'],
        };

        return $rows->groupBy($key)->map(fn (Collection $g, $k) => [
            'key' => (string) $k,
            'label' => $label($g->first()),
            'product_id' => $by === 'product' ? $g->first()->product_id : null,
            'count' => $g->count(),
            'units' => (int) $g->sum('units'),
            'value' => round($g->sum('value'), 2),
            'samples' => $samples->where($sampleKey, $g->first()->{$sampleKey})->count(),
        ] + self::rates($samples->where($sampleKey, $g->first()->{$sampleKey})))
            ->sortBy([['value', 'desc'], ['label', 'asc']])->values()->all();
    }

    /** @return array{monthly: ?float, months: list<array{month: string, label: string, spent: float, budget: ?float, over: bool}>} */
    private function budget(InsightFilters $f, Collection $rows): array
    {
        $budget = Setting::giveawayBudget();
        $months = [];
        for ($m = Carbon::parse($f->from)->startOfMonth(); $m->lte(Carbon::parse($f->to)); $m->addMonthNoOverflow()) {
            $spent = round($rows->filter(fn ($r) => str_starts_with($r->date, $m->format('Y-m')))->sum('value'), 2);
            $months[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'spent' => $spent, 'budget' => $budget,
                'over' => $budget !== null && $spent > $budget];
        }

        return ['monthly' => $budget, 'months' => $months];
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $r, InsightFilters $f, string $groupBy, string $format): array
    {
        $t = $r['totals'];
        $label = ['reason' => 'Reason', 'recipient' => 'Recipient', 'product' => 'Product', 'person' => 'Entered by'][$groupBy];
        $pct = fn ($v) => $v === null ? '' : $v;

        return InsightExport::make('Samples gifts and favours', $f, [
            'At cost (today\'s average cost when given). Conversion: the customer ordered that product (order not cancelled) within 30 / 60 days of the sample; pending ones are left out of the %.',
            "Given away {$t['count']} time(s), {$t['units']} units, {$t['value']} at cost. Samples to customers {$t['samples_to_customers']}: converted {$t['converted_30']} of {$t['decided_30']} in 30 days, {$t['converted_60']} of {$t['decided_60']} in 60 days.",
        ], [
            ['title' => "By {$label}", 'columns' => [$label, 'Count', 'Units', 'Cost', 'Samples to customers', '30-day conversion %', '60-day conversion %'],
                'rows' => array_map(fn ($g) => [$g['label'], $g['count'], $g['units'], $g['value'], $g['samples'], $pct($g['conversion_30']), $pct($g['conversion_60'])], $r['groups'][$groupBy]),
                'money' => [3]],
            ['title' => 'Budget', 'columns' => ['Month', 'Spent', 'Budget', 'Over'],
                'rows' => array_map(fn ($m) => [$m['label'], $m['spent'], $m['budget'], $m['over'] ? 'yes' : ''], $r['budget']['months']), 'money' => [1, 2]],
            ['title' => 'Samples to customers', 'columns' => ['Date', 'Customer', 'SKU', 'Product', 'Units', 'Cost', 'First order after', 'Days', 'Within 30', 'Within 60'],
                'rows' => array_map(fn ($s) => [$s['date'], $s['customer'], $s['sku'], $s['name'], $s['units'], $s['value'], $s['first_order'], $s['days_to_order'], $s['within_30'], $s['within_60']], $r['samples']),
                'money' => [5]],
            ['title' => 'Every give-away', 'columns' => ['Date', 'Reason', 'To', 'SKU', 'Product', 'Packs', 'Pack', 'Units', 'Cost', 'Purpose', 'Approved by', 'Entered by'],
                'rows' => array_map(fn ($g) => [$g->date, $g->reason, $g->recipient, $g->sku, $g->name, $g->packs, $g->pack, $g->units, $g->value, $g->purpose, $g->approved_by, $g->person], $r['rows']),
                'money' => [8]],
        ], $format);
    }
}
