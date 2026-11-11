<?php

namespace App\Services\Reports;

use App\Services\Documents\CsvWriter;
use App\Services\Documents\XlsxWriter;

/**
 * The stock value and drop reports as a spreadsheet, the same figures as on screen.
 * Products missing a cost or sell price stay in the sheet, flagged, outside the totals.
 */
class MarginExport
{
    public const VIEWS = ['stock', 'drops', 'real'];

    public const FORMATS = ['xlsx', 'csv'];

    private const TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv; charset=UTF-8',
    ];

    /** @return array{filename: string, type: string, body: string} */
    public function stock(array $report, string $basis, bool $sellableOnly, string $format): array
    {
        $header = [
            ['Stock value & margin', now(config('bwms.business_timezone'))->format('j M Y H:i')],
            ['Quantity', $basis === StockValueReport::AVAILABLE ? 'Available' : 'On hand', $sellableOnly ? 'Sellable products only' : 'All products'],
            ['Money ex GST. Cost = weighted average cost (COGS). Products missing a cost or sell price are flagged and left out of the totals.'],
        ];
        $columns = ['Range', 'SKU', 'Product', 'Pack', 'On hand (packs)', 'Avg cost / pack', 'Sell / pack', 'Sell price from',
            'Value at cost', 'Value at sell', 'GM %', 'Reorder (packs)', 'Last received', 'Flag'];

        $rows = [];
        foreach ($report['groups'] as $group) {
            foreach ($group['rows'] as $r) {
                $rows[] = [$group['range'], $r['sku'], $r['name'], $r['pack_qty'] > 1 ? "{$r['pack']} ×{$r['pack_qty']}" : $r['pack'],
                    $r['packs'], $r['cost_per_pack'], $r['sell_per_pack'], self::source($r['sell_source']),
                    $r['value_cost'], $r['value_sell'], $r['gm_percent'], $r['reorder_packs'], $r['last_received'], self::flag($r['missing'])];
            }
            $rows[] = self::totalRow("{$group['range']} total", $group['totals']);
        }
        $t = $report['totals'];
        $rows[] = self::totalRow('Total', $t);
        $summary = [[], ['Total at cost', $t['value_cost']], ['Total at sell', $t['value_sell']], ['Potential GP', $t['gp']], ['Weighted GM %', $t['gm_percent']]];
        if ($report['excluded']['count']) {
            $summary[] = ["{$report['excluded']['count']} product(s) left out of the totals: no cost or no sell price."];
        }

        return $this->file('stock-value', $format, $header, $columns, $rows, [5, 6], [8, 9], [12, 12, 34, 14, 10, 10, 10, 12, 12, 12, 8, 10, 12, 18], $summary);
    }

    /** @return array{filename: string, type: string, body: string} */
    public function drops(array $report, int $units, float $deliveryCost, string $format): array
    {
        $header = [
            ['Drop profitability (modelled)', now(config('bwms.business_timezone'))->format('j M Y H:i')],
            ['Packs per drop', $units, 'Delivery cost per drop', $deliveryCost],
            ['Money ex GST. Break-even = packs one drop needs for its GP to cover the delivery.'],
        ];
        $columns = ['Range', 'SKU', 'Product', 'Pack', 'Sell / pack', 'Cost / pack', 'GM %', 'Sales', 'Cost', 'GP', 'Net after delivery', 'Break-even packs'];
        $rows = array_map(fn ($r) => [$r['range'], $r['sku'], $r['name'], $r['pack'], $r['sell_per_pack'], $r['cost_per_pack'], $r['gm_percent'],
            $r['sales'], $r['cost'], $r['gp'], $r['net'], $r['break_even'] ?? 'never'], $report['rows']);
        $summary = $report['missing'] === [] ? [] : [[], [count($report['missing']).' product(s) not shown: no cost or no sell price.'],
            ...array_map(fn ($m) => [$m['range'], $m['sku'], $m['name'], self::flag($m['missing'])], $report['missing'])];

        return $this->file('drop-profitability', $format, $header, $columns, $rows, [5], [4, 7, 8, 9, 10], [12, 12, 34, 14, 10, 10, 8, 10, 10, 10, 12, 10], $summary);
    }

    /** @return array{filename: string, type: string, body: string} */
    public function real(array $report, string $from, string $to, float $deliveryCost, float $freeMin, string $format): array
    {
        $header = [
            ['Real drops', "{$from} to {$to}"],
            ['Delivery cost per drop', $deliveryCost, 'Free delivery from', $freeMin],
            ['Money ex GST. Each dispatched order is one drop; cost of goods at the cost when dispatched, else today\'s cost.'],
        ];
        $columns = ['Delivery date', 'Order', 'Customer', 'Area', 'Sales', 'Cost of goods', 'GP', 'Delivery', 'Net', 'Under minimum', 'Cost at', 'Price'];
        $rows = array_map(fn ($o) => [$o['delivery_date'], $o['number'], $o['customer'], $o['area'], $o['sales'], $o['cost'], $o['gp'],
            $o['delivery_cost'], $o['net'], $o['under_minimum'] ? 'yes' : '', $o['cost_basis'] === 'dispatch' ? 'dispatch' : ($o['cost_basis'] === 'current' ? 'today' : 'mixed'),
            $o['price_estimated'] ? 'estimated' : ''], $report['orders']);
        $t = $report['totals'];
        $rows[] = ['Total', "{$t['drops']} drops", '', '', $t['sales'], $t['cost'], $t['gp'], $t['delivery_cost'], $t['net'], $t['under_minimum'] ?: ''];
        $summary = [[], ['Area', 'Drops', 'Sales', 'GP', 'Net', 'Average net per drop', 'Under minimum']];
        foreach ($report['areas'] as $a) {
            $summary[] = [$a['area'], $a['drops'], $a['sales'], $a['gp'], $a['net'], $a['avg_net'], $a['under_minimum']];
        }

        return $this->file("real-drops-{$from}-to-{$to}", $format, $header, $columns, $rows, [], [4, 5, 6, 7, 8], [12, 14, 28, 18, 10, 10, 10, 10, 10, 10, 10, 10], $summary);
    }

    private function file(string $name, string $format, array $header, array $columns, array $rows, array $costCols, array $moneyCols, array $widths, array $summary): array
    {
        if ($format === 'csv') {
            $body = CsvWriter::toString([...$header, [], $columns, ...$rows, ...$summary]);
        } else {
            $xlsx = (new XlsxWriter('Report'))->landscape()->widths($widths)->costColumns($costCols)->moneyColumns($moneyCols)
                ->footer('&L'.str_replace('&', '&&', $header[0][0]).'&RPage &P of &N');
            foreach ($header as $h) {
                $xlsx->row($h, true);
            }
            $xlsx->row([])->headerRow($columns);
            foreach ($rows as $r) {
                $xlsx->row($r, is_string($r[0] ?? null) && str_ends_with($r[0], 'otal'));
            }
            foreach ($summary as $s) {
                $xlsx->row($s);
            }
            $body = $xlsx->toString();
        }

        return [
            'filename' => $name.'-'.now(config('bwms.business_timezone'))->format('Y-m-d').".{$format}",
            'type' => self::TYPES[$format],
            'body' => $body,
        ];
    }

    private static function totalRow(string $label, array $t): array
    {
        return [$label, '', $t['excluded'] ? "{$t['excluded']} left out" : '', '', '', '', '', '', $t['value_cost'], $t['value_sell'], $t['gm_percent']];
    }

    private static function source(?string $source): string
    {
        return match ($source) {
            PackPricing::SOURCE_DEFAULT => 'default',
            PackPricing::SOURCE_ORDERMENTUM => 'Ordermentum',
            default => '',
        };
    }

    private static function flag(array $missing): string
    {
        return match (true) {
            $missing === ['cost', 'sell'] => 'No cost or sell price',
            $missing === ['cost'] => 'No cost',
            $missing === ['sell'] => 'No sell price',
            default => '',
        };
    }
}
