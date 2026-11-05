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
        .titre { text-align: center; text-decoration: underline; font-size: 15px; margin: 20px 0; text-transform: uppercase; }
        .numero { text-align: center; font-size: 22px; font-weight: bold; letter-spacing: 2px; margin: 10px 0; }
        table.tableau { width: 100%; border-collapse: collapse; margin: 0 auto; max-width: 420px; }
        table.tableau td { padding: 8px 12px; border: 1px solid #999; }
        table.tableau td:first-child { font-weight: bold; width: 40%; background: #eef2f7; }
        .mention { text-align: center; margin-top: 30px; font-style: italic; font-size: 10px; color: #555; }
        .contact { text-align: center; margin-top: 20px; font-size: 10px; color: #555; }
    </style>
</head>
<body>
    @include('courrier::partials.entete')

    <div class="titre">Accusé de réception</div>

    <div class="numero">{{ $courrier->numero_enregistrement }}</div>

    <table class="tableau">
        <tr>
            <td>Objet</td>
            <td>@can('consulterContenu', $courrier){{ $courrier->objet }}@else Objet confidentiel @endcan</td>
        </tr>
        <tr>
            <td>Date</td>
            <td>{{ $courrier->created_at->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td>{{ $nomDeposant['libelle'] }}</td>
            <td>{{ $nomDeposant['valeur'] }}</td>
        </tr>
    </table>

    <p class="mention">
        Ce document atteste la réception de votre dossier par l'Office
        National du Tourisme. Il ne constitue ni son enregistrement
        administratif définitif ni une réponse officielle.
    </p>

    <p class="contact">
        {{ \Modules\Kernel\Models\Setting::valeur('organisation.contact_email', config('ont.contact.email')) }} — {{ \Modules\Kernel\Models\Setting::valeur('organisation.contact_telephone', config('ont.contact.telephone')) }}<br>
        {{ \Modules\Kernel\Models\Setting::valeur('organisation.adresse', config('ont.contact.adresse')) }}
    </p>
</body>
</html>
