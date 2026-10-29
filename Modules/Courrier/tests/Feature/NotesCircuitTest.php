<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Notifications\AnnotationCircuitNotification;
use Modules\Courrier\Services\CourrierAnnotationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/** Notes de circuit : types colorés, droits par type, rappel personnel, correction, lecture, notification et audit. */
class NotesCircuitTest extends CourrierTestCase
{
    use RefreshDatabase;

    private Direction $direction;

    private User $dg;

    private User $reception;

    private Courrier $courrier;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->direction = Direction::factory()->create();
        $this->dg = $this->agent(Poste::DG, $this->direction);
        $this->reception = $this->agent(Poste::RECEPTION, $this->direction);
        $this->courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'direction_origine_id' => $this->direction->id,
            'created_by' => $this->reception->id,
        ]);
    }

    private function url(string $suite = ''): string
    {
        return "/api/v1/courriers/{$this->courrier->id}/annotations{$suite}";
    }

    private function noter(User $auteur, array $donnees): TestResponse
    {
        return $this->actingAs($auteur)->postJson($this->url(), $donnees);
    }

    public function test_une_note_sans_type_est_une_observation_avec_sa_couleur_et_elle_est_tracee(): void
    {
        $this->noter($this->reception, ['contenu' => 'A vérifier'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'observation')
            ->assertJsonPath('data.type_label', 'Observation')
            ->assertJsonPath('data.couleur', 'jaune')
            ->assertJsonPath('data.visibilite', 'circuit');

        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.annotation_creee', 'auditable_id' => $this->courrier->id, 'user_id' => $this->reception->id]);
    }

    public function test_les_quatre_types_ont_chacun_un_nom_et_une_couleur(): void
    {
        $attendus = ['instruction' => ['Instruction', 'bleu'], 'observation' => ['Observation', 'jaune'], 'rappel' => ['Rappel personnel', 'vert'], 'urgent' => ['Urgent', 'rouge']];

        foreach ($attendus as $type => [$label, $couleur]) {
            $this->noter($this->dg, ['contenu' => "Note {$type}", 'type' => $type])
                ->assertCreated()
                ->assertJsonPath('data.type_label', $label)
                ->assertJsonPath('data.couleur', $couleur);
        }
    }

    public function test_la_dg_le_dga_et_les_directeurs_peuvent_donner_une_instruction(): void
    {
        $dga = $this->agent(Poste::DGA, $this->direction);
        // Le DGA ne voit un courrier que si la DG le lui a confié pour instruction.
        $this->actingAs($this->dg)->postJson("/api/v1/courriers/{$this->courrier->id}/confier-au-dga", ['consigne' => 'Analyser'])->assertOk();
        $directeur = User::factory()->directeurDirection($this->direction)->create();

        foreach ([$this->dg, $dga, $directeur] as $auteur) {
            $this->noter($auteur, ['contenu' => 'A traiter', 'type' => 'instruction'])->assertCreated()->assertJsonPath('data.type', 'instruction');
        }
    }

    public function test_un_acteur_sans_autorite_ne_peut_pas_donner_une_instruction_mais_peut_observer_ou_signaler_urgent(): void
    {
        $this->noter($this->reception, ['contenu' => 'Ordre', 'type' => 'instruction'])->assertForbidden();
        $this->noter($this->reception, ['contenu' => 'Remarque', 'type' => 'observation'])->assertCreated();
        $this->noter($this->reception, ['contenu' => 'Attention', 'type' => 'urgent'])->assertCreated();
    }

    public function test_un_rappel_est_personnel_et_n_a_pas_de_destinataire(): void
    {
        $this->noter($this->reception, ['contenu' => 'Penser à relancer', 'type' => 'rappel'])
            ->assertCreated()
            ->assertJsonPath('data.visibilite', 'personnelle')
            ->assertJsonPath('data.personnelle', true);

        $this->noter($this->reception, ['contenu' => 'Rappel ciblé', 'type' => 'rappel', 'destinataire_poste' => 'dg'])->assertUnprocessable();
    }

    public function test_le_rappel_d_un_utilisateur_n_est_visible_que_par_son_auteur(): void
    {
        $this->noter($this->reception, ['contenu' => 'Mon pense-bête', 'type' => 'rappel'])->assertCreated();
        $this->noter($this->reception, ['contenu' => 'Note commune'])->assertCreated();

        $this->actingAs($this->reception)->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->dg)->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contenu', 'Note commune');
    }

    public function test_une_note_urgente_a_jour_remonte_en_tete_de_liste(): void
    {
        $this->noter($this->reception, ['contenu' => 'Première'])->assertCreated();
        $this->noter($this->reception, ['contenu' => 'Alerte', 'type' => 'urgent'])->assertCreated();
        $this->noter($this->reception, ['contenu' => 'Dernière'])->assertCreated();

        $this->actingAs($this->dg)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.type', 'urgent')
            ->assertJsonPath('data.1.contenu', 'Dernière')
            ->assertJsonPath('data.2.contenu', 'Première');
    }

    public function test_une_note_du_circuit_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $id = $this->noter($this->dg, ['contenu' => 'Définitive', 'type' => 'instruction'])->json('data.id');

        $this->actingAs($this->dg)->putJson($this->url("/{$id}"), ['contenu' => 'Modifiée'])->assertStatus(405);
        $this->actingAs($this->dg)->patchJson($this->url("/{$id}"), ['contenu' => 'Modifiée'])->assertStatus(405);
        $this->actingAs($this->dg)->deleteJson($this->url("/{$id}"))->assertForbidden();

        $this->assertDatabaseHas('courrier_annotations', ['id' => $id, 'contenu' => 'Définitive']);
    }

    public function test_seul_l_auteur_retire_son_rappel_personnel_et_le_retrait_est_trace(): void
    {
        $id = $this->noter($this->reception, ['contenu' => 'A retirer', 'type' => 'rappel'])->json('data.id');

        $this->actingAs($this->dg)->deleteJson($this->url("/{$id}"))->assertNotFound();
        $this->actingAs($this->reception)->deleteJson($this->url("/{$id}"))->assertNoContent();

        $this->assertDatabaseMissing('courrier_annotations', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.rappel_retire', 'auditable_id' => $this->courrier->id, 'user_id' => $this->reception->id]);
    }

    public function test_une_correction_cree_une_nouvelle_note_qui_reference_l_ancienne(): void
    {
        $ancienne = $this->noter($this->dg, ['contenu' => 'Erreur', 'type' => 'instruction', 'destinataire_poste' => 'secretariat_1'])->json('data.id');

        $reponse = $this->actingAs($this->dg)->postJson($this->url("/{$ancienne}/correction"), ['contenu' => 'Version corrigée'])
            ->assertCreated()
            ->assertJsonPath('data.contenu', 'Version corrigée')
            ->assertJsonPath('data.type', 'instruction')
            ->assertJsonPath('data.corrige_la_note_id', $ancienne);

        $this->assertDatabaseHas('courrier_annotations', ['id' => $ancienne, 'contenu' => 'Erreur', 'annule_par_id' => $reponse->json('data.id')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.annotation_corrigee', 'auditable_id' => $this->courrier->id]);
    }

    public function test_une_note_ne_se_corrige_qu_une_fois_et_seulement_par_son_auteur(): void
    {
        $id = $this->noter($this->dg, ['contenu' => 'Initiale'])->json('data.id');

        $this->actingAs($this->reception)->postJson($this->url("/{$id}/correction"), ['contenu' => 'Intrus'])->assertForbidden();
        $this->actingAs($this->dg)->postJson($this->url("/{$id}/correction"), ['contenu' => 'Première correction'])->assertCreated();
        $this->actingAs($this->dg)->postJson($this->url("/{$id}/correction"), ['contenu' => 'Seconde correction'])->assertUnprocessable();
    }

    public function test_un_rappel_personnel_ne_se_corrige_pas(): void
    {
        $id = $this->noter($this->reception, ['contenu' => 'Pense-bête', 'type' => 'rappel'])->json('data.id');

        $this->actingAs($this->reception)->postJson($this->url("/{$id}/correction"), ['contenu' => 'Autre'])->assertForbidden();
    }

    public function test_la_lecture_d_une_instruction_est_marquee_affichee_et_tracee_une_seule_fois(): void
    {
        $id = $this->noter($this->dg, ['contenu' => 'Lisez-moi', 'type' => 'instruction', 'destinataire_poste' => 'reception'])->json('data.id');

        $this->actingAs($this->reception)->getJson($this->url())->assertJsonPath('data.0.vise_moi', true)->assertJsonPath('data.0.lue_par_moi', false);

        $this->actingAs($this->reception)->postJson($this->url("/{$id}/lecture"))->assertOk()->assertJsonPath('data.lue_par_moi', true);
        $this->actingAs($this->reception)->postJson($this->url("/{$id}/lecture"))->assertOk();

        $this->assertDatabaseCount('courrier_annotation_lectures', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'courrier.instruction_lue')->count());
        $this->actingAs($this->dg)->getJson($this->url())->assertJsonPath('data.0.lectures.0.user', $this->reception->name);
    }

    public function test_l_auteur_ne_marque_pas_sa_propre_instruction_comme_lue(): void
    {
        $id = $this->noter($this->dg, ['contenu' => 'Ma note', 'type' => 'instruction'])->json('data.id');

        $this->actingAs($this->dg)->postJson($this->url("/{$id}/lecture"))->assertOk();

        $this->assertDatabaseCount('courrier_annotation_lectures', 0);
    }

    public function test_une_instruction_notifie_en_interne_son_destinataire_sans_email(): void
    {
        $this->noter($this->dg, ['contenu' => 'Traiter', 'type' => 'instruction', 'destinataire_poste' => 'reception'])->assertCreated();

        Notification::assertSentTo($this->reception, AnnotationCircuitNotification::class, function ($n) {
            $this->assertSame(['database'], $n->via($this->reception));

            return str_contains($n->toArray($this->reception)['message'], 'Nouvelle instruction');
        });
        Notification::assertNotSentTo($this->dg, AnnotationCircuitNotification::class);
    }

    public function test_une_note_urgente_notifie_sa_direction_cible_mais_pas_les_observations(): void
    {
        $directionCible = Direction::factory()->create();
        $secretariat = User::factory()->secretariatDirection($directionCible)->create();
        $this->courrier->update(['direction_destination_id' => $directionCible->id, 'niveau_confidentialite' => NiveauConfidentialite::ORDINAIRE]);

        $this->noter($this->dg, ['contenu' => 'Observation simple', 'type' => 'observation', 'destinataire_direction_id' => $directionCible->id])->assertCreated();
        Notification::assertNothingSent();

        $this->noter($this->dg, ['contenu' => 'Très urgent', 'type' => 'urgent', 'destinataire_direction_id' => $directionCible->id])->assertCreated();
        Notification::assertSentTo($secretariat, AnnotationCircuitNotification::class);
    }

    public function test_une_note_n_est_ni_visible_ni_notifiee_pour_qui_ne_peut_pas_voir_le_courrier(): void
    {
        $autreDirection = Direction::factory()->create();
        $etranger = User::factory()->responsableDirection($autreDirection)->create();

        $this->noter($this->dg, ['contenu' => 'Confidentiel circuit', 'type' => 'urgent', 'destinataire_direction_id' => $autreDirection->id])->assertCreated();

        Notification::assertNotSentTo($etranger, AnnotationCircuitNotification::class);
        $this->actingAs($etranger)->getJson($this->url())->assertNotFound();
    }

    public function test_un_poste_qui_ne_peut_pas_lire_les_notes_ne_peut_pas_etre_cible(): void
    {
        $this->noter($this->dg, ['contenu' => 'Pour SEC2', 'type' => 'instruction', 'destinataire_poste' => 'secretariat_2'])->assertUnprocessable();
        $this->noter($this->dg, ['contenu' => 'Poste retiré', 'type' => 'instruction', 'destinataire_poste' => 'protocole'])->assertUnprocessable();
    }

    public function test_l_administrateur_ne_lit_pas_et_n_ecrit_pas_dans_les_notes_d_un_courrier_confidentiel(): void
    {
        $admin = User::factory()->administrateur()->create();
        $this->noter($this->dg, ['contenu' => 'Secret défense'])->assertCreated();
        $this->courrier->update(['niveau_confidentialite' => NiveauConfidentialite::CONFIDENTIEL]);

        $this->actingAs($admin)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.contenu', null)
            ->assertJsonPath('data.0.contenu_masque', true);
        $this->noter($admin, ['contenu' => 'Tentative'])->assertForbidden();
    }

    public function test_l_administrateur_lit_les_notes_d_un_courrier_ordinaire(): void
    {
        $admin = User::factory()->administrateur()->create();
        $this->noter($this->dg, ['contenu' => 'Visible'])->assertCreated();

        $this->actingAs($admin)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.contenu', 'Visible')
            ->assertJsonPath('data.0.contenu_masque', false);
    }

    public function test_les_instructions_non_lues_alimentent_la_liste_et_le_compteur_de_navigation(): void
    {
        $id = $this->noter($this->dg, ['contenu' => 'A lire', 'type' => 'instruction', 'destinataire_poste' => 'reception'])->json('data.id');
        $this->noter($this->dg, ['contenu' => 'Pour un autre poste', 'type' => 'instruction', 'destinataire_poste' => 'secretariat_1'])->assertCreated();

        $this->actingAs($this->reception)->getJson('/api/v1/annotations/instructions-non-lues')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.courrier_objet', $this->courrier->objet);
        $this->actingAs($this->reception)->getJson('/api/v1/notifications/compteurs')->assertOk()
            ->assertJsonPath('instructions_non_lues.count', 1);

        $this->actingAs($this->reception)->postJson($this->url("/{$id}/lecture"))->assertOk();

        $this->actingAs($this->reception)->getJson('/api/v1/notifications/compteurs')->assertJsonPath('instructions_non_lues.count', 0);
    }

    public function test_les_impressions_reproduisent_les_notes_du_circuit_avec_leur_type_sans_les_rappels_personnels(): void
    {
        $this->noter($this->dg, ['contenu' => 'Instruction imprimée', 'type' => 'instruction', 'destinataire_poste' => 'reception'])->assertCreated();
        $this->noter($this->reception, ['contenu' => 'Rappel secret', 'type' => 'rappel'])->assertCreated();

        $notes = app(CourrierAnnotationService::class)->notesImprimables($this->courrier);
        $this->assertCount(1, $notes);

        $html = view('courrier::partials.notes-circuit', ['notes' => $notes, 'masquees' => false])->render();
        $this->assertStringContainsString('Instruction', $html);
        $this->assertStringContainsString('Instruction imprimée', $html);
        $this->assertStringNotContainsString('Rappel secret', $html);

        $masque = view('courrier::partials.notes-circuit', ['notes' => collect(), 'masquees' => true])->render();
        $this->assertStringContainsString('courrier confidentiel', $masque);

        $this->actingAs($this->dg)->get("/api/v1/courriers/{$this->courrier->id}/imprimer")->assertOk();
    }

    public function test_les_notes_corrigees_restent_dans_l_historique_marquees_comme_corrigees(): void
    {
        $id = $this->noter($this->dg, ['contenu' => 'Avant'])->json('data.id');
        $this->actingAs($this->dg)->postJson($this->url("/{$id}/correction"), ['contenu' => 'Après'])->assertCreated();

        $liste = $this->actingAs($this->dg)->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');
        // Du plus récent au plus ancien : la correction d'abord, puis la note d'origine marquée comme corrigée.
        $this->assertNull($liste->json('data.0.corrigee_par_id'));
        $this->assertSame($liste->json('data.0.id'), $liste->json('data.1.corrigee_par_id'));
        $this->assertSame(2, CourrierAnnotation::query()->where('courrier_id', $this->courrier->id)->count());
    }
}
