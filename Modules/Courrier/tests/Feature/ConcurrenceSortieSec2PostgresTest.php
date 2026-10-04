<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;

class ConcurrenceSortieSec2PostgresTest extends CourrierTestCase
{
    use DatabaseMigrations;

    public function test_deux_envois_courriel_concurrents_ne_creent_quune_execution(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        [$courrier, $dg, $premier, $second] = $this->courrierSigne();
        Storage::disk('local')->put($courrier->pdf_chemin, '%PDF-1.7 scan final concurrent');
        $courrier->update(['pdf_sha256' => hash('sha256', '%PDF-1.7 scan final concurrent')]);
        $this->marquerDecharge($courrier, Poste::SECRETARIAT_2);
        $this->actingAs($premier)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertOk();

        try {
            $this->concurrence('envoyer-par-courriel', $courrier->id, $premier, $second);
            $courrier->refresh();
            $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
            $this->assertNotNull($courrier->courriel_envoye_at);
            $this->assertContains($courrier->courriel_envoye_par_id, [$premier->id, $second->id]);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'courrier.courriel_envoye')->where('auditable_id', $courrier->id)->count());
            $this->assertSame(1, $courrier->transitions()->where('statut', CourrierStatut::ENVOYE)->count());
        } finally {
            Storage::disk('local')->delete($courrier->pdf_chemin);
        }
    }

    public function test_deux_confirmations_de_remise_concurrentes_ne_remplacent_pas_le_recuperant(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        [$courrier, , $premier, $second] = $this->courrierSigne();
        Storage::disk('local')->put($courrier->pdf_chemin, '%PDF-1.7 scan final remise');
        $courrier->update(['pdf_sha256' => hash('sha256', '%PDF-1.7 scan final remise')]);
        $this->marquerDecharge($courrier, Poste::SECRETARIAT_2);
        $this->actingAs($premier)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE->value,
        ])->assertOk();
        $this->actingAs($premier)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertOk();

        try {
            $this->concurrence('confirmer-remise', $courrier->id, $premier, $second);
            $courrier->refresh();
            $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
            $this->assertNotNull($courrier->remis_le);
            $this->assertSame('Récupérant test', $courrier->remis_a);
            $this->assertContains($courrier->retrait_effectue_par_id, [$premier->id, $second->id]);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'courrier.remise_physique_confirmee')->where('auditable_id', $courrier->id)->count());
            $this->assertSame(1, $courrier->transitions()->where('statut', CourrierStatut::REMIS)->count());
        } finally {
            Storage::disk('local')->delete($courrier->pdf_chemin);
        }
    }

    public function test_deux_decisions_de_classement_concurrentes_ne_creent_quun_cycle(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('ont_testing', config('database.connections.pgsql.database'));
        [$courrier, $dg, $sec2, $autreSec2] = $this->courrierSigne();
        $courrier->update([
            'statut' => CourrierStatut::ENVOYE,
            'mode_sortie' => ModeSortie::COURRIEL,
            'date_envoi' => today(),
            'courriel_envoye_at' => now(),
            'courriel_envoye_par_id' => $sec2->id,
            'courriel_destinataire' => $courrier->destinataire_externe_email,
        ]);
        $champsPreuves = [
            'pdf_chemin', 'pdf_sha256', 'signataire_id', 'signe_at', 'numero_depart',
            'mode_sortie', 'date_envoi', 'courriel_envoye_at', 'courriel_envoye_par_id',
            'courriel_destinataire', 'destinataire_externe_email',
        ];
        $preuvesAvant = array_intersect_key($courrier->fresh()->getRawOriginal(), array_flip($champsPreuves));
        $transitionsAvant = $courrier->transitions()->count();

        try {
            $this->concurrence('decider-classement', $courrier->id, $dg, $dg);
            $courrier->refresh();
            $dispatchs = $courrier->dispatchs()->get();
            $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
            $this->assertTrue($courrier->estSortieCompletee());
            $this->assertCount(1, $dispatchs);
            $dispatch = $dispatchs->sole();
            $this->assertSame('classement', $dispatch->type_destination->value);
            $this->assertSame('en_attente', $dispatch->statut->value);
            $this->assertSame($transitionsAvant, $courrier->transitions()->count());

            $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
            $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Archives concurrence'])->assertOk();
            $courrier->refresh();
            $dispatch->refresh();
            $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
            $this->assertSame('execute', $dispatch->statut->value);
            $this->assertSame(1, DB::table('dispatchs_courrier')->where('courrier_id', $courrier->id)->where('type_destination', 'classement')->count());
            $this->assertSame(1, DB::table('classements_documents')->where('courrier_id', $courrier->id)->count());
            $this->assertSame('classe', DB::table('classements_documents')->where('courrier_id', $courrier->id)->value('statut'));
            $this->assertSame($preuvesAvant, array_intersect_key($courrier->getRawOriginal(), array_flip($champsPreuves)));
            $this->assertSame($transitionsAvant, $courrier->transitions()->count());
            $this->assertNotContains($courrier->statut, [CourrierStatut::EN_DISPATCH, CourrierStatut::DISPATCH_EXECUTE]);
        } finally {
            Storage::disk('local')->delete($courrier->pdf_chemin);
        }
    }

    private function concurrence(string $action, int $courrierId, User $premier, User $second): void
    {
        $barriere = sys_get_temp_dir().'/ont-sortie-sec2-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $processus = [];
        try {
            foreach ([$premier, $second] as $index => $acteur) {
                $processus[] = new Process([
                    PHP_BINARY,
                    base_path('Modules/Courrier/tests/Support/signature_dg_worker.php'),
                    (string) $courrierId,
                    (string) $acteur->id,
                    $barriere,
                    (string) ($index + 1),
                    $action,
                ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'ont_testing']);
            }
            foreach ($processus as $process) {
                $process->setTimeout(30)->start();
            }
            $limite = microtime(true) + 10;
            while ((! file_exists($barriere.'/ready-1') || ! file_exists($barriere.'/ready-2')) && microtime(true) < $limite) {
                usleep(10_000);
            }
            $this->assertFileExists($barriere.'/ready-1');
            $this->assertFileExists($barriere.'/ready-2');
            file_put_contents($barriere.'/start', 'go');
            foreach ($processus as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $resultats = collect([1, 2])->map(fn (int $numero) => json_decode(
                (string) file_get_contents($barriere.'/result-'.$numero.'.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            $this->assertSame(['ok', 'refused'], $resultats->pluck('status')->sort()->values()->all(), $resultats->toJson());
        } finally {
            foreach ($processus as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }

    private function courrierSigne(): array
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $premier = $this->agent(Poste::SECRETARIAT_2, $direction);
        $second = $this->agent(Poste::SECRETARIAT_2, $direction);
        $source = Courrier::factory()->create([
            'sens' => 'entrant',
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'expediteur_externe_nom' => 'Partenaire',
            'expediteur_externe_email' => 'destinataire@example.test',
        ]);
        $courrier = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'sens' => 'sortant',
            'statut' => CourrierStatut::SIGNE,
            'en_reponse_a_courrier_id' => $source->id,
            'destinataire_externe_nom' => 'Partenaire',
            'destinataire_externe_email' => 'destinataire@example.test',
            'numero_depart' => 'CONC-'.bin2hex(random_bytes(4)),
            'signataire_id' => $dg->id,
            'signe_at' => now(),
            'relecture_validee_at' => now(),
            'pdf_chemin' => 'courriers-signes/concurrence.pdf',
            'pdf_sha256' => hash('sha256', '%PDF-1.7 scan final concurrent'),
        ]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'ancien_statut' => CourrierStatut::EN_RELECTURE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'changed_by_id' => $dg->id,
            'destinataire_poste' => Poste::SECRETARIAT_2->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);

        return [$courrier, $dg, $premier, $second];
    }
}
