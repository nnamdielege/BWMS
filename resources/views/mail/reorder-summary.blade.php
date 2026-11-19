<x-mail::message>
# Reorder suggestions, {{ $date }}

**{{ $stockouts }}** {{ $stockouts === 1 ? 'product runs' : 'products run' }} out before the next delivery could arrive.

@if ($suppliers === [])
Nothing needs ordering today.
@else
<x-mail::table>
| Supplier | Lines | Value at cost | Minimum |
|:--|--:|--:|:--|
@foreach ($suppliers as $s)
| {{ $s['supplier'] }} | {{ $s['to_order'] }} | ${{ number_format($s['value'], 2) }} | {{ $s['min'] === null ? '—' : ($s['meets_min'] ? 'met' : 'below $'.number_format($s['min'], 2)) }} |
@endforeach
</x-mail::table>

@foreach ($suppliers as $s)
@if ($s['stockouts'] !== [])
**{{ $s['supplier'] }}** runs out first: {{ implode(', ', $s['stockouts']) }}

@endif
@endforeach
@endif
<x-mail::button :url="$url">
Open reorder suggestions
</x-mail::button>

Nothing has been ordered: drafts are made from the suggestions screen.

@if ($expiring !== null && $expiring['lines'] !== [])
## Expiring soon

{{ count($expiring['lines']) }} {{ count($expiring['lines']) === 1 ? 'batch' : 'batches' }} won't sell before best-before (next {{ $expiring['days'] }} days), about ${{ number_format($expiring['value_left'], 2) }} at cost.

<x-mail::table>
| Product | Batch | Best before | Won't sell |
|:--|:--|:--|--:|
@foreach (array_slice($expiring['lines'], 0, 20) as $l)
| {{ $l['sku'] }} {{ $l['name'] }} | {{ $l['batch'] }} | {{ $l['best_before'] }}{{ $l['expired'] ? ' (past)' : '' }} | {{ $l['left_over'] }} {{ $l['base_unit'] }} |
@endforeach
</x-mail::table>

@if (count($expiring['lines']) > 20)
…and {{ count($expiring['lines']) - 20 }} more.
@endif

<x-mail::button :url="$expiringUrl">
Open expiring soon
</x-mail::button>
@endif
</x-mail::message>
