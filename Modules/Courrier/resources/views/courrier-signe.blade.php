<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 24mm 22mm; }
        body { font-family: "DejaVu Sans", serif; font-size: 11pt; color: #111; line-height: 1.45; }
        .filigrane { position: fixed; top: 220px; left: 50%; width: 500px; transform: translateX(-50%); z-index: -1; opacity: .12; }
        .filigrane img { width: 100%; height: auto; }
        .entete-institutionnelle { width: 100%; border-collapse: collapse; margin-bottom: 12mm; }
        .entete-institutionnelle td { vertical-align: top; }
        .identite-institutionnelle { width: 62%; padding-top: 2mm; }
        .republique, .nom-institution { font-size: 10pt; font-weight: bold; line-height: 1.25; }
        .logo-entete { display: block; width: 118px; height: 118px; margin: 4mm 0 2mm; }
        .fonction-entete { margin: 0; font-size: 10.5pt; font-weight: bold; font-style: italic; }
        .date-reference { width: 38%; padding-top: 4mm; text-align: right; font-size: 10pt; color: #1e4e8b; }
        .date-reference .date-ligne { margin-bottom: 15mm; }
        .reference { margin-top: 0; font-weight: bold; color: #1e4e8b; }
        .copies-information { margin: 0 0 10mm; font-size: 10pt; }
        .copies-information ul { margin: 2mm 0 0; padding-left: 7mm; }
        .destinataire { width: 56%; margin: 0 0 6mm auto; text-align: left; line-height: 1.5; font-size: 11pt; }
        .objet { margin: 0 0 5mm; font-size: 11pt; }
        .objet-label { font-weight: normal; }
        .objet-valeur { font-weight: bold; text-decoration: underline; }
        .corps { font-size: 11pt; line-height: 1.55; text-align: justify; }
        .corps p { margin: 0 0 4mm; }
        .corps h1, .corps h2, .corps h3 { margin: 6mm 0 3mm; }
        .corps ul, .corps ol { margin: 0 0 4mm; padding-left: 7mm; }
        .signature { margin: 35mm 0 0 auto; width: 44%; text-align: center; line-height: 1.5; }
        .signature p { margin: 0; }
        .signature .nom, .signature .fonction { font-weight: bold; }
        .pied-page { position: fixed; bottom: -14mm; left: 0; width: 100%; border-top: .5px solid #555; padding-top: 2mm; color: #333; font-size: 8pt; text-align: center; }
    </style>
    @php
        $dateOfficielle = $courrier->signe_at?->copy()->locale('fr')->translatedFormat('d F Y') ?? '';
        $referenceNref = filled($courrier->reference_documentaire)
            ? $courrier->reference_documentaire
            : $courrier->numero_depart;
    @endphp
</head>
<body>
    @include('courrier::partials.entete', ['enteteInstitutionnelleLettre' => true, 'logoOntDataUri' => $logoOntDataUri ?? null, 'dateOfficielle' => $dateOfficielle, 'referenceNref' => $referenceNref])

    <div class="destinataire">A {{ $courrier->destinataire_externe_nom }}</div>

    <p class="objet"><span class="objet-label">Objet :</span> <span class="objet-valeur">{{ $courrier->objet }}</span></p>

    <div class="corps">
        {!! $corpsHtml !!}
    </div>

    <div class="signature">
        <p class="nom">{{ $courrier->signataire?->name }}</p>
        <p>{{ $courrier->signataire?->poste?->label() ?? $courrier->signataire?->role?->label() }}</p>
    </div>
    <div class="pied-page">OFFICE NATIONAL DU TOURISME</div>
</body>
</html>
