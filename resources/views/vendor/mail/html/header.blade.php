@props(['url'])
{{-- Company logo (Company settings, else the bundled Local Selection logo), served by the public branding.logo route. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ \App\Support\Branding::logoUrl() }}" class="logo" alt="{{ trim($slot) }}" style="height: 75px; max-height: 75px; width: auto;">
</a>
</td>
</tr>
