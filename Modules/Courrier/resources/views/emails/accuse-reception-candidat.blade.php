<x-mail::message>
Bonjour {{ $courrier->candidat_nom }},

Nous avons bien reçu votre lettre de demande de stage à l'Office National du Tourisme.

Voici votre numéro de dossier : **{{ $courrier->numeroSuivi() }}**

Conservez ce numéro, il vous permettra de suivre l'état de votre dossier. Nous reviendrons vers vous dès qu'une décision aura été prise.

@if ($courrier->numeroSuivi())
<x-mail::button :url="config('app.frontend_url').'/suivi-dossier?numero='.urlencode($courrier->numeroSuivi())">
Suivre l'état de mon dossier
</x-mail::button>
@endif

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
