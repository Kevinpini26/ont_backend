<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 20px; border-bottom: 2px solid #184b85; padding-bottom: 10px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; text-decoration: underline; font-size: 14px; margin: 20px 0; text-transform: uppercase; }
        table.tableau { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.tableau td { padding: 5px 8px; border: 1px solid #999; }
        h3 { font-size: 11px; text-transform: uppercase; margin: 16px 0 6px; }
        table.historique { width: 100%; border-collapse: collapse; }
        table.historique th, table.historique td { border: 1px solid #999; padding: 4px 6px; font-size: 10px; text-align: left; }
        table.historique th { background: #eef2f7; }
    </style>
</head>
<body>
    @include('courrier::partials.entete')

    <div class="titre">Fiche imprimable — {{ $courrier->numero_accuse_reception }}</div>

    <table class="tableau">
        <tr><td>Objet</td><td>{{ $courrier->objet }}</td></tr>
        <tr><td>Type</td><td>{{ $courrier->type->label() }}</td></tr>
        <tr><td>Statut</td><td>{{ $courrier->statut->label() }}</td></tr>
        <tr><td>Direction d'origine</td><td>{{ $courrier->directionOrigine?->nom ?? '—' }}</td></tr>
        <tr><td>Direction de destination</td><td>{{ $courrier->directionDestination?->nom ?? '—' }}</td></tr>
        <tr><td>Numéro d'enregistrement</td><td>{{ $courrier->numero_enregistrement ?? '—' }}</td></tr>
        <tr><td>Cote de classement</td><td>{{ $courrier->cote_classement ?? '—' }}</td></tr>
        <tr><td>Date du courrier</td><td>{{ optional($courrier->date_courrier)->translatedFormat('d F Y') ?? '—' }}</td></tr>
        <tr><td>Degré d'urgence</td><td>{{ $courrier->degre_urgence?->label() ?? '—' }}</td></tr>
    </table>

    <h3>Historique des transmissions</h3>
    <table class="historique">
        <tr><th>Statut</th><th>Émetteur</th><th>Destinataire</th><th>Date</th><th>Décharge</th></tr>
        @forelse($courrier->transitions as $transition)
            <tr>
                <td>{{ $transition->statut->label() }}</td>
                <td>{{ $transition->auteur?->name ?? '—' }}</td>
                <td>
                    {{ $transition->destinataireUser?->name
                        ?? \Modules\Kernel\Enums\Poste::tryFrom($transition->destinataire_poste ?? '')?->label()
                        ?? '—' }}
                </td>
                <td>{{ $transition->created_at?->translatedFormat('d/m/Y H:i') }}</td>
                <td>
                    @if($transition->accuse_reception_at)
                        Reçu par {{ $transition->accuseReceptionPar?->name }} le {{ $transition->accuse_reception_at->translatedFormat('d/m/Y H:i') }}
                    @else
                        En attente
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5">Aucune transmission enregistrée.</td></tr>
        @endforelse
    </table>

    <p style="margin-top: 24px; font-style: italic; font-size: 9px;">
        Document imprimé le {{ now()->translatedFormat('d F Y à H:i') }} — reflète l'état du dossier à cet instant,
        pas un document officiel définitif (voir le PDF signé une fois le courrier abouti, s'il y a lieu).
    </p>
</body>
</html>
