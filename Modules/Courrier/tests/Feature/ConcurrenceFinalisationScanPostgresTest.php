<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Symfony\Component\Process\Process;

class ConcurrenceFinalisationScanPostgresTest extends CourrierTestCase
{
    use DatabaseMigrations;

    public function test_deux_processus_ne_finalisent_quune_fois_et_ne_creent_quune_transmission_sec2(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);
        $source = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $courrier = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'en_reponse_a_courrier_id' => $source->id,
            'created_by' => $assistant->id,
            'sens' => 'sortant',
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'destinataire_externe_nom' => 'Partenaire officiel',
            'relecteur_id' => $relecteur->id,
            'relecture_validee_at' => now(),
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ]);
        MissionDocumentaire::query()->create([
            'courrier_id' => $source->id,
            'dossier_id' => $source->dossier_id,
            'demandeur_id' => $dg->id,
            'demandeur_poste' => Poste::DG,
            'autorite_poste' => Poste::DG,
            'assistant_id' => $assistant->id,
            'instruction' => 'Préparer D',
            'type' => MissionDocumentaireType::PREPARATION_REPONSE,
            'projet_courrier_id' => $courrier->id,
            'statut' => MissionDocumentaireStatut::RETOURNEE,
            'envoyee_at' => now(),
            'retournee_at' => now(),
        ]);
        $this->marquerDecharge($courrier);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();

        $barriere = sys_get_temp_dir().'/ont-scan-signe-'.bin2hex(random_bytes(6));
        $scanPath = tempnam(sys_get_temp_dir(), 'ont-scan-');
        file_put_contents($scanPath, "%PDF-1.7\nScan signe en concurrence\n%%EOF");
        mkdir($barriere, 0700, true);
        $processus = [];
        try {
            foreach ([1, 2] as $numero) {
                $processus[] = new Process([
                    PHP_BINARY,
                    base_path('Modules/Courrier/tests/Support/signature_dg_worker.php'),
                    (string) $courrier->id,
                    (string) $dg->id,
                    $barriere,
                    (string) $numero,
                    'finaliser-scan',
                    $scanPath,
                ], base_path(), ['APP_ENV' => 'testing']);
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
            $courrier->refresh();
            $this->assertSame(CourrierStatut::SIGNE, $courrier->statut);
            $this->assertSame($dg->id, $courrier->signataire_id);
            $this->assertNotNull($courrier->signe_at);
            $this->assertSame(hash('sha256', file_get_contents($scanPath)), $courrier->pdf_sha256);
            $this->assertSame(1, $courrier->transitions()->where('statut', CourrierStatut::SIGNE)->count());
            $this->assertSame(1, $courrier->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
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
            unlink($scanPath);
            $courrier->refresh();
            Storage::disk('local')->delete(array_filter([$courrier->pdf_chemin, $courrier->pdf_a_signer_chemin]));
        }
    }
}
