<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 24mm 22mm; }
        body { font-family: "DejaVu Sans", serif; font-size: 11pt; color: #111; line-height: 1.45; }
        .entete { width: 100%; border-collapse: collapse; margin-bottom: 12mm; }
        .entete td { vertical-align: top; }
        .identite { width: 62%; }
        .republique, .institution { font-size: 10pt; font-weight: bold; line-height: 1.25; }
        .logo { display: block; width: 92px; height: 92px; margin: 4mm 0 2mm; }
        .direction { margin: 0; font-size: 10.5pt; font-weight: bold; }
        .reference { width: 38%; padding-top: 4mm; text-align: right; font-size: 10pt; font-weight: bold; color: #1e4e8b; }
        .objet { margin: 0 0 6mm; font-size: 11pt; }
        .objet-valeur { font-weight: bold; text-decoration: underline; }
        .corps { font-size: 11pt; line-height: 1.55; text-align: justify; white-space: pre-wrap; }
        .corps p { margin: 0 0 4mm; }
        .corps h2 { margin: 6mm 0 3mm; }
        .corps ul { margin: 0 0 4mm; padding-left: 7mm; }
        .zone-signature { min-height: 48mm; margin: 22mm 0 0 auto; width: 52%; page-break-inside: avoid; }
        .zone-signature-notice { margin: 0 0 8mm; text-align: center; font-size: 9pt; font-weight: bold; }
        .zone-signature-label { margin: 0; text-align: center; font-size: 9pt; }
        .pied-page { position: fixed; bottom: -14mm; left: 0; width: 100%; border-top: .5px solid #555; padding-top: 2mm; color: #333; font-size: 8pt; text-align: center; }
    </style>
</head>
<body>
    <table class="entete">
        <tr>
            <td class="identite">
                <div class="republique">REPUBLIQUE DEMOCRATIQUE DU CONGO</div>
                <div class="institution">OFFICE NATIONAL DU TOURISME</div>
                <img class="logo" src="{{ $logoOntDataUri }}" alt="Logo ONT">
                <p class="direction">Direction productrice : {{ $direction }}</p>
            </td>
            <td class="reference">Référence : {{ $reference }}</td>
        </tr>
    </table>

    <p class="objet"><span>Objet :</span> <span class="objet-valeur">{{ $objet }}</span></p>

    <div class="corps">
        {!! $corpsHtml !!}
    </div>

    <div class="zone-signature">
        <p class="zone-signature-notice">DOCUMENT À SIGNER PHYSIQUEMENT — NON SIGNÉ</p>
        <p class="zone-signature-label">Zone réservée à la signature physique du Directeur et au cachet officiel</p>
    </div>

    <div class="pied-page">OFFICE NATIONAL DU TOURISME</div>
</body>
</html>
