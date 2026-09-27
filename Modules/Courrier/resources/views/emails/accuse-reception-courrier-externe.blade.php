<x-mail::message>
# Office National du Tourisme

Bonjour {{ $courrier->expediteur_externe_nom }},

Nous vous confirmons la bonne réception de votre courrier adressé à l’Office National du Tourisme.

**Objet de la demande :** {{ $courrier->objet }}<br>
**Référence de suivi :** {{ $courrier->numero_accuse_reception }}<br>
**Date de dépôt :** {{ $courrier->created_at?->format('d/m/Y') }}

Votre demande sera prise en charge conformément au circuit administratif de l’Office National du Tourisme.

<x-mail::button :url="config('app.frontend_url').'/suivi-dossier?numero='.$courrier->numero_accuse_reception">
Suivre ma demande
</x-mail::button>

Cet accusé confirme la réception technique de votre courrier. Il ne constitue ni son enregistrement administratif ni une réponse officielle de l’ONT.

Cordialement,<br>
**Office National du Tourisme**<br>
République Démocratique du Congo
</x-mail::message>
