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
        .signature { margin-top: 60px; text-align: right; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    <div class="titre">Certificat de fin de stage</div>

    {{--
        Distinct de l'attestation de stage : ne mentionne jamais la note
        finale ni le détail des évaluations (voir attestation.blade.php et
        StagiairePolicy::voirEvaluationFinale()) — un document que le
        stagiaire peut produire librement (candidature, dossier
        administratif) sans exposer une information confidentielle.
    --}}
    <div class="corps">
        <p>
            L'Office National du Tourisme certifie que <strong>{{ $stagiaire->nom }}</strong>,
            de l'établissement {{ $stagiaire->etablissement_origine }}, a effectué un
            {{ mb_strtolower($stagiaire->type_stage->label()) }}
            au sein de la direction {{ $stagiaire->direction?->nom }}
            du {{ optional($stagiaire->date_debut_stage)->translatedFormat('d F Y') }}
            au {{ optional($stagiaire->date_fin_stage)->translatedFormat('d F Y') }},
            stage mené à son terme.
        </p>
    </div>

    <div class="signature">
        <p>Fait à Kinshasa, le {{ now()->translatedFormat('d F Y') }}</p>
        <p>La Direction de la Formation et de la Professionnalisation</p>
    </div>
</body>
</html>
