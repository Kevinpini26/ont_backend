<x-mail::message>
# Avis en attente depuis plus de 48 heures

Le courrier suivant attend votre avis depuis plus de 48 heures.

<x-mail::panel>
**Objet :** {{ $courrier->objetPourCanalExterne() }}<br>
**Référence :** {{ $courrier->numero_accuse_reception }}
</x-mail::panel>

<x-mail::button :url="config('app.frontend_url').'/courriers/'.$courrier->id">
Traiter ce courrier
</x-mail::button>

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
