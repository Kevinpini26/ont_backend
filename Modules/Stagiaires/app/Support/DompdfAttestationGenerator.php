<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Contracts\QrCodeService;
use Modules\Stagiaires\Contracts\AttestationGenerator;
use Modules\Stagiaires\Models\Stagiaire;

class DompdfAttestationGenerator implements AttestationGenerator
{
    public function __construct(
        private readonly PdfGenerationService $pdf,
        private readonly QrCodeService $qrCode,
    ) {}

    public function generer(Stagiaire $stagiaire): string
    {
        $contenu = $this->pdf->genererDepuisVue('stagiaires::attestation', [
            'stagiaire' => $stagiaire,
            'qrCodeDataUri' => $this->genererQrCodeDataUri($stagiaire),
            'badgeReussiteDataUri' => $this->genererBadgeReussiteDataUri(),
        ]);

        $chemin = "attestations/attestation-stagiaire-{$stagiaire->id}.pdf";

        Storage::disk('local')->put($chemin, $contenu);

        return $chemin;
    }

    private function genererQrCodeDataUri(Stagiaire $stagiaire): ?string
    {
        if (! $stagiaire->token_verification) {
            return null;
        }

        $url = rtrim(config('app.frontend_url'), '/')."/verification-attestation/{$stagiaire->token_verification}";

        return $this->qrCode->genererSvgDataUri($url);
    }

    /**
     * Sceau discret signalant la complétion réussie du parcours
     * d'évaluation — mêmes tons ont-gold que le badge affiché sur le
     * portail (voir BadgeReussite.jsx côté frontend). En data URI comme le
     * QR code ci-dessus : dompdf ne rend pas de manière fiable un <svg>
     * inline dans le HTML de la vue.
     */
    private function genererBadgeReussiteDataUri(): string
    {
        $svg = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 26 26">
                <circle cx="13" cy="13" r="12" fill="#f5a623" stroke="#b06d0b" stroke-width="1"/>
                <polygon points="13,6 15,11 20,11 16,14 17.5,19 13,16 8.5,19 10,14 6,11 11,11" fill="#ffffff"/>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
