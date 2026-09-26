<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

/**
 * Ce process PHPUnit unique ne peut pas faire s'exécuter deux vraies
 * requêtes en parallèle sur deux connexions distinctes (même contrainte que
 * QuotaAffectationTest, PresenceEtDocumentTest : ce projet ne s'appuie sur
 * aucun outillage multi-connexion pour ses tests). Le scénario réaliste
 * qu'on peut reproduire fidèlement, sans threading, est celui-ci : deux
 * requêtes HTTP concurrentes chargent chacune leur propre copie du
 * courrier avant qu'aucune n'ait agi — donc deux instances Eloquent
 * distinctes, toutes deux "périmées" dès que la première écrit. Chaque
 * test ci-dessous réutilise volontairement la MÊME instance $courrier pour
 * les deux appels (jamais réassignée avec le retour du premier appel) :
 * exactement la situation d'une deuxième requête qui n'a pas encore vu le
 * résultat de la première. Ce qui est testé, c'est que
 * CourrierCircuitService::lockCourrierFrais() ignore cet état périmé et
 * revalide contre l'état réel en base — pas le blocage bas niveau du
 * verrou Postgres lui-même (déjà le mécanisme standard, non ré-implémenté
 * ici).
 */
class ConcurrenceCircuitCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function service(): CourrierCircuitService
    {
        return app(CourrierCircuitService::class);
    }

    public function test_une_seule_de_deux_transmissions_au_tri_concurrentes_aboutit(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        $this->marquerDecharge($courrier);

        $resultat = $this->service()->transmettreTri($courrier, $secretariat1);
        $this->assertSame(CourrierStatut::EN_ATTENTE_TRI, $resultat->statut);

        // $courrier n'a jamais été réassigné : il représente toujours l'état
        // "recu" vu par une deuxième requête chargée avant la première.
        $this->expectException(TransitionNonAutoriseeException::class);
        $this->service()->transmettreTri($courrier, $secretariat1);
    }

    public function test_deux_avis_dg_concurrents_sur_le_meme_courrier_le_second_echoue(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->demandeStage()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->service()->rendreAvisDg($courrier, $dg, AvisDg::FAVORABLE, null);

        $this->expectException(TransitionNonAutoriseeException::class);
        $this->service()->rendreAvisDg($courrier, $dg, AvisDg::FAVORABLE, null);
    }

    public function test_deux_accuses_de_reception_concurrents_le_second_echoue(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        // Bordereau non acquitté (accuse_reception_at nul) : un courrier
        // créé directement par factory n'en a aucun par défaut — voir
        // marquerDecharge(), qui en crée un déjà acquitté. Ici on veut
        // justement l'inverse : un bordereau "en transit" à accuser.
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'destinataire_poste' => Poste::SECRETARIAT_1,
            'accuse_reception_at' => null,
            'created_at' => now(),
        ]);

        $this->service()->accuserReception($courrier, $secretariat1);

        $this->expectExceptionObject(TransitionNonAutoriseeException::dechargeDejaDonnee());
        $this->service()->accuserReception($courrier, $secretariat1);
    }
}
