<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Collection;
use Modules\Courrier\Contracts\FeuilleCouvertureGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Contracts\QrCodeService;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * picqer/php-barcode-generator, rendu SVG : comme pour les QR codes (voir
 * EndroidQrCodeService), aucune extension image n'est requise — seul
 * fichier du projet autorisé à référencer cette librairie.
 */
class DompdfFeuilleCouvertureGenerator implements FeuilleCouvertureGenerator
{
    public function __construct(
        private readonly PdfGenerationService $pdf,
        private readonly QrCodeService $qrCode,
    ) {}

    public function generer(Courrier $courrier, ?int $nombrePagesAttendues = null): string
    {
        return $this->pdf->genererDepuisVue('courrier::feuille-couverture', [
            'lignes' => [$this->ligne($courrier, $nombrePagesAttendues)],
        ]);
    }

    public function genererLot(Collection $courriers): string
    {
        return $this->pdf->genererDepuisVue('courrier::feuille-couverture', [
            'lignes' => $courriers->map(fn (Courrier $courrier) => $this->ligne($courrier))->all(),
        ]);
    }

    private function ligne(Courrier $courrier, ?int $nombrePagesAttendues = null): array
    {
        return [
            'courrier' => $courrier,
            'nombre_pages_attendues' => $nombrePagesAttendues,
            'code_barres_data_uri' => $this->genererCodeBarresDataUri($courrier->numero_accuse_reception),
            // Numéro seul (déjà imprimé en clair juste au-dessus) : le nom
            // du déposant reste exigé en second facteur sur la page de
            // vérification elle-même (voir DossierPublicController), le QR
            // ne contourne donc pas cette protection.
            'qr_verification_data_uri' => $this->qrCode->genererSvgDataUri(
                rtrim(config('app.frontend_url'), '/')."/verification-dossier?numero={$courrier->numero_accuse_reception}",
                120,
            ),
        ];
    }

    private function genererCodeBarresDataUri(string $numero): string
    {
        $svg = (new BarcodeGeneratorSVG)->getBarcode($numero, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 50);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
