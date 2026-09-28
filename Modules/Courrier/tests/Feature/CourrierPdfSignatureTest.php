<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class CourrierPdfSignatureTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_pdf_definitif_est_genere_exactement_a_la_signature_pas_avant(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            // sens=sortant (plutôt que la valeur par défaut 'entrant') :
            // en_relecture n'est atteignable, pour un courrier nécessitant
            // l'avis DG, que via le circuit 'sortant'/'dg_initie' — le
            // circuit 'complet' route désormais ce même rôle vers
            // PROJET_A_VALIDER (voir CourrierStatut::PROJET_A_VALIDER).
            'sens' => 'sortant',
            'destinataire_externe_nom' => 'Partenaire signé',
            'statut' => CourrierStatut::EN_RELECTURE,
            'relecteur_id' => $relecteur->id,
            'relecture_validee_at' => now(),
            'signataire_id' => null,
            'signe_at' => null,
            'pdf_chemin' => null,
            'projet_reponse_contenu' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Corps du courrier signé.']]],
                ],
            ],
        ]);
        $this->marquerDecharge($courrier);

        $this->assertNull($courrier->pdf_chemin);

        $response = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);

        $courrier->refresh();
        $this->assertNotNull($courrier->pdf_chemin);
        Storage::disk('local')->assertExists($courrier->pdf_chemin);

        // Empreinte d'intégrité (voir docs/conformite-donnees.md) : doit
        // correspondre exactement au contenu réellement stocké sur disque.
        $this->assertNotNull($courrier->pdf_sha256);
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($courrier->pdf_chemin)),
            $courrier->pdf_sha256,
        );
    }

    public function test_le_pdf_est_telechargeable_apres_signature(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'signataire_id' => $dg->id,
            'signe_at' => now(),
            'pdf_chemin' => 'courriers-signes/courrier-test.pdf',
            'pdf_sha256' => hash('sha256', '%PDF-1.7 contenu de test'),
        ]);
        Storage::disk('local')->put($courrier->pdf_chemin, '%PDF-1.7 contenu de test');

        $this->actingAs($dg)
            ->get("/api/v1/courriers/{$courrier->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_telechargement_renvoie_404_si_le_courrier_nest_pas_encore_signe(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU, 'pdf_chemin' => null]);

        $this->actingAs($dg)
            ->get("/api/v1/courriers/{$courrier->id}/pdf")
            ->assertStatus(404);
    }

    /**
     * Un courrier "enregistre" via le circuit court (enregistrement direct,
     * jamais passé par "signe") n'a pas de PDF — voir signer(), seul point
     * où pdf_chemin est renseigné. `pdf_disponible` doit refléter la
     * présence réelle du fichier, pas seulement le statut : sinon le
     * frontend affiche un bouton "Voir le PDF signé" qui échoue en 404.
     */
    public function test_pdf_disponible_est_faux_pour_un_enregistrement_direct_sans_signature(): void
    {
        $direction = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::ENREGISTRE,
            'pdf_chemin' => null,
            'created_by' => $secretariat2->id,
        ]);

        $reponse = $this->actingAs($secretariat2)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();

        $this->assertFalse($reponse->json('data.pdf_disponible'));
    }

    public function test_pdf_disponible_est_vrai_apres_signature(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'signataire_id' => $dg->id,
            'signe_at' => now(),
            'pdf_chemin' => 'courriers-signes/courrier-test.pdf',
            'pdf_sha256' => hash('sha256', 'contenu-test'),
        ]);

        $reponse = $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();

        $this->assertTrue($reponse->json('data.pdf_disponible'));
    }
}
