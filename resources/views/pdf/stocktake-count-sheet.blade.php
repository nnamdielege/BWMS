<html><head><style>
    body { font-family: dejavusans; font-size: 9pt; color: #111; }
    h1 { font-size: 15pt; margin: 0 0 4pt; }
    .muted { color: #555; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 6pt; }
    table.lines th, table.lines td { border-bottom: 0.5pt solid #bbb; padding: 5pt 3pt; text-align: left; vertical-align: top; }
    table.lines th { font-size: 7.5pt; text-transform: uppercase; color: #555; }
    table.lines td.box { border: 0.8pt solid #111; width: 16mm; }
    table.lines td.note { border: 0.8pt solid #111; width: 45mm; }
    .footer td { border: 0; font-size: 9pt; padding-top: 4pt; }
</style></head><body>

<htmlpagefooter name="signature">
    <table class="footer" width="100%"><tr>
        <td>Counted by: ______________________</td>
        <td>Signature: ______________________</td>
        <td>Date: ______________</td>
        <td style="text-align:right" class="muted">{{ $stocktake->number }} · page {PAGENO} of {nbpg}</td>
    </tr></table>
</htmlpagefooter>
<sethtmlpagefooter name="signature" value="on" />

<table width="100%"><tr>
    <td style="width:14%"><img src="{{ $logoPath }}" style="height:14mm" alt=""></td>
    <td>
        <h1>Count sheet {{ $stocktake->number }}</h1>
        <div class="muted">
            @foreach ($header as [$label, $value])
                @continue($label === 'Stocktake')
                <strong>{{ $label }}:</strong> {{ $value }}@if (! $loop->last) &nbsp;·&nbsp; @endif
            @endforeach
        </div>
    </td>
</tr></table>

<table class="lines">
    <thead><tr>
        <th>Bin</th><th>SKU</th><th>Product</th><th>Range</th><th>Batch lot</th><th>Best-before</th><th>Pack</th>
        <th>Counted (packs)</th><th>Counted (each)</th><th>Note</th>
    </tr></thead>
    <tbody>
    @foreach ($rows as $r)
        <tr>
            <td>{{ $r['bin'] }}</td>
            <td>{{ $r['sku'] }}</td>
            <td>{{ $r['name'] }}</td>
            <td>{{ $r['range'] }}</td>
            <td>{{ $r['lot'] }}</td>
            <td>{{ $r['best_before'] }}</td>
            <td>{{ $r['pack'] }}</td>
            <td class="box"></td>
            <td class="box"></td>
            <td class="note"></td>
        </tr>
    @endforeach
    </tbody>
</table>
@if ($rows->isEmpty())<p class="muted">No lines.</p>@endif
</body></html>
