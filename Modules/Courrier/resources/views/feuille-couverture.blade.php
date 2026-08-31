<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        .page { page-break-after: always; padding-top: 10px; }
        .page:last-child { page-break-after: avoid; }
        .entete-tableau { width: 100%; margin-bottom: 24px; border-bottom: 2px solid #184b85; padding-bottom: 10px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; text-decoration: underline; font-size: 15px; margin: 20px 0; text-transform: uppercase; }
        .numero { text-align: center; font-size: 22px; font-weight: bold; letter-spacing: 2px; margin: 10px 0; }
        .code-barres { text-align: center; margin: 16px 0 24px; }
        table.tableau { width: 100%; border-collapse: collapse; margin: 0 auto; max-width: 420px; }
        table.tableau td { padding: 8px 12px; border: 1px solid #999; }
        table.tableau td:first-child { font-weight: bold; width: 40%; background: #eef2f7; }
        .mention { text-align: center; margin-top: 30px; font-style: italic; font-size: 10px; color: #555; }
    </style>
</head>
<body>
    @foreach($lignes as $ligne)
        <div class="page">
            @include('courrier::partials.entete')

            <div class="titre">Feuille de couverture</div>

            <div class="numero">{{ $ligne['courrier']->numero_accuse_reception }}</div>

            <div class="code-barres">
                <img src="{{ $ligne['code_barres_data_uri'] }}" alt="Code-barres">
            </div>

            <table class="tableau">
                <tr>
                    <td>Objet</td>
                    <td>{{ $ligne['courrier']->objet }}</td>
                </tr>
                <tr>
                    <td>Date</td>
                    <td>{{ $ligne['courrier']->created_at->translatedFormat('d F Y') }}</td>
                </tr>
                <tr>
                    <td>Expéditeur</td>
                    <td>
                        {{ $ligne['courrier']->expediteur_externe_nom
                            ?? $ligne['courrier']->candidat_nom
                            ?? $ligne['courrier']->directionOrigine?->nom
                            ?? '—' }}
                    </td>
                </tr>
                <tr>
                    <td>Pages attendues</td>
                    <td>{{ $ligne['nombre_pages_attendues'] ?? '—' }}</td>
                </tr>
            </table>

            <p class="mention">
                À poser sur le dessus de la liasse avant numérisation — le code-barres permet de
                rattacher le scan à ce courrier sans ressaisie.
            </p>

            <div class="code-barres">
                <img src="{{ $ligne['qr_verification_data_uri'] }}" alt="QR de vérification" width="80" height="80">
                <p class="mention" style="margin-top: 4px;">Vérifier ce dossier en ligne</p>
            </div>
        </div>
    @endforeach
</body>
</html>
