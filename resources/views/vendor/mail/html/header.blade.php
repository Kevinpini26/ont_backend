@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="data:image/png;base64,{{ base64_encode((string) file_get_contents(base_path('Modules/Courrier/resources/ONT.png'))) }}" class="logo" alt="{{ config('app.name') }}" height="40">
</a>
</td>
</tr>
