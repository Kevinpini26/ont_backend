<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 30px; border-bottom: 2px solid #184b85; padding-bottom: 12px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; text-decoration: underline; font-size: 15px; margin: 30px 0; text-transform: uppercase; }
        .corps { line-height: 1.8; text-align: justify; }
        .tableau { margin-top: 30px; width: 100%; border-collapse: collapse; }
        .tableau td { padding: 6px 10px; border: 1px solid #999; }
        .signature { margin-top: 60px; text-align: right; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    <div class="titre">Note d'affectation</div>

    <div class="corps">
        <p>
            La Direction de la Formation et de la Professionnalisation informe la direction
            {{ $stagiaire->direction?->nom }} de l'affectation du stagiaire
            <strong>{{ $stagiaire->nom }}</strong> (matricule {{ $stagiaire->matricule }}),
            de l'établissement {{ $stagiaire->etablissement_origine }}, à compter de ce jour.
        </p>
    </div>

    <table class="tableau">
        <tr>
            <td>Matricule</td>
            <td>{{ $stagiaire->matricule }}</td>
        </tr>
        <tr>
            <td>Direction d'accueil</td>
            <td>{{ $stagiaire->direction?->nom }}</td>
        </tr>
        <tr>
            <td>Type de stage</td>
            <td>{{ $stagiaire->type_stage?->label() }}</td>
        </tr>
        <tr>
            <td>Date d'affectation</td>
            <td>{{ optional($stagiaire->affecte_at)->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td>Référence du dossier</td>
            <td>{{ $stagiaire->reference_courrier }}</td>
        </tr>
    </table>

    <div class="signature">
        <p>Fait à Kinshasa, le {{ now()->translatedFormat('d F Y') }}</p>
        <p>La Direction de la Formation et de la Professionnalisation</p>
    </div>
</body>
</html>
