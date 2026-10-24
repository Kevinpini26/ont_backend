<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p style="margin: 8px 0 0;">
{{ \Modules\Kernel\Models\Setting::valeur('organisation.contact_email', config('ont.contact.email')) }} — {{ \Modules\Kernel\Models\Setting::valeur('organisation.contact_telephone', config('ont.contact.telephone')) }}<br>
{{ \Modules\Kernel\Models\Setting::valeur('organisation.adresse', config('ont.contact.adresse')) }}
</p>
<p style="margin: 8px 0 0; font-size: 11px;">
Ce message est généré automatiquement par le système d'information de
l'Office National du Tourisme (République Démocratique du Congo). Ne
répondez pas directement à cet email si aucune adresse de réponse n'est
indiquée dans le corps du message.
</p>
</td>
</tr>
</table>
</td>
</tr>
