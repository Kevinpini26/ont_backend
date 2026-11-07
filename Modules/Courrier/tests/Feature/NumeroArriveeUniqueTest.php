<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 6, point 1 : chaque courrier entrant porte un seul numéro d'arrivée chronologique (format 2026-0007),
 * sur une seule séquence annuelle, quel que soit le canal. Les anciens numéros restent valables.
 */
class NumeroArriveeUniqueTest extends CourrierTestCase
{
    use RefreshDatabase;

    private Direction $direction;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Storage::fake('local');
        $this->direction = Direction::factory()->create();
        $this->reception = $this->agent(Poste::RECEPTION, $this->direction);
    }

    private function deposerEnLigne(string $objet = 'Proposition de partenariat'): Courrier
    {
        $this->post('/api/v1/public/courriers-externes', [
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
            'expediteur_externe_email' => 'contact@agence-exemple.cd',
            'objet' => $objet,
            'piece_jointe' => UploadedFile::fake()->create('courrier.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Courrier::query()->withoutGlobalScopes()->where('objet', $objet)->firstOrFail();
    }

    private function deposerAuGuichet(string $objet): Courrier
    {
        $this->actingAs($this->reception)->post('/api/v1/courriers', [
            'objet' => $objet,
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'mode_reception' => 'porteur',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Courrier::query()->withoutGlobalScopes()->where('objet', $objet)->firstOrFail();
    }

    private function verifier(Courrier $courrier): void
    {
        $this->actingAs($this->reception)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => $courrier->classificationAttendue()->value,
            'accuse_reception_partenaire' => 'Dépôt reçu',
        ])->assertOk();
    }

    public function test_le_depot_en_ligne_recoit_son_numero_darrivee_des_le_depot_sans_accuse_separe(): void
    {
        $reponse = $this->post('/api/v1/public/courriers-externes', [
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
            'expediteur_externe_email' => 'contact@agence-exemple.cd',
            'objet' => 'Dépôt en ligne',
            'piece_jointe' => UploadedFile::fake()->create('courrier.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $courrier = Courrier::query()->withoutGlobalScopes()->where('objet', 'Dépôt en ligne')->firstOrFail();
        $this->assertMatchesRegularExpression('/^'.now()->year.'-\d{4,}$/', (string) $courrier->numero_enregistrement);
        $this->assertNull($courrier->numero_accuse_reception);
        $this->assertNull($courrier->enregistre_at, 'La Réception n’a pas encore vérifié le dossier.');
        $this->assertSame($courrier->numero_enregistrement, $reponse->json('numero'));
    }

    public function test_la_verification_par_la_reception_ne_cree_jamais_de_second_numero(): void
    {
        $courrier = $this->deposerEnLigne();
        $numero = $courrier->numero_enregistrement;

        $this->verifier($courrier);

        $courrier->refresh();
        $this->assertSame($numero, $courrier->numero_enregistrement);
        $this->assertNotNull($courrier->enregistre_at);
        $this->actingAs($this->reception)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => $courrier->classificationAttendue()->value,
            'accuse_reception_partenaire' => 'Dépôt reçu',
        ])->assertUnprocessable();
    }

    public function test_guichet_en_ligne_et_document_interne_partagent_une_seule_sequence_chronologique(): void
    {
        $a = $this->deposerAuGuichet('Premier au guichet');
        $b = $this->deposerEnLigne('Deuxième en ligne');
        $c = $this->deposerAuGuichet('Troisième au guichet');

        $sequence = fn (Courrier $courrier) => (int) explode('-', (string) $courrier->numero_enregistrement)[1];
        $this->assertSame([$sequence($a) + 1, $sequence($a) + 2], [$sequence($b), $sequence($c)]);
    }

    public function test_un_ancien_depot_en_ligne_garde_son_accuse_et_recoit_son_numero_a_la_verification(): void
    {
        $ancien = Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-2026-000042', 'numero_enregistrement' => null, 'enregistre_at' => null,
            'mode_reception' => 'depot_en_ligne', 'statut' => CourrierStatut::RECU, 'expediteur_externe_nom' => 'Ancien déposant',
        ]);

        $this->verifier($ancien);

        $ancien->refresh();
        $this->assertSame('AR-2026-000042', $ancien->numero_accuse_reception);
        $this->assertMatchesRegularExpression('/^'.now()->year.'-\d{4,}$/', (string) $ancien->numero_enregistrement);
    }

    public function test_le_suivi_public_accepte_le_nouveau_numero_et_lancien_accuse(): void
    {
        $nouveau = $this->deposerEnLigne();
        Courrier::factory()->create(['numero_accuse_reception' => 'AR-2026-000042', 'expediteur_externe_nom' => 'Ancien déposant']);

        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $nouveau->numero_enregistrement, 'nom' => 'contact@agence-exemple.cd'])
            ->assertOk()->assertJsonPath('data.numero', $nouveau->numero_enregistrement);
        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => 'AR-2026-000042', 'nom' => 'Ancien déposant'])
            ->assertOk()->assertJsonPath('data.numero', 'AR-2026-000042');
    }

    public function test_un_depot_non_recevable_garde_son_numero_qui_nest_jamais_reutilise(): void
    {
        $courrier = $this->deposerEnLigne('Dépôt hors sujet');

        $this->actingAs($this->reception)->postJson("/api/v1/courriers/{$courrier->id}/non-recevable", [])->assertUnprocessable();
        $this->actingAs($this->agent(Poste::SECRETARIAT_1, $this->direction))
            ->postJson("/api/v1/courriers/{$courrier->id}/non-recevable", ['motif' => 'Hors du champ de compétence'])->assertForbidden();
        $this->actingAs($this->reception)
            ->postJson("/api/v1/courriers/{$courrier->id}/non-recevable", ['motif' => 'Hors du champ de compétence de l’ONT'])
            ->assertOk()->assertJsonPath('data.non_recevable', true);

        $courrier->refresh();
        $this->assertNotNull($courrier->numero_enregistrement);
        $this->assertSame('Hors du champ de compétence de l’ONT', $courrier->non_recevable_motif);
        $suivant = $this->deposerEnLigne('Dépôt suivant');
        $this->assertGreaterThan(
            (int) explode('-', $courrier->numero_enregistrement)[1],
            (int) explode('-', (string) $suivant->numero_enregistrement)[1],
        );
        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $courrier->numero_enregistrement, 'nom' => 'contact@agence-exemple.cd'])
            ->assertOk()->assertJsonPath('data.statut_simplifie', 'Non recevable');
    }

    /** @return array<string, mixed> */
    private ?object $capturePdf = null;

    private function registre(array $parametres = []): array
    {
        // Une seule capture par test : le générateur du registre garde le service PDF reçu au premier appel.
        $capture = $this->capturePdf ??= new class implements PdfGenerationService
        {
            public array $donnees = [];

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->donnees = $donnees + ['html' => view($vue, $donnees)->render()];

                return '%PDF-1.4';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);
        $capture->donnees = [];
        $requete = http_build_query(['debut' => now()->subDay()->toDateString(), 'fin' => now()->addDay()->toDateString()] + $parametres);
        $this->actingAs($this->agent(Poste::DG, $this->direction))->get('/api/v1/courriers/registre?'.$requete)->assertOk();

        return $capture->donnees;
    }

    public function test_le_registre_darrivee_liste_tous_les_entrants_annules_et_non_recevables_compris(): void
    {
        $enLigne = $this->deposerEnLigne('En ligne pas encore vérifié');
        $guichet = $this->deposerAuGuichet('Au guichet');
        $annule = $this->deposerAuGuichet('Doublon annulé');
        $this->actingAs($this->agent(Poste::DG, $this->direction))
            ->postJson("/api/v1/courriers/{$annule->id}/annuler", ['motif' => 'Doublon du courrier précédent'])->assertOk();
        $nonRecevable = $this->deposerEnLigne('Non recevable');
        $this->actingAs($this->reception)->postJson("/api/v1/courriers/{$nonRecevable->id}/non-recevable", ['motif' => 'Hors compétence'])->assertOk();
        Courrier::factory()->create(['type' => CourrierType::TABLEAU_REPARTITION, 'numero_enregistrement' => null, 'objet' => 'Tableau DFP']);

        $registre = $this->registre();

        $numeros = $registre['courriers']->pluck('numero_enregistrement')->all();
        $this->assertSame([$enLigne->numero_enregistrement, $guichet->numero_enregistrement, $annule->fresh()->numero_enregistrement, $nonRecevable->numero_enregistrement], $numeros);
        $this->assertStringContainsString('Annulé', $registre['html']);
        $this->assertStringContainsString('Non recevable', $registre['html']);
        $this->assertStringNotContainsString('Tableau DFP', $registre['html']);
        $this->assertStringContainsString('Origine', $registre['html']);
    }

    public function test_le_registre_se_filtre_par_origine_et_trie_par_valeur_numerique(): void
    {
        Courrier::factory()->create(['numero_enregistrement' => now()->year.'-10000', 'mode_reception' => 'poste', 'enregistre_at' => now()]);
        Courrier::factory()->create(['numero_enregistrement' => now()->year.'-9999', 'mode_reception' => 'poste', 'enregistre_at' => now()]);
        $enLigne = $this->deposerEnLigne('Filtre en ligne');

        $this->assertSame([now()->year.'-9999', now()->year.'-10000'], $this->registre(['origine' => 'externe'])['courriers']->pluck('numero_enregistrement')->all());
        $this->assertSame([$enLigne->numero_enregistrement], $this->registre(['origine' => 'en_ligne'])['courriers']->pluck('numero_enregistrement')->all());
    }
}
