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
        .infos { width: 100%; margin-bottom: 18px; }
        .infos td { padding: 3px 0; }
        table.tableau { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.tableau td, table.tableau th { padding: 5px 8px; border: 1px solid #999; text-align: left; }
        table.cartouche { width: 100%; margin-top: 40px; border-collapse: collapse; }
        table.cartouche td { width: 50%; padding: 10px; border: 1px solid #999; vertical-align: top; height: 70px; }
        table.cartouche .role { font-weight: bold; margin-bottom: 30px; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    <div class="titre">Tableau de répartition</div>

    <table class="infos">
        <tr>
            <td><strong>Période couverte :</strong> {{ $tableau->periode_debut->format('d/m/Y') }} au {{ $tableau->periode_fin->format('d/m/Y') }}</td>
            <td><strong>Établi par :</strong> {{ $tableau->direction?->nom }}</td>
        </tr>
        <tr>
            <td><strong>Rédacteur :</strong> {{ $tableau->redacteur?->name }}</td>
            <td><strong>Approuvé le :</strong> {{ optional($tableau->approuve_at)->translatedFormat('d F Y') }}</td>
        </tr>
    </table>

    <table class="tableau">
        <tr>
            <th>Stagiaire</th>
            <th>Direction d'accueil proposée</th>
            <th>Dates proposées</th>
            <th>Encadrant pressenti</th>
            <th>Issue</th>
        </tr>
        @foreach($tableau->lignes as $ligne)
            <tr>
                <td>{{ $ligne->stagiaire?->nom }}</td>
                <td>{{ $ligne->directionAccueilProposee?->nom }}</td>
                <td>{{ $ligne->date_debut_proposee->format('d/m/Y') }} — {{ $ligne->date_fin_proposee->format('d/m/Y') }}</td>
                <td>{{ $ligne->encadrant_pressenti }}</td>
                <td>
                    {{ $ligne->issue_proposee?->label() }}
                    @if($ligne->issue_proposee?->value === 'non_retenu')
                        <br><small>{{ config('stagiaires.motifs_non_retenu')[$ligne->motif_non_retenu] ?? $ligne->motif_non_retenu }}</small>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <table class="cartouche">
        <tr>
            <td>
                <div class="role">Établi par</div>
                {{ $tableau->redacteur?->name }}
            </td>
            <td>
                <div class="role">Approuvé par la Direction Générale</div>
                {{ $tableau->approuvePar?->name }}
            </td>
        </tr>
    </table>
</body>
</html>
