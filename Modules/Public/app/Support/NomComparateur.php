<?php

namespace Modules\Public\Support;

use Illuminate\Support\Str;

/**
 * Compare un nom saisi par un candidat externe (guichet, mémoire
 * approximative) à un nom stocké, sans exiger l'ordre ni la casse exacts.
 * Utilisé comme second facteur de vérification publique (dossiers,
 * attestations) : "kabasele" et "jean kabasele" valident tous deux
 * "KABASELE Jean Pierre".
 */
class NomComparateur
{
    private const LONGUEUR_MIN_MOT_SIGNIFICATIF = 3;

    public static function correspond(string $saisie, ?string $nomStocke): bool
    {
        if (! filled($nomStocke) || ! filled($saisie)) {
            return false;
        }

        $motsSaisis = self::tokeniser($saisie);
        $motsStockes = self::tokeniser($nomStocke);

        $auMoinsUnMotSignificatif = collect($motsSaisis)
            ->contains(fn (string $mot) => mb_strlen($mot) >= self::LONGUEUR_MIN_MOT_SIGNIFICATIF);

        if (! $auMoinsUnMotSignificatif) {
            return false;
        }

        foreach ($motsSaisis as $mot) {
            if (! in_array($mot, $motsStockes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private static function tokeniser(string $valeur): array
    {
        $normalise = Str::ascii(mb_strtolower(trim($valeur)));
        $normalise = str_replace(['-', "'"], ' ', $normalise);

        return array_values(array_filter(preg_split('/\s+/', $normalise) ?: []));
    }
}
