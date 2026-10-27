<html><head><style>
    body { font-family: dejavusans; font-size: 10pt; color: #111; }
    h1 { font-size: 16pt; margin: 0 0 4pt; }
    .muted { color: #555; }
    table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    th, td { border-bottom: 0.5pt solid #bbb; padding: 4pt 3pt; text-align: left; vertical-align: top; }
    th { font-size: 8.5pt; text-transform: uppercase; color: #555; }
    .num { text-align: right; }
    .box { border: 0.8pt solid #111; padding: 6pt; }
    .chilled { font-size: 8pt; border: 0.6pt solid #06c; color: #06c; padding: 0 3pt; }
</style></head><body>
<table style="margin-top:0"><tr>
    <td style="border:0; width:60%">
        <h1>Packing slip {{ $order->number }}</h1>
        <div class="muted">{{ $company['company.name'] ?? config('app.name') }}</div>
    </td>
    <td style="border:0" class="box">
        <strong>Run:</strong> {{ $order->deliveryRun?->label() ?? 'Unassigned' }}<br>
        <strong>Cartons:</strong> {{ $pack?->cartons ?? '—' }}<br>
        <strong>Packed by:</strong> {{ $pack?->packer?->name ?? '—' }} {{ $pack?->packed_at?->setTimezone(config('bwms.business_timezone'))->format('j M H:i') }}
    </td>
</tr></table>

<p><strong>Deliver to:</strong> {{ $order->customer?->name ?? '—' }}<br>
    {{ $order->delivery_street ?? $order->customer?->delivery_street }}
    {{ $order->delivery_suburb ?? $order->customer?->delivery_suburb }} {{ $order->delivery_postcode ?? $order->customer?->delivery_postcode }}
    @if ($order->delivery_instructions)<br><span class="muted">{{ $order->delivery_instructions }}</span>@endif
    @if ($order->external_ref)<br><span class="muted">Ref {{ $order->external_ref }} ({{ $order->channel->value }})</span>@endif
</p>

<table>
    <thead><tr><th>SKU</th><th>Product</th><th>Pack</th><th class="num">Ordered</th><th class="num">Packed</th><th>Note</th></tr></thead>
    <tbody>
    @foreach ($rows as $r)
        <tr>
            <td>{{ $r['sku'] }}</td>
            <td>{{ $r['name'] }} @if ($r['chilled'])<span class="chilled">CHILLED</span>@endif</td>
            <td>{{ $r['pack'] }}</td>
            <td class="num">{{ $r['ordered'] }}</td>
            <td class="num">{{ $r['packed'] }}</td>
            <td>{{ $r['short'] ? 'Short '.$r['short'] : '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body></html>
