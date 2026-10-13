<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Policies\DocumentProduitDirectionPolicy;
use Modules\Courrier\Services\DocumentProduitScanOfficielService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class ConsultationScanOfficielPolicyTest extends TestCase
{
    public function test_policy_autorise_le_secretariat_producteur_et_le_responsable_personnellement_assigne(): void
    {
        $document = $this->document();
        $policy = new DocumentProduitDirectionPolicy;

        $this->assertTrue($policy->consulterScanOfficiel(
            $this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 12),
            $document,
        ));
        $this->assertTrue($policy->consulterScanOfficiel(
            $this->utilisateur(UserRole::DIRECTEUR_DIRECTION, directionId: 12, id: 42),
            $document,
        ));
        $this->assertTrue($policy->consulterScanOfficiel(
            $this->utilisateur(UserRole::RESPONSABLE_DIRECTION, directionId: 12, id: 42),
            $document,
        ));
    }

    public function test_policy_refuse_les_roles_postes_et_affectations_non_autorises(): void
    {
        $document = $this->document();
        $policy = new DocumentProduitDirectionPolicy;

        $utilisateursRefuses = [
            $this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 13),
            $this->utilisateur(UserRole::DIRECTEUR_DIRECTION, directionId: 12, id: 43),
            $this->utilisateur(UserRole::RESPONSABLE_DIRECTION, directionId: 12, id: 43),
            $this->utilisateur(UserRole::AGENT_CIRCUIT_COURRIER, poste: Poste::RECEPTION),
            $this->utilisateur(UserRole::AGENT_CIRCUIT_COURRIER, poste: Poste::DG),
            $this->utilisateur(UserRole::AGENT_CIRCUIT_COURRIER, poste: Poste::DGA),
            $this->utilisateur(UserRole::AGENT_CIRCUIT_COURRIER, poste: Poste::SECRETARIAT_1),
            $this->utilisateur(UserRole::AGENT_CIRCUIT_COURRIER, poste: Poste::SECRETARIAT_2),
            $this->utilisateur(UserRole::ADMINISTRATEUR),
        ];

        foreach ($utilisateursRefuses as $utilisateur) {
            $this->assertFalse($policy->consulterScanOfficiel($utilisateur, $document));
        }

        $documentSansPointeur = $this->document(['scan_officiel_document_id' => null]);
        $this->assertFalse($policy->consulterScanOfficiel(
            $this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 12),
            $documentSansPointeur,
        ));

        $documentEncoreValide = $this->document(['statut' => DocumentProduitStatut::VALIDE->value]);
        $this->assertFalse($policy->consulterScanOfficiel(
            $this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 12),
            $documentEncoreValide,
        ));
    }

    public function test_route_retourne_le_flux_pdf_prive_apres_autorisation(): void
    {
        $document = $this->document();
        Route::bind('document', fn (): DocumentProduitDirection => $document);

        $flux = response()->stream(static function (): void {}, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="scan-officiel-42.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $service = \Mockery::mock(DocumentProduitScanOfficielService::class);
        $service->shouldReceive('consulter')->once()->with($document)->andReturn($flux);
        $this->app->instance(DocumentProduitScanOfficielService::class, $service);

        $this->actingAs($this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 12))
            ->get('/api/v1/documents-produits-direction/42/scan-officiel')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_route_masque_le_refus_par_404_sans_lire_le_scan(): void
    {
        $document = $this->document();
        Route::bind('document', fn (): DocumentProduitDirection => $document);

        $service = \Mockery::mock(DocumentProduitScanOfficielService::class);
        $service->shouldNotReceive('consulter');
        $this->app->instance(DocumentProduitScanOfficielService::class, $service);

        $this->actingAs($this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 13))
            ->get('/api/v1/documents-produits-direction/42/scan-officiel')
            ->assertNotFound();
    }

    public function test_route_exige_une_authentification(): void
    {
        $document = $this->document();
        Route::bind('document', fn (): DocumentProduitDirection => $document);

        $this->get('/api/v1/documents-produits-direction/42/scan-officiel')
            ->assertUnauthorized();
    }

    public function test_telechargement_generique_refuse_un_document_autonome_sans_pointeur_actif(): void
    {
        $this->assertGenericDownloadRefusedForAutonomousScan(pointer: null);
    }

    public function test_telechargement_generique_refuse_un_document_autonome_avec_pointeur_divergent(): void
    {
        $this->assertGenericDownloadRefusedForAutonomousScan(pointer: 100);
    }

    public function test_telechargement_generique_refuse_une_origine_incoherente_avec_les_relations_autonomes(): void
    {
        $this->assertGenericDownloadRefusedForAutonomousScan(
            pointer: null,
            documentProduitOverrides: ['origine' => DocumentProduitOrigine::TRAITEMENT_DIRECTION->value],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function document(array $overrides = []): DocumentProduitDirection
    {
        $document = new DocumentProduitDirection;
        $document->setRawAttributes(array_merge([
            'id' => 42,
            'origine' => DocumentProduitOrigine::AUTONOME->value,
            'statut' => DocumentProduitStatut::SCAN_ENREGISTRE->value,
            'courrier_id' => 84,
            'direction_id' => 12,
            'directeur_id' => 42,
            'soumis_par_id' => 7,
            'soumis_at' => '2026-01-01 12:00:00',
            'scan_officiel_document_id' => 99,
        ], $overrides));

        return $document;
    }

    private function assertGenericDownloadRefusedForAutonomousScan(
        ?int $pointer,
        array $documentProduitOverrides = [],
    ): void {
        $documentProduit = $this->document(array_merge(
            $documentProduitOverrides,
            ['scan_officiel_document_id' => $pointer],
        ));
        $courrier = new Courrier;
        $courrier->setRawAttributes([
            'id' => 84,
            'numero_accuse_reception' => 'COURRIER-TEST',
        ]);
        $courrier->setRelation('documentProduitDirection', $documentProduit);

        $numerisation = new DocumentNumerise;
        $numerisation->setRawAttributes([
            'id' => 99,
            'numerisable_type' => $courrier->getMorphClass(),
            'numerisable_id' => 84,
            'chemin' => 'numerisations/historique.pdf',
            'version' => 1,
        ]);

        Route::bind('courrier', fn (): Courrier => $courrier);
        Route::bind('document', fn (): DocumentNumerise => $numerisation);
        Gate::before(static fn (): bool => true);
        Storage::fake('local');
        Storage::disk('local')->put('numerisations/historique.pdf', '%PDF-1.7 historical file %%EOF');

        $service = \Mockery::mock(DocumentProduitScanOfficielService::class);
        $service->shouldNotReceive('consulter');
        $this->app->instance(DocumentProduitScanOfficielService::class, $service);

        $this->actingAs($this->utilisateur(UserRole::SECRETARIAT_DIRECTION, directionId: 12))
            ->get('/api/v1/courriers/84/numerisations/99')
            ->assertNotFound();
    }

    private function utilisateur(
        UserRole $role,
        ?Poste $poste = null,
        ?int $directionId = null,
        int $id = 42,
    ): User {
        $user = new User;
        $user->setRawAttributes([
            'id' => $id,
            'role' => $role->value,
            'poste' => $poste?->value,
            'direction_id' => $directionId,
        ]);

        return $user;
    }
}
