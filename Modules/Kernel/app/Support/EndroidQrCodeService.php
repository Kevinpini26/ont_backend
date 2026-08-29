<?php

namespace Modules\Kernel\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Modules\Kernel\Contracts\QrCodeService;

/**
 * Implémentation actuelle : endroid/qr-code (paquet Composer
 * `endroid/qr-code`). SVG plutôt que PNG : le serveur ne dispose ni de GD
 * ni d'Imagick, et dompdf sait embarquer une image SVG encodée en data URI.
 * Seul fichier du projet autorisé à référencer cette librairie — un
 * remplacement futur se limite à réécrire cette classe et son binding dans
 * KernelServiceProvider, sans toucher aux appelants.
 */
class EndroidQrCodeService implements QrCodeService
{
    public function genererSvgDataUri(string $donnees, int $taille = 180, int $marge = 6): string
    {
        $resultat = (new Builder(
            writer: new SvgWriter,
            data: $donnees,
            size: $taille,
            margin: $marge,
        ))->build();

        return 'data:image/svg+xml;base64,'.base64_encode($resultat->getString());
    }
}
