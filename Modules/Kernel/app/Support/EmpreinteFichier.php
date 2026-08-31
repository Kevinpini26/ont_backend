<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Empreinte SHA-256 des documents PDF officiels (générés ou signés) — preuve
 * technique complémentaire d'intégrité, au sens de l'article 95 de
 * l'ordonnance-loi n°23/010 du 13 mars 2023 portant Code du numérique
 * ("établi et conservé dans des conditions de nature à en garantir
 * l'intégrité"). Ne remplace pas une signature électronique qualifiée au
 * sens des articles 104-110 du même texte (le système n'en implémente
 * aucune à ce jour — voir docs/conformite-donnees.md) : elle permet
 * seulement de prouver après coup qu'un fichier n'a pas été altéré depuis
 * sa génération/réception, pas d'authentifier son signataire.
 */
class EmpreinteFichier
{
    public static function pourFichierStocke(string $chemin, string $disque = 'local'): string
    {
        return hash('sha256', Storage::disk($disque)->get($chemin));
    }
}
