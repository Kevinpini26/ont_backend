<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceIdentitesPostgresTest extends TestCase
{
    use DatabaseMigrations;

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

    public function test_vingt_numeros_enregistrement_concurrents_sont_uniques(): void
    {
        $resultats = $this->executerConcurremment('numero', range(1, 20), 0);

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
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
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'ont_testing']));
        $processus->each(function (Process $process): void {
            $process->setTimeout(45)->start();
        });

        $limite = microtime(true) + 20;
        while (count(glob($barriere.'/ready-*') ?: []) < count($identifiants) && microtime(true) < $limite) {
            usleep(10_000);
        }
        $this->assertCount(count($identifiants), glob($barriere.'/ready-*') ?: []);
        file_put_contents($barriere.'/start', 'go');
        $processus->each(function (Process $process): void {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        });
        $resultats = collect(array_keys($identifiants))->map(fn (int $index) => json_decode((string) file_get_contents($barriere.'/result-'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR));
        foreach (glob($barriere.'/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($barriere);

        return $resultats;
    }
}
