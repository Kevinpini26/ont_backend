<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Jobs\ExtraireTexteDocumentNumeriseJob;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Models\Direction;

/**
 * Lot 3a (feuille de couverture) + import par lot depuis une clé USB —
 * voir docs/numerisation-courrier.md. Ne dépend d'aucun matériel réseau :
 * fonctionne avec un simple copieur scan-vers-USB.
 */
class FeuilleCouvertureEtImportLotTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_la_feuille_de_couverture_unitaire_est_un_pdf(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['created_by' => $agent->id]);

        $reponse = $this->actingAs($agent)->get("/api/v1/courriers/{$courrier->id}/feuille-couverture");

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_la_feuille_de_couverture_en_lot_regroupe_plusieurs_courriers(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $c1 = Courrier::factory()->create(['created_by' => $agent->id]);
        $c2 = Courrier::factory()->create(['created_by' => $agent->id]);

        $reponse = $this->actingAs($agent)->get("/api/v1/courriers/feuilles-couverture-lot?ids[]={$c1->id}&ids[]={$c2->id}");

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_importer_un_segment_par_numero_da_r_cree_une_version_source_copieur(): void
    {
        Storage::fake('local');
        Event::fake([JobProcessed::class]);
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create([
            'numerisation_statut' => NumerisationStatut::A_NUMERISER,
            'created_by' => $agent->id,
        ]);

        $reponse = $this->actingAs($agent)->postJson('/api/v1/courriers/import-lot', [
            'numero_accuse_reception' => $courrier->numero_accuse_reception,
            'fichier' => UploadedFile::fake()->create('segment.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, $reponse->json('document.version'));
        $this->assertSame(NumerisationStatut::NUMERISE->value, $courrier->fresh()->numerisation_statut->value);
        $this->assertSame(SourceDocumentNumerise::COPIEUR, $courrier->fresh()->numerisations->first()->source);
        $this->assertCount(1, Storage::disk('local')->allFiles('numerisations'));
        Event::assertDispatched(JobProcessed::class, fn (JobProcessed $event): bool => $event->job->resolveName() === ExtraireTexteDocumentNumeriseJob::class);
    }

    public function test_importer_lot_sur_courrier_archive_est_refuse_avant_tout_effet(): void
    {
        Storage::fake('local');
        Queue::fake();
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create([
            'created_by' => $agent->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'numerisation_statut' => NumerisationStatut::A_NUMERISER,
        ]);
        $this->archiverCourrierViaEndpoints($courrier, $direction);

        $documentsAvant = DocumentNumerise::query()->count();
        $fichiersAvant = Storage::disk('local')->allFiles('numerisations');
        $statutAvant = $courrier->fresh()->numerisation_statut;

        $response = $this->actingAs($agent)->postJson('/api/v1/courriers/import-lot', [
            'numero_accuse_reception' => $courrier->numero_accuse_reception,
            'fichier' => UploadedFile::fake()->create('segment.pdf', 200, 'application/pdf'),
        ]);

        $response->assertUnprocessable()->assertJsonPath('message', 'Un document archivé ne peut plus recevoir de mutation métier.');
        $this->assertSame($documentsAvant, DocumentNumerise::query()->count());
        $this->assertSame($fichiersAvant, Storage::disk('local')->allFiles('numerisations'));
        $this->assertSame($statutAvant, $courrier->fresh()->numerisation_statut);
        Queue::assertNothingPushed();
    }

    public function test_importer_avec_un_numero_dar_inconnu_est_rejete(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($agent)->postJson('/api/v1/courriers/import-lot', [
            'numero_accuse_reception' => 'INCONNU-0000',
            'fichier' => UploadedFile::fake()->create('segment.pdf', 200, 'application/pdf'),
        ])->assertStatus(422);
    }
}
