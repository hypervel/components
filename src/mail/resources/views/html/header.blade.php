@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Hypervel')
<img src="https://hypervel.org/icon.png" class="logo" alt="Hypervel Logo">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>