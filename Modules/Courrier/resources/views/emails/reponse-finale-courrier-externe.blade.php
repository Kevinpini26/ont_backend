<x-mail::message>
# Office National du Tourisme

Bonjour {{ $courrierOrigine->expediteur_externe_nom }},

L’Office National du Tourisme vous informe qu’une réponse officielle a été apportée à votre courrier.

**Objet initial :** {{ $courrierOrigine->objet }}<br>
**Référence de suivi :** {{ $courrierOrigine->numero_accuse_reception }}<br>
**Référence de la réponse :** {{ $reponse->reference_documentaire ?? $reponse->numero_depart }}<br>
@if ($reponse->numero_depart)
**Numéro de départ :** {{ $reponse->numero_depart }}<br>
@endif
**Date :** {{ $reponse->date_envoi?->format('d/m/Y') ?? $reponse->signe_at?->format('d/m/Y') }}

<x-mail::button :url="$urlTelechargement">
Consulter / Télécharger la réponse
</x-mail::button>

Pour des raisons de sécurité, ce lien est temporaire.

Cordialement,<br>
**Office National du Tourisme**<br>
République Démocratique du Congo
</x-mail::message>
