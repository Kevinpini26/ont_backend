<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Courrier sortant initié par la DG elle-même (instruction, note de
 * service), sans courrier entrant déclencheur — voir
 * CourrierCircuitService::initierParDg(). Couvre les deux chemins possibles
 * selon que le rédacteur (Secrétariat 01) coche ou non "Nécessite la
 * validation de la DG avant envoi", jusqu'à la réception effective par la
 * direction destinataire.
 */
class CourrierInitieParDgTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function contenu(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    private function instruction(User $dg, User $sec1): int
    {
        $id = $this->actingAs($dg)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Préparer un nouveau courrier institutionnel.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($sec1);

        return $id;
    }

    public function test_le_secretariat_1_peut_initier_un_courrier_sans_validation_requise_jusqua_reception(): void
    {
        $directionSecretariat = Direction::factory()->create();
        $directionDestinataire = Direction::factory()->create();

        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $directionSecretariat);
        $dg = $this->agent(Poste::DG, $directionSecretariat);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $directionSecretariat);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $directionSecretariat);
        $responsableDestinataire = User::factory()->responsableDirection($directionDestinataire)->create();

        $courrier = $this->actingAs($secretariat1)->postJson('/api/v1/courriers/initier-dg', [
            'instruction_courrier_dg_id' => $this->instruction($dg, $secretariat1),
            'direction_destination_id' => $directionDestinataire->id,
            'objet' => 'Note de service — nouvelle procédure',
            'projet_reponse_contenu' => $this->contenu(),
            'relecteur_id' => $relecteur->id,
            'validation_dg_requise' => false,
        ])->assertCreated()->json('data');

        $this->assertSame(CourrierStatut::EN_RELECTURE->value, $courrier['statut']);
        $this->assertTrue($courrier['initie_par_dg']);
        $this->assertFalse($courrier['validation_dg_requise']);
        $this->assertNull($courrier['numero_accuse_reception']);
        $id = $courrier['id'];

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/valider-relecture")->assertOk();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);

        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $response = $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$id}/enregistrer", [
                'classification' => CourrierClassification::INTERNE->value,
                'note_technique' => 'Note de service diffusée sans réserve.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::ENREGISTRE->value);

        // Une communication interne initiée par la DG n'est pas une entrée
        // externe enregistrée par la Réception : SEC2 ne lui invente plus un
        // numéro d'enregistrement lors du classement.
        $this->assertNull($response->json('data.numero_enregistrement'));

        // Réception effective : la direction destinataire le voit dans sa
        // propre liste, comme n'importe quel autre courrier entrant, avec
        // la mention distincte d'origine.
        $recu = $this->actingAs($responsableDestinataire)
            ->getJson("/api/v1/courriers/{$id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($recu['initie_par_dg']);
        $this->assertSame(CourrierClassification::INTERNE->value, $recu['classification']);
    }

    public function test_le_secretariat_1_peut_initier_un_courrier_avec_validation_dg_requise(): void
    {
        $direction = Direction::factory()->create();
        $directionDestinataire = Direction::factory()->create();

        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);

        $courrier = $this->actingAs($secretariat1)->postJson('/api/v1/courriers/initier-dg', [
            'instruction_courrier_dg_id' => $this->instruction($dg, $secretariat1),
            'direction_destination_id' => $directionDestinataire->id,
            'objet' => 'Instruction — validation préalable requise',
            'projet_reponse_contenu' => $this->contenu(),
            'relecteur_id' => $relecteur->id,
            'validation_dg_requise' => true,
        ])->assertCreated()->json('data');

        $this->assertSame(CourrierStatut::EN_ATTENTE_VALIDATION_DG->value, $courrier['statut']);
        $id = $courrier['id'];

        // La relecture est impossible tant que la DG n'a pas validé : le
        // courrier n'est même pas encore au statut en_relecture, donc même
        // le relecteur désigné n'y est pas encore habilité (403, bloqué en
        // amont par la policy — jamais atteint le garde-fou 422 du service).
        $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$id}/valider-relecture")
            ->assertStatus(403);

        // Un poste autre que DG ne peut pas valider avant diffusion.
        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/valider-avant-diffusion")
            ->assertStatus(403);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/valider-avant-diffusion")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_RELECTURE->value);

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/valider-relecture")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/signer")->assertOk();
        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$id}/enregistrer", [
                'classification' => CourrierClassification::INTERNE->value,
                'note_technique' => 'Instruction diffusée après validation DG.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::ENREGISTRE->value);
    }

    public function test_un_poste_autre_que_secretariat_1_ne_peut_pas_initier_un_courrier_de_la_dg(): void
    {
        $direction = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $directionDestinataire = Direction::factory()->create();

        $this->actingAs($secretariat2)->postJson('/api/v1/courriers/initier-dg', [
            'direction_destination_id' => $directionDestinataire->id,
            'objet' => 'Tentative non autorisée',
            'projet_reponse_contenu' => $this->contenu(),
            'relecteur_id' => $relecteur->id,
        ])->assertStatus(403);
    }

    public function test_le_secretariat_1_ne_peut_pas_se_designer_lui_meme_comme_relecteur(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $directionDestinataire = Direction::factory()->create();

        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($secretariat1)->postJson('/api/v1/courriers/initier-dg', [
            'instruction_courrier_dg_id' => $this->instruction($dg, $secretariat1),
            'direction_destination_id' => $directionDestinataire->id,
            'objet' => 'Auto-désignation refusée',
            'projet_reponse_contenu' => $this->contenu(),
            'relecteur_id' => $secretariat1->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['relecteur_id']);
    }

    public function test_une_piece_jointe_optionnelle_peut_accompagner_le_courrier(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $directionDestinataire = Direction::factory()->create();

        $courrier = $this->actingAs($secretariat1)->post('/api/v1/courriers/initier-dg', [
            'instruction_courrier_dg_id' => $this->instruction($dg, $secretariat1),
            'direction_destination_id' => $directionDestinataire->id,
            'objet' => 'Avec pièce jointe',
            'projet_reponse_contenu' => json_encode($this->contenu()),
            'relecteur_id' => $relecteur->id,
            'piece_jointe' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertTrue($courrier['piece_jointe_disponible']);
    }
}
