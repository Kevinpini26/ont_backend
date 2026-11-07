<x-mail::message>
# Office National du Tourisme

Bonjour {{ $courrierOrigine?->expediteur_externe_nom ?? $reponse->destinataire_externe_nom }},

L’Office National du Tourisme vous informe qu’une réponse officielle a été apportée à votre courrier.

@if ($courrierOrigine)
**Objet initial :** {{ $courrierOrigine->objet }}<br>
**Référence de suivi :** {{ $courrierOrigine->numeroSuivi() }}<br>
@endif
**Référence de la réponse :** {{ $reponse->reference_documentaire ?? $reponse->numero_depart }}<br>
@if ($reponse->numero_depart)
**Numéro de départ :** {{ $reponse->numero_depart }}<br>
@endif
**Date :** {{ $reponse->date_envoi?->format('d/m/Y') ?? $reponse->signe_at?->format('d/m/Y') }}
@if ($reponse->signataire)
**Signataire :** {{ $reponse->signataire->name }}
@endif

<x-mail::button :url="$urlTelechargement">
Consulter / Télécharger la réponse
</x-mail::button>

Pour des raisons de sécurité, ce lien est temporaire. Le PDF officiel est
également joint à cet email lorsque sa taille le permet.

Cordialement,<br>
**Office National du Tourisme**<br>
République Démocratique du Congo
</x-mail::message>
