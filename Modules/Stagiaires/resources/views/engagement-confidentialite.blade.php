<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 24px; border-bottom: 2px solid #184b85; padding-bottom: 12px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; text-decoration: underline; font-size: 15px; margin: 24px 0; text-transform: uppercase; }
        .corps { line-height: 1.8; text-align: justify; }
        .signature { margin-top: 60px; text-align: right; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    <div class="titre">Engagement de confidentialité</div>

    <div class="corps">
        <p>
            Je soussigné(e) <strong>{{ $stagiaire->nom }}</strong>, stagiaire au sein de la
            direction {{ $stagiaire->direction?->nom }} de l'Office National du Tourisme,
            m'engage à observer la plus stricte confidentialité sur l'ensemble des
            informations, documents et données auxquels j'aurai accès durant mon stage,
            et à ne pas les divulguer, communiquer ou exploiter à des fins autres que celles
            de mon stage, y compris après la fin de celui-ci.
        </p>
    </div>

    <div class="signature">
        @if($stagiaire->engagement_confidentialite_signe_at)
            <p>Signé électroniquement le {{ $stagiaire->engagement_confidentialite_signe_at->translatedFormat('d F Y à H:i') }}</p>
        @else
            <p>Non signé à ce jour.</p>
        @endif
    </div>
</body>
</html>
