<?php

namespace App\Services\Reports;

use App\Models\OrderSubstitution;
use App\Services\Documents\CsvWriter;
use App\Services\Documents\XlsxWriter;
use App\Support\PackQty;
use Illuminate\Support\Carbon;

/**
 * Substitutions made between two dates (Brisbane), undone ones left out: who got what
 * instead of what, and what it did to the price. Money ex GST.
 */
class SubstitutionReport
{
    public const FORMATS = ['xlsx', 'csv'];

    /** @return array{rows: list<array>, totals: array{count: int, price_difference: float}} */
    public function build(string $from, string $to): array
    {
        $tz = config('bwms.business_timezone');
        $subs = OrderSubstitution::active()
            ->whereBetween('created_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])
            ->with(['order:id,number,channel,customer_id', 'order.customer:id,name', 'originalLine.unit:id,name', 'originalProduct:id,sku,name,base_unit',
                'substituteProduct:id,sku,name,base_unit', 'substituteUnit:id,name', 'creator:id,name'])
            ->orderBy('created_at')->orderBy('id')->get();

        $rows = $subs->map(fn (OrderSubstitution $s) => [
            'id' => $s->id,
            'date' => $s->created_at->setTimezone($tz)->format('Y-m-d H:i'),
            'order_id' => $s->order_id,
            'order' => $s->order->number,
            'channel' => $s->order->channel->value,
            'customer' => $s->order->customer?->name,
            'original_sku' => $s->originalProduct->sku,
            'original_name' => $s->originalProduct->name,
            'original_qty' => PackQty::label($s->original_qty_base, $s->original_per, $s->originalLine?->unit?->name ?? 'pack', $s->originalProduct->base_unit),
            'original_price' => $s->original_unit_price !== null ? round((float) $s->original_unit_price, 2) : null,
            'substitute_sku' => $s->substituteProduct->sku,
            'substitute_name' => $s->substituteProduct->name,
            'substitute_qty' => PackQty::label($s->substitute_qty_base, $s->substitute_per, $s->substituteUnit->name, $s->substituteProduct->base_unit),
            'substitute_price' => $s->substitute_unit_price !== null ? round((float) $s->substitute_unit_price, 2) : null,
            'price_difference' => $s->price_difference !== null ? (float) $s->price_difference : null,
            'kept_original_price' => $s->kept_original_price,
            'note' => $s->note,
            'by' => $s->creator?->name,
        ])->values()->all();

        return ['rows' => $rows, 'totals' => ['count' => count($rows), 'price_difference' => round(array_sum(array_map(fn ($r) => $r['price_difference'] ?? 0, $rows)), 2)]];
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $report, string $from, string $to, string $format): array
    {
        $header = [['Substitutions', "{$from} to {$to}"], ['Money ex GST. Price difference = substitute value − original value; undone substitutions left out.']];
        $columns = ['Date', 'Order', 'Customer', 'Original SKU', 'Original', 'Original qty', 'Original price / pack',
            'Substitute SKU', 'Substitute', 'Substitute qty', 'Substitute price / pack', 'Price difference', 'Note', 'By'];
        $rows = array_map(fn ($r) => [$r['date'], $r['order'], $r['customer'], $r['original_sku'], $r['original_name'], $r['original_qty'], $r['original_price'],
            $r['substitute_sku'], $r['substitute_name'], $r['substitute_qty'], $r['substitute_price'], $r['price_difference'], $r['note'], $r['by']], $report['rows']);
        $rows[] = ['Total', "{$report['totals']['count']} substitutions", '', '', '', '', '', '', '', '', '', $report['totals']['price_difference']];

        if ($format === 'csv') {
            $body = CsvWriter::toString([...$header, [], $columns, ...$rows]);
            $type = 'text/csv; charset=UTF-8';
        } else {
            $xlsx = (new XlsxWriter('Substitutions'))->landscape()->widths([16, 12, 24, 12, 26, 14, 10, 12, 26, 14, 10, 10, 34, 14])->moneyColumns([6, 10, 11])
                ->footer('&LSubstitutions&RPage &P of &N');
            foreach ($header as $h) {
                $xlsx->row($h, true);
            }
            $xlsx->row([])->headerRow($columns);
            foreach ($rows as $r) {
                $xlsx->row($r, $r[0] === 'Total');
            }
            $body = $xlsx->toString();
            $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return ['filename' => "substitutions-{$from}-to-{$to}.{$format}", 'type' => $type, 'body' => $body];
    }
}
