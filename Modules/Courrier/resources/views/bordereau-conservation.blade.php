<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $bordereau->numero }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        .titre { text-align: center; font-size: 16px; font-weight: bold; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #555; padding: 4px 6px; text-align: left; }
        .cartouche td { height: 60px; vertical-align: top; width: 50%; }
    </style>
</head>
<body>
    @include('courrier::partials.entete')

    <div class="titre">{{ $bordereau->type === 'versement' ? 'Bordereau de versement aux Archives nationales' : 'Bordereau d’élimination' }}</div>
    <p>Numéro : <strong>{{ $bordereau->numero }}</strong> · Proposé le {{ $bordereau->propose_at?->format('d/m/Y') }} par {{ $bordereau->proposePar?->name }}</p>
    <p>Motif : {{ $bordereau->motif }}</p>

    <table>
        <thead><tr><th>Dossier</th><th>Rubrique du plan de classement</th><th>Fin de conservation</th></tr></thead>
        <tbody>
            @foreach ($bordereau->dossiers as $dossier)
                <tr>
                    <td>#{{ $dossier->id }} {{ $dossier->libelle }}</td>
                    <td>{{ $dossier->pivot->rubrique }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($dossier->pivot->fin_conservation_le)->format('d/m/Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>Aucune donnée n'est supprimée de l'application : ce bordereau trace le sort final des originaux.</p>

    <table class="cartouche">
        <tr>
            <td>Proposé par : {{ $bordereau->proposePar?->name }}</td>
            <td>
                @if ($bordereau->statut === 'valide')
                    Validé le {{ $bordereau->valide_at?->format('d/m/Y') }} par {{ $bordereau->validePar?->name }}
                @else
                    En attente de validation
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
