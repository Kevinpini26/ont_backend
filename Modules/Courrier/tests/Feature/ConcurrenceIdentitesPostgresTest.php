<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Services\DocumentProduitDirectionService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceIdentitesPostgresTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        if (Schema::hasTable('documents_produits_direction')) {
            foreach (DocumentProduitDirection::query()->where('origine', 'autonome')->get() as $document) {
                $courrier = Courrier::withoutGlobalScopes()->find($document->courrier_id);

                AuditLog::query()
                    ->where('auditable_type', (new DocumentProduitDirection)->getMorphClass())
                    ->where('auditable_id', $document->id)
                    ->delete();
                if ($courrier !== null) {
                    AuditLog::query()
                        ->where('auditable_type', $courrier->getMorphClass())
                        ->where('auditable_id', $courrier->id)
                        ->delete();
                }

                $document->delete();
                $courrier?->delete();
                Dossier::query()->whereKey($courrier?->dossier_id)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_vingt_references_documentaires_concurrentes_sont_uniques(): void
    {
        $direction = Direction::factory()->create(['code' => 'TST']);
        $directeur = User::factory()->directeurDirection($direction)->create();
        $courriers = Courrier::factory()->count(20)->create(['direction_origine_id' => $direction->id]);
        $resultats = $this->executerConcurremment('reference', $courriers->pluck('id')->all(), $directeur->id);

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
        $this->assertSame(20, Courrier::withoutGlobalScopes()->whereNotNull('reference_documentaire')->distinct()->count('reference_documentaire'));
    }

    public function test_deux_validations_de_documents_produits_concurrentes_ne_dupliquent_pas_la_reference(): void
    {
        $direction = Direction::factory()->create();
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG)->create();
        $documentIds = [];
        $directeurIds = [];

        for ($index = 0; $index < 2; $index++) {
            $directeur = User::factory()->directeurDirection($direction)->create([
                'initiales_reference' => $index === 0 ? 'ABC' : 'XYZ',
            ]);
            $source = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
            $courrier = Courrier::factory()->create([
                'dossier_id' => $source->dossier_id,
                'direction_origine_id' => $direction->id,
                'statut' => CourrierStatut::BROUILLON_DIRECTION,
            ]);
            $dispatch = DispatchCourrier::query()->create([
                'courrier_id' => $source->id,
                'dossier_id' => $source->dossier_id,
                'type_destination' => DispatchTypeDestination::DIRECTION,
                'direction_id' => $direction->id,
                'instruction' => 'Traitement concurrent de test.',
                'decisionnaire_id' => $dg->id,
                'autorite_poste' => Poste::DG,
                'decide_at' => now(),
                'statut' => DispatchStatut::EXECUTE,
            ]);
            $traitement = TraitementDirection::query()->create([
                'courrier_id' => $source->id,
                'dossier_id' => $source->dossier_id,
                'dispatch_courrier_id' => $dispatch->id,
                'direction_id' => $direction->id,
                'statut' => TraitementDirectionStatut::TERMINE_DIRECTEUR,
                'recu_par_secretariat_id' => $secretariat->id,
                'recu_secretariat_at' => now(),
                'directeur_id' => $directeur->id,
            ]);
            $documentIds[] = DocumentProduitDirection::query()->create([
                'traitement_direction_id' => $traitement->id,
                'courrier_id' => $courrier->id,
                'document_source_id' => $source->id,
                'direction_id' => $direction->id,
                'statut' => DocumentProduitStatut::SOUMIS_DIRECTEUR,
                'cree_par_id' => $secretariat->id,
                'cree_at' => now(),
                'soumis_par_id' => $secretariat->id,
                'soumis_at' => now(),
            ])->id;
            $directeurIds[] = $directeur->id;
        }

        $resultats = $this->executerConcurremment('document-interne', $documentIds, $directeurIds);
        $courrierIds = DocumentProduitDirection::query()->whereIn('id', $documentIds)->pluck('courrier_id');
        $references = Courrier::withoutGlobalScopes()->whereIn('id', $courrierIds)->pluck('reference_documentaire');
        $documents = DocumentProduitDirection::query()->whereIn('id', $documentIds)->get();

        $this->assertSame(2, $resultats->where('status', 'ok')->count());
        $this->assertSame(2, $documents->count());
        $this->assertSame(2, $documents->where('direction_id', $direction->id)->count());
        $this->assertSame(2, Courrier::withoutGlobalScopes()->whereIn('id', $courrierIds)
            ->where('direction_origine_id', $direction->id)->count());
        $this->assertSame(2, $references->filter()->count());
        $this->assertSame(2, $references->unique()->count());

        $numeros = $references->map(function (string $reference) use ($direction): string {
            $motif = '/\A(\d{3})\/(?:ABC|XYZ)\/'.preg_quote($direction->code, '/').'\/ONT\/'.now()->year.'\z/';
            $this->assertSame(1, preg_match($motif, $reference, $correspondance));

            return $correspondance[1];
        });

        $this->assertSame(2, $numeros->unique()->count());
    }

    public function test_sauvegarde_et_soumission_concurrentes_ne_peuvent_pas_consommer_la_meme_version(): void
    {
        $direction = Direction::factory()->create(['code' => 'RAC']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $id = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Document concurrent',
            'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Version initiale.']]]]],
        ])->assertCreated()->json('data.id');

        $document = DocumentProduitDirection::query()->findOrFail($id);
        $courrierId = $document->courrier_id;
        $dossierId = Courrier::withoutGlobalScopes()->findOrFail($courrierId)->dossier_id;

        try {
            $resultats = $this->executerConcurremment(
                'autonome-concurrent',
                [$id, $id],
                [$secretariat->id, $secretariat->id],
            );
            $document->refresh();
            $courrier = Courrier::withoutGlobalScopes()->findOrFail($courrierId);

            $this->assertSame(1, $resultats->where('status', 'ok')->count());
            $this->assertSame(1, $resultats->where('status', 'error')->count());
            $this->assertTrue(
                ($document->statut === DocumentProduitStatut::BROUILLON && $document->version_contenu === 2 && $courrier->objet === 'Sauvegarde concurrente')
                || ($document->statut === DocumentProduitStatut::SOUMIS_DIRECTEUR && $document->version_contenu === 1 && $courrier->objet === 'Document concurrent'),
            );
            $this->assertNull($courrier->reference_documentaire);
        } finally {
            AuditLog::query()
                ->where('auditable_type', (new DocumentProduitDirection)->getMorphClass())
                ->where('auditable_id', $id)
                ->delete();
            DocumentProduitDirection::query()->findOrFail($id)->delete();
            Courrier::withoutGlobalScopes()->findOrFail($courrierId)->delete();
            Dossier::query()->findOrFail($dossierId)->delete();
        }
    }

    public function test_deux_sauvegardes_concurrentes_de_la_meme_version_necrasent_pas_le_brouillon(): void
    {
        $direction = Direction::factory()->create(['code' => 'SAV']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $id = $this->creerEtSoumettreAutonome($secretariat, false);

        $resultats = $this->executerConcurremment(
            'autonome-sauvegarde',
            [$id, $id],
            [$secretariat->id, $secretariat->id],
        );
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $courrier = $document->courrier()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(1, $resultats->where('status', 'ok')->count());
        $this->assertSame(1, $resultats->where('status', 'error')->count());
        $this->assertSame(DocumentProduitStatut::BROUILLON, $document->statut);
        $this->assertSame(2, $document->version_contenu);
        $this->assertContains($courrier->objet, ['Sauvegarde worker 0', 'Sauvegarde worker 1']);
        $this->assertNull($courrier->reference_documentaire);
    }

    public function test_deux_validations_concurrentes_du_meme_document_autonome_nattribuent_quune_reference(): void
    {
        $direction = Direction::factory()->create(['code' => 'DVL']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $id = $this->creerEtSoumettreAutonome($secretariat);

        $resultats = $this->executerConcurremment(
            'autonome-validation',
            [$id, $id],
            [$directeur->id, $directeur->id],
        );
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $courrier = $document->courrier()->withoutGlobalScopes()->firstOrFail();
        $auditValidation = AuditLog::query()
            ->where('auditable_type', (new DocumentProduitDirection)->getMorphClass())
            ->where('auditable_id', $id)
            ->where('action', 'document_direction.valide')
            ->count();
        $auditReference = AuditLog::query()
            ->where('auditable_type', (new Courrier)->getMorphClass())
            ->where('auditable_id', $courrier->id)
            ->where('action', 'courrier.reference_documentaire_interne_attribuee')
            ->count();

        $this->assertSame(1, $resultats->where('status', 'ok')->count());
        $this->assertSame(1, $resultats->where('status', 'error')->count());
        $this->assertSame(DocumentProduitStatut::VALIDE, $document->statut);
        $this->assertNotNull($courrier->reference_documentaire);
        $this->assertSame(1, Courrier::withoutGlobalScopes()
            ->where('reference_documentaire', $courrier->reference_documentaire)
            ->count());
        $this->assertSame(1, $auditValidation);
        $this->assertSame(1, $auditReference);
    }

    public function test_correction_concurrente_avec_validation_nautorise_quune_transition_compatible(): void
    {
        $direction = Direction::factory()->create(['code' => 'COR']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $id = $this->creerEtSoumettreAutonome($secretariat);

        $resultats = $this->executerConcurremment(
            'autonome-correction-validation',
            [$id, $id],
            [$directeur->id, $directeur->id],
        );
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $courrier = $document->courrier()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(1, $resultats->where('status', 'ok')->count());
        $this->assertSame(1, $resultats->where('status', 'error')->count());
        $this->assertContains($document->statut, [DocumentProduitStatut::A_CORRIGER, DocumentProduitStatut::VALIDE]);
        $this->assertSame(
            $document->statut === DocumentProduitStatut::VALIDE,
            $courrier->reference_documentaire !== null,
        );
    }

    public function test_validations_autonomes_concurrentes_reutilisent_le_compteur_historique_de_direction(): void
    {
        $direction = Direction::factory()->create(['code' => 'HIS']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);

        $historiqueId = $this->creerEtSoumettreAutonome($secretariat);
        app(DocumentProduitDirectionService::class)->valider(
            DocumentProduitDirection::query()->findOrFail($historiqueId),
            $directeur,
        );
        $premiereReference = DocumentProduitDirection::query()->findOrFail($historiqueId)
            ->courrier()->withoutGlobalScopes()->firstOrFail()->reference_documentaire;
        $this->assertSame('001/ABC/HIS/ONT/'.now()->year, $premiereReference);

        $ids = [
            $this->creerEtSoumettreAutonome($secretariat),
            $this->creerEtSoumettreAutonome($secretariat),
        ];
        $resultats = $this->executerConcurremment('autonome-validation', $ids, $directeur->id);
        $courriers = DocumentProduitDirection::query()->whereIn('id', $ids)->with('courrier')->get()->pluck('courrier');
        $references = $courriers->pluck('reference_documentaire');
        $numeros = $references->map(function (?string $reference): int {
            $this->assertNotNull($reference);
            $this->assertSame(1, preg_match('/\A(\d{3})\/ABC\/HIS\/ONT\/'.now()->year.'\z/', $reference, $match));

            return (int) $match[1];
        });

        $this->assertSame(2, $resultats->where('status', 'ok')->count());
        $this->assertSame(2, $references->unique()->count());
        $this->assertSame([2, 3], $numeros->sort()->values()->all());
    }

    public function test_vingt_numeros_enregistrement_concurrents_sont_uniques(): void
    {
        $resultats = $this->executerConcurremment('numero', range(1, 20), 0);

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
    }

    private function creerEtSoumettreAutonome(User $secretariat, bool $soumettre = true): int
    {
        $id = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Document concurrent autonome',
            'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte initial.']]]]],
        ])->assertCreated()->json('data.id');

        if ($soumettre) {
            $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre", [
                'version_contenu' => 1,
            ])->assertOk();
        }

        return (int) $id;
    }

    public function test_enregistrement_concurrent_des_depots_publics_reste_unique(): void
    {
        $direction = Direction::factory()->create();
        $receptions = User::factory()->count(20)->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $courriers = Courrier::factory()->count(20)->create([
            'statut' => CourrierStatut::RECU,
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'numero_enregistrement' => null,
        ]);

        $resultats = $this->executerConcurremment('depot', $courriers->pluck('id')->all(), $receptions->pluck('id')->all());

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
        $this->assertSame(20, Courrier::withoutGlobalScopes()->whereIn('id', $courriers->pluck('id'))->whereNotNull('numero_enregistrement')->distinct()->count('numero_enregistrement'));

        $memeCourrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'numero_enregistrement' => null,
        ]);
        $double = $this->executerConcurremment('depot', [$memeCourrier->id, $memeCourrier->id], $receptions->take(2)->pluck('id')->all());

        $this->assertSame(1, $double->where('status', 'ok')->count());
        $this->assertSame(1, $double->where('status', 'error')->count());
        $this->assertNotNull($memeCourrier->fresh()->numero_enregistrement);
        $this->assertSame(1, Courrier::withoutGlobalScopes()->whereKey($memeCourrier->id)->whereNotNull('numero_enregistrement')->count());
    }

    /**
     * @param  array<int, int>  $identifiants
     * @param  int|array<int, int>  $acteurIds
     */
    private function executerConcurremment(string $mode, array $identifiants, int|array $acteurIds)
    {
        $barriere = sys_get_temp_dir().'/ont-identites-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $worker = base_path('Modules/Courrier/tests/Support/identite_worker.php');
        $processus = collect($identifiants)->values()->map(fn (int $identifiant, int $index) => new Process([
            PHP_BINARY, $worker, $mode, (string) $identifiant, (string) (is_array($acteurIds) ? $acteurIds[$index] : $acteurIds), $barriere, (string) $index,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'ont_testing']));
        $processus->each(function (Process $process): void {
            $process->setTimeout(90)->start();
        });

        try {
            $limite = microtime(true) + 45;
            while (count(glob($barriere.'/ready-*') ?: []) < count($identifiants) && microtime(true) < $limite) {
                usleep(10_000);
            }
            $this->assertCount(count($identifiants), glob($barriere.'/ready-*') ?: []);
            file_put_contents($barriere.'/start', 'go');
            $processus->each(function (Process $process): void {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            });

            return collect(array_keys($identifiants))->map(fn (int $index) => json_decode((string) file_get_contents($barriere.'/result-'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            if (! file_exists($barriere.'/start')) {
                file_put_contents($barriere.'/start', 'go');
            }
            $processus->each(function (Process $process): void {
                if ($process->isStarted() && ! $process->isTerminated()) {
                    $process->wait();
                }
            });
            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }
}
