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
        ul { margin: 0; padding-left: 18px; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    {{--
        Fiche de suivi de terrain : n'expose jamais le détail des
        évaluations ni le contenu du retour d'expérience (voir
        StagiairePolicy::voirEvaluationFinale()/voirRetour()) — seulement
        l'identité et le déroulement administratif du dossier.
    --}}
    <div class="titre">Fiche imprimable — {{ $stagiaire->matricule ?? $stagiaire->nom }}</div>

    <table class="tableau">
        <tr><td>Nom</td><td>{{ $stagiaire->nom }}</td></tr>
        <tr><td>Matricule</td><td>{{ $stagiaire->matricule ?? '—' }}</td></tr>
        <tr><td>Établissement</td><td>{{ $stagiaire->etablissement_origine }}</td></tr>
        <tr><td>Type de stage</td><td>{{ $stagiaire->type_stage?->label() }}</td></tr>
        <tr><td>Direction d'accueil</td><td>{{ $stagiaire->direction?->nom ?? '—' }}</td></tr>
        <tr><td>Statut</td><td>{{ $stagiaire->statut->label() }}</td></tr>
        <tr>
            <td>Période de stage</td>
            <td>
                {{ optional($stagiaire->date_debut_stage)->translatedFormat('d F Y') ?? '—' }}
                – {{ optional($stagiaire->date_fin_stage)->translatedFormat('d F Y') ?? '—' }}
            </td>
        </tr>
        <tr><td>Maître de stage</td><td>{{ $stagiaire->maitre_stage ?? $stagiaire->maitreStageUtilisateur?->name ?? '—' }}</td></tr>
    </table>

    <h3>Documents du dossier</h3>
    <ul>
        @forelse($stagiaire->documents as $document)
            <li>{{ $document->type->label() }} — {{ $document->nom_original }} ({{ $document->created_at->translatedFormat('d/m/Y') }})</li>
        @empty
            <li>Aucun document déposé.</li>
        @endforelse
    </ul>

    <p style="margin-top: 24px; font-style: italic; font-size: 9px;">
        Document imprimé le {{ now()->translatedFormat('d F Y à H:i') }} — reflète l'état du dossier à cet instant.
    </p>
</body>
</html>
