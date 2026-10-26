{{-- mPDF template. No remote URLs, web fonts or external images (NFR-6). --}}
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #111; }
    h1 { font-size: 18pt; margin: 0 0 2mm 0; }
    .muted { color: #555; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { vertical-align: top; padding: 0 4mm 2mm 0; }
    .lines th { background: #eee; text-align: left; padding: 1.5mm; border-bottom: 0.3mm solid #999; font-size: 8.5pt; }
    .lines td { padding: 1.5mm; border-bottom: 0.2mm solid #ddd; }
    .num { text-align: right; }
    .totals td { padding: 1mm 1.5mm; }
    .box { border: 0.3mm solid #ccc; padding: 2.5mm; }
</style>
</head>
<body>
<table class="meta">
    <tr>
        <td style="width:55%">
            <h1>Purchase order</h1>
            <div style="font-size:12pt"><strong>{{ $po->number }}</strong></div>
            <div class="muted">Order date: {{ $po->order_date->format('d/m/Y') }}</div>
            @if ($po->expected_date)<div class="muted">Required by: {{ $po->expected_date->format('d/m/Y') }}</div>@endif
        </td>
        <td style="width:45%">
            <strong>{{ $company['company.name'] ?? config('app.name') }}</strong><br>
            @if (!empty($company['company.abn']))ABN {{ $company['company.abn'] }}<br>@endif
            {!! nl2br(e($company['company.address'] ?? '')) !!}<br>
            {{ $company['company.phone'] ?? '' }} {{ $company['company.email'] ?? '' }}
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td style="width:50%" class="box">
            <span class="muted">Supplier</span><br>
            <strong>{{ $po->supplier->name }}</strong><br>
            @if ($po->supplier->contact_name)Attn: {{ $po->supplier->contact_name }}<br>@endif
            {!! nl2br(e($po->supplier->address ?? '')) !!}
            @if ($po->supplier->email)<br>{{ $po->supplier->email }}@endif
        </td>
        <td style="width:50%" class="box">
            <span class="muted">Deliver to</span><br>
            <strong>{{ $po->site->name }}</strong><br>
            {!! nl2br(e($company['company.delivery_address'] ?? '')) !!}
        </td>
    </tr>
</table>

<table class="lines" style="margin-top:4mm">
    <thead>
        <tr><th>SKU</th><th>Description</th><th>Pack</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">GST %</th><th class="num">Line (ex GST)</th></tr>
    </thead>
    <tbody>
    @foreach ($po->lines as $line)
        <tr>
            <td>{{ $line->product->sku }}</td>
            <td>{{ $line->product->name }}@if ($line->notes)<br><span class="muted">{{ $line->notes }}</span>@endif</td>
            <td>{{ $line->unit->name }}@if ($line->base_qty_per_unit > 1) ×{{ $line->base_qty_per_unit }}@endif</td>
            <td class="num">{{ $line->qty_ordered }}</td>
            <td class="num">${{ number_format((float) $line->unit_cost, 2) }}</td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $line->tax_rate, 2), '0'), '.') }}</td>
            <td class="num">${{ number_format((float) $line->line_subtotal, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top:3mm">
    <tr><td style="width:70%"></td><td>Subtotal</td><td class="num">${{ number_format((float) $po->subtotal, 2) }}</td></tr>
    <tr><td></td><td>GST</td><td class="num">${{ number_format((float) $po->tax_total, 2) }}</td></tr>
    <tr><td></td><td><strong>Total (AUD)</strong></td><td class="num"><strong>${{ number_format((float) $po->total, 2) }}</strong></td></tr>
</table>

@if ($po->notes)
    <p style="margin-top:5mm"><strong>Notes</strong><br>{!! nl2br(e($po->notes)) !!}</p>
@endif
@if (!empty($company['po.terms']))
    <p class="muted" style="margin-top:5mm; font-size:8pt">{!! nl2br(e($company['po.terms'])) !!}</p>
@endif
</body>
</html>
