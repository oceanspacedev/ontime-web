@props(['url'])
{{-- Nama aplikasi di atas kartu, sama seperti brand teks pada halaman login OnTime. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{ trim($slot) }}
</a>
</td>
</tr>
