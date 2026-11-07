@props(['url'])
{{-- Cabeçalho da marca: símbolo (PNG com endereço absoluto: e-mail não aceita SVG) + nome + lema. Sem imagens, o nome em texto continua legível. --}}
<tr>
<td class="header" align="center" bgcolor="#12344D">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
<tr>
<td valign="middle" style="padding-right: 12px;"><img src="{{ rtrim(config('app.url'), '/') }}/brand/bulai-icone-192.png" class="logo" width="44" height="44" alt="" style="display: block; border-radius: 10px;"></td>
<td valign="middle" align="left">
<span class="brand-name" style="display: block; color: #ffffff; font-size: 24px; font-weight: bold;">{{ config('app.name') }}</span>
<span class="brand-tagline" style="display: block; color: #b3c7d7; font-size: 12px; letter-spacing: 1px; text-transform: uppercase;">Bulas inteligentes</span>
</td>
</tr>
</table>
</a>
</td>
</tr>
