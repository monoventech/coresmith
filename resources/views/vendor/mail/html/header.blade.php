@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('coresmith-lockup-on-light.png') }}" width="160" height="26" alt="{{ trim(strip_tags($slot)) }}" style="display: block; width: 160px; height: auto; border: 0;">
</a>
</td>
</tr>
