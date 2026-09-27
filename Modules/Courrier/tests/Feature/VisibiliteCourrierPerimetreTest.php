<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class VisibiliteCourrierPerimetreTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_matrice_direction_multi_direction_et_enumeration_id(): void
    {
        $centrale = Direction::factory()->create();
        $finances = Direction::factory()->create();
        $etudes = Direction::factory()->create();
        $marketing = Direction::factory()->create();
        $courrier = Courrier::factory()->create([
            'objet' => 'CONFIDENTIEL-TEST-ONT-92841',
            'direction_origine_id' => $centrale->id,
            'direction_destination_id' => $centrale->id,
            'statut' => CourrierStatut::DISPATCH_EXECUTE,
        ]);
        $this->dispatchExecute($courrier, $finances);
        $this->dispatchExecute($courrier, $etudes);

        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $assistant = $this->agent(Poste::ASSISTANT_1, $centrale);
        $directeurFinances = User::factory()->directeurDirection($finances)->create();
        $secretariatFinances = User::factory()->secretariatDirection($finances)->create();
        $directeurEtudes = User::factory()->directeurDirection($etudes)->create();
        $directeurMarketing = User::factory()->directeurDirection($marketing)->create();
        $secretariatMarketing = User::factory()->secretariatDirection($marketing)->create();

        foreach ([$dg, $sec2, $directeurFinances, $secretariatFinances, $directeurEtudes] as $autorise) {
            $this->actingAs($autorise)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        }
        foreach ([$directeurMarketing, $secretariatMarketing, $assistant] as $interdit) {
            $this->actingAs($interdit)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        }
    }

    public function test_objet_recherche_dossier_et_piece_jointe_ne_fuitent_pas(): void
    {
        Storage::fake('local');
        $finances = Direction::factory()->create();
        $marketing = Direction::factory()->create();
        $secret = 'CONFIDENTIEL-TEST-ONT-92841';
        $chemin = UploadedFile::fake()->create('secret.pdf', 20, 'application/pdf')->store('courriers', 'local');
        $courrier = Courrier::factory()->create([
            'objet' => $secret,
            'reference_documentaire' => 'REF-SECRET-92841',
            'expediteur_externe_nom' => 'EXPEDITEUR-SECRET-92841',
            'piece_jointe_chemin' => $chemin,
            'direction_origine_id' => $finances->id,
            'direction_destination_id' => $finances->id,
        ]);
        $marketingUser = User::factory()->directeurDirection($marketing)->create();
        $financesUser = User::factory()->directeurDirection($finances)->create();

        $this->actingAs($marketingUser)->getJson('/api/v1/courriers')->assertOk()->assertDontSee($secret);
        foreach ([$secret, 'REF-SECRET-92841', 'EXPEDITEUR-SECRET-92841'] as $recherche) {
            $this->actingAs($marketingUser)->getJson('/api/v1/courriers?recherche='.urlencode($recherche))
                ->assertOk()->assertJsonCount(0, 'data')->assertDontSee($secret);
        }
        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        $this->actingAs($marketingUser)->get("/api/v1/courriers/{$courrier->id}/piece-jointe")->assertNotFound();
        $this->actingAs($marketingUser)->getJson("/api/v1/dossiers/{$courrier->dossier_id}")->assertNotFound()->assertDontSee($secret);

        $this->actingAs($financesUser)->getJson('/api/v1/courriers?recherche='.urlencode($secret))
            ->assertOk()->assertJsonPath('data.0.id', $courrier->id);
    }

    public function test_un_dossier_ne_revele_que_les_documents_visibles(): void
    {
        $finances = Direction::factory()->create();
        $autre = Direction::factory()->create();
        $c = Courrier::factory()->create(['objet' => 'C visible', 'direction_origine_id' => $finances->id]);
        foreach (['A caché', 'B caché', 'D caché'] as $objet) {
            Courrier::factory()->create([
                'dossier_id' => $c->dossier_id,
                'objet' => $objet,
                'direction_origine_id' => $autre->id,
                'direction_destination_id' => $autre->id,
            ]);
        }
        $directeur = User::factory()->directeurDirection($finances)->create();

        $reponse = $this->actingAs($directeur)->getJson("/api/v1/dossiers/{$c->dossier_id}")
            ->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.id', $c->id);
        $reponse->assertDontSee('A caché')->assertDontSee('B caché')->assertDontSee('D caché');
    }

    public function test_document_direction_devient_visible_apres_dispatch_execute_uniquement(): void
    {
        $dmc = Direction::factory()->create();
        $dep = Direction::factory()->create();
        $b = Courrier::factory()->create(['objet' => 'Analyse DMC', 'direction_origine_id' => $dmc->id]);
        $depUser = User::factory()->directeurDirection($dep)->create();

        $this->actingAs($depUser)->getJson("/api/v1/courriers/{$b->id}")->assertNotFound();
        $dispatch = $this->dispatchExecute($b, $dep, DispatchStatut::EN_ATTENTE);
        $this->actingAs($depUser)->getJson("/api/v1/courriers/{$b->id}")->assertNotFound();
        $dispatch->update(['statut' => DispatchStatut::EXECUTE, 'execute_at' => now()]);
        $this->actingAs($depUser)->getJson("/api/v1/courriers/{$b->id}")->assertOk();
    }

    public function test_courrier_interne_et_sortant_restent_cloisonnes(): void
    {
        $centrale = Direction::factory()->create();
        $rh = Direction::factory()->create();
        $marketing = Direction::factory()->create();
        $interne = Courrier::factory()->create([
            'objet' => 'Interne RH vers DG',
            'direction_origine_id' => $rh->id,
            'direction_destination_id' => $centrale->id,
        ]);
        $sortant = Courrier::factory()->create([
            'objet' => 'Réponse externe officielle',
            'sens' => SensCourrier::SORTANT,
            'direction_origine_id' => $rh->id,
            'direction_destination_id' => null,
            'statut' => CourrierStatut::SIGNE,
        ]);
        $marketingUser = User::factory()->directeurDirection($marketing)->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);

        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$interne->id}")->assertNotFound();
        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$sortant->id}")->assertNotFound();
        $sortant->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'destinataire_poste' => Poste::SECRETARIAT_2,
            'created_at' => now(),
        ]);
        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$sortant->id}")->assertOk();

        $this->dispatchExecute($interne, $marketing);
        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$interne->id}")->assertOk();
    }

    private function dispatchExecute(Courrier $courrier, Direction $direction, DispatchStatut $statut = DispatchStatut::EXECUTE): DispatchCourrier
    {
        $decisionnaire = User::factory()->create(['poste' => Poste::DG]);

        return DispatchCourrier::query()->create([
            'courrier_id' => $courrier->id,
            'dossier_id' => $courrier->dossier_id,
            'cycle' => ((int) $courrier->dispatchs()->max('cycle')) + 1,
            'type_destination' => DispatchTypeDestination::DIRECTION,
            'direction_id' => $direction->id,
            'instruction' => 'Traiter ce document.',
            'decisionnaire_id' => $decisionnaire->id,
            'decisionnaire_poste' => Poste::DG,
            'autorite_poste' => Poste::DG,
            'decide_at' => now(),
            'statut' => $statut,
            'execute_at' => $statut === DispatchStatut::EXECUTE ? now() : null,
        ]);
    }
}
