<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
        .badge {
            width: 340px; height: 214px; border: 2px solid #184b85; border-radius: 10px;
            padding: 14px; box-sizing: border-box;
        }
        .entete-badge { text-align: center; border-bottom: 1px solid #184b85; padding-bottom: 6px; margin-bottom: 10px; }
        .entete-badge h1 { font-size: 12px; margin: 0; text-transform: uppercase; color: #184b85; }
        .entete-badge h2 { font-size: 10px; font-weight: normal; margin: 2px 0 0; }
        .corps-badge { width: 100%; }
        .corps-badge td { vertical-align: top; }
        .photo { width: 70px; height: 90px; border: 1px solid #999; object-fit: cover; }
        .photo-absente { width: 70px; height: 90px; border: 1px solid #999; text-align: center; font-size: 9px; color: #777; }
        .identite { padding-left: 12px; }
        .nom { font-size: 14px; font-weight: bold; margin: 0 0 4px; }
        .champ { margin: 2px 0; }
        .libelle { color: #555; }
    </style>
</head>
<body>
    <div class="badge">
        <div class="entete-badge">
            <h1>Office National du Tourisme</h1>
            <h2>Badge de stagiaire</h2>
        </div>
        <table class="corps-badge">
            <tr>
                <td style="width: 70px;">
                    @if($photoDataUri)
                        <img class="photo" src="{{ $photoDataUri }}" alt="Photo">
                    @else
                        <div class="photo-absente">Photo non fournie</div>
                    @endif
                </td>
                <td class="identite">
                    <p class="nom">{{ $stagiaire->nom }}</p>
                    <p class="champ"><span class="libelle">Matricule :</span> {{ $stagiaire->matricule }}</p>
                    <p class="champ"><span class="libelle">Direction :</span> {{ $stagiaire->direction?->nom }}</p>
                    <p class="champ">
                        <span class="libelle">Période :</span>
                        {{ optional($stagiaire->date_debut_stage)->translatedFormat('d/m/Y') }}
                        – {{ optional($stagiaire->date_fin_stage)->translatedFormat('d/m/Y') }}
                    </p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
