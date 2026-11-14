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
</x-mail::message>
