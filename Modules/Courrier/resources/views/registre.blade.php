<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 16px; border-bottom: 2px solid #184b85; padding-bottom: 10px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; margin: 0 0 4px; font-size: 14px; text-transform: uppercase; }
        .periode { text-align: center; margin: 0 0 16px; color: #444; }
        table.registre { width: 100%; border-collapse: collapse; }
        table.registre th, table.registre td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
        table.registre th { background: #eef2f7; font-size: 9px; text-transform: uppercase; }
        tr.rupture td { border-top: 2px solid #b91c1c; }
        .mention-rupture { color: #b91c1c; font-weight: bold; font-size: 9px; }
        .totaux { margin-top: 12px; font-weight: bold; }
        .cartouche { margin-top: 50px; width: 100%; }
        .cartouche td { width: 50%; vertical-align: top; padding-top: 30px; border-top: 1px solid #444; }
        .vide { text-align: center; padding: 24px; color: #666; }
    </style>
</head>
<body>
    @include('courrier::partials.entete')

    <p class="titre">Registre {{ $type === 'depart' ? 'départ' : 'arrivée' }} du courrier</p>
    <p class="periode">Du {{ $debut->translatedFormat('d F Y') }} au {{ $fin->translatedFormat('d F Y') }}</p>

    @if ($courriers->isEmpty())
        <p class="vide">Aucun courrier {{ $type === 'depart' ? 'sortant enregistré' : 'enregistré' }} sur cette période.</p>
    @else
        <table class="registre">
            <thead>
                <tr>
                    <th>N°</th>
                    <th>Date</th>
                    <th>{{ $type === 'depart' ? 'Destinataire' : 'Expéditeur' }}</th>
                    <th>Objet</th>
                    <th>Direction imputée</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($courriers as $courrier)
                    <tr class="{{ in_array($courrier->id, $ruptures, true) ? 'rupture' : '' }}">
                        <td>
                            {{ $type === 'depart' ? $courrier->numero_depart : $courrier->numero_accuse_reception }}
                            @if (in_array($courrier->id, $ruptures, true))
                                <br><span class="mention-rupture">RUPTURE DE SÉQUENCE</span>
                            @endif
                        </td>
                        <td>{{ $courrier->created_at->format('d/m/Y') }}</td>
                        <td>{{ $courrier->expediteur_externe_nom ?? $courrier->candidat_nom ?? $courrier->directionOrigine?->nom ?? '—' }}</td>
                        <td>{{ $courrier->objet }}</td>
                        <td>{{ $courrier->imputations->firstWhere('est_principale', true)?->direction?->code ?? $courrier->directionDestination?->code ?? '—' }}</td>
                        <td>{{ $courrier->statut?->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="totaux">Total : {{ $total }} courrier(s){{ count($ruptures) > 0 ? ' — '.count($ruptures).' rupture(s) de séquence détectée(s)' : '' }}</p>
    @endif

    <table class="cartouche">
        <tr>
            <td>Établi par</td>
            <td>Vu et certifié par le Secrétaire Général</td>
        </tr>
    </table>
</body>
</html>
