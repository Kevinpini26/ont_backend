<?php

namespace Modules\Courrier\Tests\Unit;

use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Policies\DocumentProduitDirectionPolicy;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use PHPUnit\Framework\TestCase;

class DocumentProduitDirectionTransmissionPolicyTest extends TestCase
{
    public function test_seul_le_secretariat_producteur_peut_transmettre_un_autonome_scanne_une_fois(): void
    {
        $policy = new DocumentProduitDirectionPolicy;
        $document = $this->document(DocumentProduitStatut::SCAN_ENREGISTRE);

        self::assertTrue($policy->transmettreReception($this->secretariat(3), $document));
        self::assertFalse($policy->transmettreReception($this->secretariat(4), $document));
        self::assertFalse($policy->transmettreReception($this->reception(), $document));

        $sansScan = $this->document(DocumentProduitStatut::SCAN_ENREGISTRE, ['scan_officiel_document_id' => null]);
        self::assertFalse($policy->transmettreReception($this->secretariat(3), $sansScan));

        $transmis = $this->document(DocumentProduitStatut::TRANSMIS_RECEPTION, [
            'transmis_reception_par_id' => 10,
            'transmis_reception_at' => '2026-10-13T12:00:00+00:00',
        ]);
        self::assertFalse($policy->transmettreReception($this->secretariat(3), $transmis));
    }

    public function test_la_reception_ne_peut_lire_le_scan_qu_apres_transmission_explicite(): void
    {
        $policy = new DocumentProduitDirectionPolicy;
        $reception = $this->reception();
        $pretransmission = $this->document(DocumentProduitStatut::SCAN_ENREGISTRE);
        self::assertFalse($policy->consulterScanOfficiel($reception, $pretransmission));

        $transmis = $this->document(DocumentProduitStatut::TRANSMIS_RECEPTION, [
            'transmis_reception_par_id' => 10,
            'transmis_reception_at' => '2026-10-13T12:00:00+00:00',
        ]);
        self::assertTrue($policy->consulterScanOfficiel($reception, $transmis));
        self::assertTrue($policy->consulterScanOfficiel($this->secretariat(3), $transmis));

        $sansPreuveTransmission = $this->document(DocumentProduitStatut::TRANSMIS_RECEPTION);
        self::assertFalse($policy->consulterScanOfficiel($reception, $sansPreuveTransmission));

        $recu = $this->document(DocumentProduitStatut::TRANSMIS_RECEPTION, [
            'transmis_reception_par_id' => 10,
            'transmis_reception_at' => '2026-10-13T12:00:00+00:00',
            'recu_par_id' => 30,
        ]);
        self::assertFalse($policy->consulterScanOfficiel($reception, $recu));
    }

    public function test_la_file_historique_et_la_reception_ne_peuvent_pas_ouvrir_un_autonome_pretransmis(): void
    {
        $policy = new DocumentProduitDirectionPolicy;
        $reception = $this->reception();

        self::assertFalse($policy->view($reception, $this->document(DocumentProduitStatut::SCAN_ENREGISTRE)));
        self::assertFalse($policy->recevoir($reception, $this->document(DocumentProduitStatut::TRANSMIS_RECEPTION)));
    }

    private function document(DocumentProduitStatut $statut, array $overrides = []): DocumentProduitDirection
    {
        $document = new DocumentProduitDirection;
        $document->setDateFormat('Y-m-d H:i:s');
        $document->forceFill(array_merge([
            'origine' => DocumentProduitOrigine::AUTONOME,
            'statut' => $statut,
            'direction_id' => 3,
            'directeur_id' => 12,
            'soumis_par_id' => 11,
            'soumis_at' => '2026-10-13T10:00:00+00:00',
            'scan_officiel_document_id' => 44,
        ], $overrides));

        return $document;
    }

    private function secretariat(int $directionId): User
    {
        $user = new User;
        $user->forceFill(['role' => UserRole::SECRETARIAT_DIRECTION, 'direction_id' => $directionId]);

        return $user;
    }

    private function reception(): User
    {
        $user = new User;
        $user->forceFill([
            'role' => UserRole::AGENT_CIRCUIT_COURRIER,
            'poste' => Poste::RECEPTION,
            'direction_id' => null,
        ]);

        return $user;
    }
}
