<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 20px; border-bottom: 2px solid #184b85; padding-bottom: 10px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .mention { margin: 14px 0; padding: 8px; border: 1px solid #777; text-align: center; font-weight: bold; }
        .references { width: 100%; margin-bottom: 20px; font-size: 11px; color: #333; }
        .references td { padding: 3px 0; }
        .destinataire { margin: 16px 0; }
        .objet { font-weight: bold; margin: 18px 0; }
        .corps { line-height: 1.8; text-align: justify; }
        .corps p { margin: 0 0 10px; }
        .corps h1, .corps h2, .corps h3 { margin: 14px 0 8px; }
        .corps ul, .corps ol { margin: 0 0 10px; padding-left: 20px; }
        .signature { margin: 46px 0 0 auto; width: 48%; text-align: center; }
        .signature .nom { font-weight: bold; }
        .ligne-signature { height: 52px; border-bottom: 1px solid #333; margin: 8px 0; }
        .note { font-size: 10px; color: #444; text-align: center; }
    </style>
    @php
        $fonctionSignataire = match ($sourceAutorite) {
            'interim_dga' => 'Directeur Général Adjoint, intérim de la Direction Générale',
            'delegation' => 'Délégataire de la Direction Générale',
            default => 'Directeur Général',
        };
        $nomSignataire = trim((string) $courrier->valideSignaturePar?->name);
    @endphp
</head>
<body>
    @include('courrier::partials.entete')

    <p class="mention">DOCUMENT À SIGNER — NON SIGNÉ</p>

    <table class="references">
        <tr>
            <td><strong>N/Réf. :</strong> {{ $courrier->numero_depart }}</td>
            <td style="text-align: right;"><strong>Date de validation :</strong> {{ $courrier->valide_signature_at->translatedFormat('d F Y') }}</td>
        </tr>
    </table>

    <div class="destinataire">
        <strong>À :</strong> {{ $courrier->destinataire_externe_nom }}
        @if ($courrier->destinataire_externe_email)
            <br>{{ $courrier->destinataire_externe_email }}
        @endif
    </div>

    <p class="objet">Objet : {{ $courrier->objet }}</p>

    <div class="corps">
        {!! $corpsHtml !!}
    </div>

    <div class="signature">
        @if ($nomSignataire !== '' && $nomSignataire !== $fonctionSignataire)
            <p class="nom">{{ $nomSignataire }}</p>
        @endif
        <p>{{ $fonctionSignataire }}</p>
        <div class="ligne-signature"></div>
        <p>Signature manuscrite et cachet institutionnel</p>
    </div>
    <p class="note">Document préparé pour impression et signature. Aucune signature ni aucun cachet n'y a encore été apposé.</p>
</body>
</html>