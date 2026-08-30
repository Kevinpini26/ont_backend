<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 24px; border-bottom: 2px solid #184b85; padding-bottom: 10px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; text-decoration: underline; font-size: 15px; margin: 24px 0; text-transform: uppercase; }
        .chiffres-cles { width: 100%; margin-bottom: 24px; border-collapse: collapse; }
        .chiffres-cles td { padding: 8px 10px; border: 1px solid #999; text-align: center; }
        .chiffres-cles .valeur { font-size: 18px; font-weight: bold; color: #184b85; }
        .chiffres-cles .libelle { font-size: 10px; color: #555; }
        h3 { font-size: 12px; text-transform: uppercase; margin: 18px 0 6px; }
        table.tableau { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.tableau td, table.tableau th { padding: 5px 8px; border: 1px solid #999; text-align: left; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    <div class="titre">Rapport annuel consolidé — Exercice {{ $annee }}</div>

    <table class="chiffres-cles">
        <tr>
            <td>
                <div class="valeur">{{ $dossiersRecus }}</div>
                <div class="libelle">Dossiers reçus</div>
            </td>
            <td>
                <div class="valeur">{{ $stagesClotures }}</div>
                <div class="libelle">Stages clôturés</div>
            </td>
            <td>
                <div class="valeur">{{ $noteMoyenne !== null ? $noteMoyenne.' / 100' : 'N/A' }}</div>
                <div class="libelle">Note moyenne</div>
            </td>
        </tr>
    </table>

    <h3>Répartition par type de stage (stages clôturés dans l'année)</h3>
    <table class="tableau">
        <tr><th>Type de stage</th><th>Total</th></tr>
        @foreach($parTypeStage as $ligne)
            <tr><td>{{ $ligne['label'] }}</td><td>{{ $ligne['total'] }}</td></tr>
        @endforeach
    </table>

    <h3>Répartition par direction d'accueil (stages clôturés dans l'année)</h3>
    <table class="tableau">
        <tr><th>Direction</th><th>Stages clôturés</th><th>Note moyenne</th></tr>
        @forelse($parDirection as $ligne)
            <tr>
                <td>{{ $ligne->direction_nom }}</td>
                <td>{{ $ligne->total }}</td>
                <td>{{ $ligne->moyenne !== null ? round($ligne->moyenne, 1).' / 100' : 'N/A' }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Aucun stage clôturé sur cette période.</td></tr>
        @endforelse
    </table>

    <p style="margin-top: 24px; font-style: italic;">
        Document généré automatiquement le {{ now()->translatedFormat('d F Y') }} par la Direction de la
        Formation et de la Professionnalisation, à l'attention de la tutelle.
    </p>
</body>
</html>
