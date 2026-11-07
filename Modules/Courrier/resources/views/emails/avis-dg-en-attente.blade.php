<x-mail::message>
# Avis en attente au-delà du délai

Le courrier suivant attend votre avis au-delà du délai prévu (compté en heures ouvrées).

<x-mail::panel>
**Objet :** {{ $courrier->objetPourCanalExterne() }}<br>
**Référence :** {{ $courrier->numeroSuivi() }}
</x-mail::panel>

<x-mail::button :url="config('app.frontend_url').'/courriers/'.$courrier->id">
Traiter ce courrier
</x-mail::button>

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
