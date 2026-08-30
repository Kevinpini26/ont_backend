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
        .tableau { margin-bottom: 24px; width: 100%; border-collapse: collapse; }
        .tableau td { padding: 5px 8px; border: 1px solid #999; }
        h3 { font-size: 12px; text-transform: uppercase; margin: 18px 0 6px; }
        ol { padding-left: 18px; line-height: 1.7; }
        .signatures { margin-top: 40px; width: 100%; }
        .signatures td { width: 33%; vertical-align: top; padding: 8px; }
        .case { display: inline-block; width: 10px; height: 10px; border: 1px solid #333; margin-right: 6px; }
    </style>
</head>
<body>
    @include('stagiaires::partials.entete')

    <div class="titre">Convention de stage tripartite</div>

    <p style="margin-bottom: 18px; line-height: 1.6;">
        Établie entre l'Office National du Tourisme (direction {{ $stagiaire->direction?->nom }}),
        l'établissement de formation ({{ $stagiaire->etablissement_origine }}) et le stagiaire.
    </p>

    <table class="tableau">
        <tr>
            <td>Stagiaire</td>
            <td>{{ $stagiaire->nom }}</td>
        </tr>
        <tr>
            <td>Établissement d'origine</td>
            <td>
                {{ $stagiaire->etablissement_origine }}
                @if($stagiaire->etablissement?->ville)
                    ({{ $stagiaire->etablissement->ville }})
                @endif
            </td>
        </tr>
        <tr>
            <td>Direction d'accueil</td>
            <td>{{ $stagiaire->direction?->nom }}</td>
        </tr>
        <tr>
            <td>Période de stage</td>
            <td>
                Du {{ optional($stagiaire->date_debut_stage)->translatedFormat('d F Y') }}
                au {{ optional($stagiaire->date_fin_stage)->translatedFormat('d F Y') }}
            </td>
        </tr>
        <tr>
            <td>Référence du dossier</td>
            <td>{{ $stagiaire->reference_courrier }}</td>
        </tr>
    </table>

    <h3>Obligations du stagiaire</h3>
    <ol>
        <li>Respecter les horaires et le règlement intérieur de la direction d'accueil.</li>
        <li>Exécuter les missions confiées avec assiduité et sous l'encadrement désigné.</li>
        <li>Observer une stricte confidentialité sur les informations et documents internes dont il aurait connaissance durant le stage.</li>
        <li>Informer sans délai la direction d'accueil et la DFP de toute absence ou empêchement.</li>
    </ol>

    <h3>Obligations de l'Office National du Tourisme</h3>
    <ol>
        <li>Désigner un encadrant au sein de la direction d'accueil pour la durée du stage.</li>
        <li>Confier au stagiaire des missions en rapport avec sa formation.</li>
        <li>Délivrer une attestation de stage à l'issue de la période, sous réserve de son évaluation.</li>
    </ol>

    <p style="margin-top: 18px; font-style: italic;">
        Ce document est un modèle standard généré automatiquement ; il n'a pas fait l'objet d'une
        relecture juridique et doit être validé par le service compétent avant tout usage officiel.
    </p>

    <table class="signatures">
        <tr>
            <td>
                <p><span class="case"></span> Pour la direction d'accueil</p>
                @if ($stagiaire->convention_signee_direction_at)
                    <p>Signé par {{ $stagiaire->conventionSigneeDirectionPar?->name }}<br>
                        le {{ $stagiaire->convention_signee_direction_at->translatedFormat('d F Y à H:i') }}</p>
                @else
                    <p>Non signé à ce jour.</p>
                @endif
            </td>
            <td>
                <p><span class="case"></span> Pour le stagiaire</p>
                @if ($stagiaire->convention_signee_stagiaire_at)
                    <p>Signé le {{ $stagiaire->convention_signee_stagiaire_at->translatedFormat('d F Y à H:i') }}</p>
                @else
                    <p>Non signé à ce jour.</p>
                @endif
            </td>
            <td>
                {{--
                    Troisième partie de la convention tripartite : signature
                    manuscrite, hors système — l'établissement de formation
                    n'a pas de compte utilisateur ONT et aucun mécanisme de
                    signature électronique n'existe pour lui à ce jour (voir
                    docs/questions-ont.md).
                --}}
                <p><span class="case"></span> Pour l'établissement de formation</p>
                <p>{{ $stagiaire->etablissement_origine }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
