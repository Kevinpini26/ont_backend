<x-mail::message>
# Email de test

Cet email confirme que l'envoi d'e-mails fonctionne correctement, de bout en bout (serveur SMTP, file d'attente, worker).

**Envoyé le :** {{ now()->translatedFormat('d F Y à H:i') }}

Vous pouvez ignorer ce message.

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
