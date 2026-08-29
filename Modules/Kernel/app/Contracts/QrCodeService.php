<?php

namespace Modules\Kernel\Contracts;

/**
 * Point d'extension : génère un QR code encodant une donnée arbitraire
 * (typiquement une URL de vérification). Aucun code applicatif ne doit
 * appeler une librairie de génération de QR code directement, pour que
 * remplacer la librairie sous-jacente reste localisé à la seule
 * implémentation liée dans Modules\Kernel\Providers\KernelServiceProvider.
 */
interface QrCodeService
{
    /**
     * @return string Data URI SVG (`data:image/svg+xml;base64,...`), prête
     *                à être embarquée dans une vue Blade rendue en PDF.
     */
    public function genererSvgDataUri(string $donnees, int $taille = 180, int $marge = 6): string;
}
