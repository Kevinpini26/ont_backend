<x-mail::message>
# Nouveau courrier reçu

Un nouveau courrier a été adressé à votre direction.

<x-mail::panel>
**Objet :** {{ $courrier->objet }}<br>
**Référence :** {{ $courrier->numero_accuse_reception ?? $courrier->numero_enregistrement }}<br>
**Type :** {{ $courrier->type?->label() }}
</x-mail::panel>

<x-mail::button :url="config('app.frontend_url').'/direction/courriers'">
Consulter mes courriers
</x-mail::button>

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
