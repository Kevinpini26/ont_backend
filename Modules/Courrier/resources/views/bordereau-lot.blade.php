<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        .entete-tableau { width: 100%; margin-bottom: 16px; border-bottom: 2px solid #184b85; padding-bottom: 10px; }
        .entete-logo { width: 50px; }
        .entete-texte { text-align: center; }
        .entete-texte h1 { font-size: 16px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-texte h2 { font-size: 13px; font-weight: normal; margin: 4px 0 0; }
        .titre { text-align: center; margin: 0 0 4px; font-size: 14px; text-transform: uppercase; }
        .reference { text-align: center; margin: 0 0 16px; color: #444; }
        table.postes { width: 100%; margin-bottom: 16px; border-collapse: collapse; }
        table.postes td { width: 50%; padding: 6px 8px; border: 1px solid #999; vertical-align: top; }
        table.postes .libelle { font-size: 9px; text-transform: uppercase; color: #666; }
        table.dossiers { width: 100%; border-collapse: collapse; }
        table.dossiers th, table.dossiers td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
        table.dossiers th { background: #eef2f7; font-size: 9px; text-transform: uppercase; }
        .totaux { margin-top: 12px; font-weight: bold; }
        .cartouche { margin-top: 50px; width: 100%; }
        .cartouche td { width: 50%; vertical-align: top; padding-top: 30px; border-top: 1px solid #444; }
    </style>
</head>
<body>
    @include('courrier::partials.entete')

    <p class="titre">Bordereau de transmission par lot</p>
    <p class="reference">N° {{ $bordereau->numero }} — {{ $bordereau->created_at->translatedFormat('d F Y à H:i') }}</p>

    <table class="postes">
        <tr>
            <td>
                <div class="libelle">Émetteur</div>
                {{ $bordereau->emetteur?->name ?? '—' }}
            </td>
            <td>
                <div class="libelle">Destinataire</div>
                {{ $bordereau->poste_destinataire?->label() ?? '—' }}
            </td>
        </tr>
    </table>

    <table class="dossiers">
        <thead>
            <tr>
                <th>N°</th>
                <th>Référence</th>
                <th>Objet</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bordereau->courriers as $index => $courrier)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $courrier->numero_accuse_reception ?? $courrier->numero_enregistrement ?? '—' }}</td>
                    <td>{{ $courrier->objet }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="totaux">Total : {{ $bordereau->courriers->count() }} dossier(s)</p>

    <table class="cartouche">
        <tr>
            <td>Remis par</td>
            <td>Reçu par</td>
        </tr>
    </table>
</body>
</html>
