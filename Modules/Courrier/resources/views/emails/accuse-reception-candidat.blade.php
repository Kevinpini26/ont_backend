<x-mail::message>
Bonjour {{ $courrier->candidat_nom }},

Nous avons bien reçu votre lettre de demande de stage à l'Office National du Tourisme.

Voici votre numéro d'accusé de réception : **{{ $courrier->numero_accuse_reception ?? $courrier->numero_enregistrement }}**

Conservez ce numéro, il vous permettra de suivre l'état de votre dossier. Nous reviendrons vers vous dès qu'une décision aura été prise.

@if ($courrier->numero_accuse_reception)
<x-mail::button :url="config('app.frontend_url').'/verification-dossier?numero='.$courrier->numero_accuse_reception">
Suivre l'état de mon dossier
</x-mail::button>
@endif

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
