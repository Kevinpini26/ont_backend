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
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;

class ConcurrenceSignatureDgPostgresTest extends CourrierTestCase
{
    use DatabaseMigrations;

    public function test_deux_processus_ne_signent_quune_fois_le_meme_d(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $d = Courrier::factory()->create([
            'dossier_id' => $a->dossier_id,
            'sens' => 'sortant', 'statut' => CourrierStatut::PROJET_A_VALIDER,
            'en_reponse_a_courrier_id' => $a->id,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'relecteur_id' => $relecteur->id, 'relecture_validee_at' => now(),
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ]);
        MissionDocumentaire::query()->create([
            'courrier_id' => $a->id, 'dossier_id' => $a->dossier_id,
            'demandeur_id' => $dg->id, 'demandeur_poste' => Poste::DG,
            'autorite_poste' => Poste::DG, 'assistant_id' => $assistant->id,
            'instruction' => 'Préparer D', 'type' => MissionDocumentaireType::PREPARATION_REPONSE,
            'projet_courrier_id' => $d->id, 'statut' => MissionDocumentaireStatut::RETOURNEE,
            'envoyee_at' => now(), 'retournee_at' => now(),
        ]);
        $this->marquerDecharge($d);
        $barriere = sys_get_temp_dir().'/ont-signature-dg-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $processus = [];
        try {
            foreach ([1, 2] as $numero) {
                $processus[] = new Process([
                    PHP_BINARY, base_path('Modules/Courrier/tests/Support/signature_dg_worker.php'),
                    (string) $d->id, (string) $dg->id, $barriere, (string) $numero,
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
            $resultats = collect([1, 2])->map(fn ($numero) => json_decode((string) file_get_contents($barriere.'/result-'.$numero.'.json'), true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame(['ok', 'refused'], $resultats->pluck('status')->sort()->values()->all(), $resultats->toJson());
            $d->refresh();
            $this->assertSame(CourrierStatut::SIGNE, $d->statut);
            $this->assertNotNull($d->numero_depart);
            $this->assertNotNull($d->signe_at);
            $this->assertSame($dg->id, $d->signataire_id);
            $this->assertNotNull($d->pdf_chemin);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($d->pdf_chemin)), $d->pdf_sha256);
            $this->assertSame(1, $d->transitions()->where('statut', CourrierStatut::SIGNE)->count());
            $this->assertSame(1, $d->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
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
            if ($d->fresh()?->pdf_chemin) {
                Storage::disk('local')->delete($d->fresh()->pdf_chemin);
            }
        }
    }

    public function test_signature_et_revocation_concurrentes_respectent_lordre_des_verrous(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection($direction)->create();
        $delegation = DelegationPoste::query()->create([
            'poste' => Poste::DG, 'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(),
            'motif' => 'Intérim DG', 'cree_par_id' => $admin->id,
        ]);
        $d = Courrier::factory()->create([
            'sens' => 'sortant', 'statut' => CourrierStatut::PROJET_A_VALIDER,
            'direction_destination_id' => $direction->id,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'relecture_validee_at' => now(),
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ]);
        $this->marquerDecharge($d);
        $barriere = sys_get_temp_dir().'/ont-revocation-dg-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $processus = [
            new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/signature_dg_worker.php'),
                (string) $d->id, (string) $delegataire->id, $barriere, '1'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/revocation_dg_worker.php'),
                (string) $delegation->id, (string) $admin->id, $barriere, '2'], base_path(), ['APP_ENV' => 'testing']),
        ];
        try {
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
            $signature = json_decode((string) file_get_contents($barriere.'/result-1.json'), true, flags: JSON_THROW_ON_ERROR);
            $revocation = json_decode((string) file_get_contents($barriere.'/result-2.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $revocation['status']);
            $this->assertContains($signature['status'], ['ok', 'refused']);
            $this->assertNotNull($delegation->fresh()->revoquee_at);
            $d->refresh();
            if ($signature['status'] === 'ok') {
                $this->assertSame($delegataire->id, $d->signataire_id);
                $this->assertNotNull($d->numero_depart);
                $this->assertNotNull($d->pdf_chemin);
                $this->assertNotNull($d->pdf_sha256);
                $this->assertSame(1, $d->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
            } else {
                $this->assertNull($d->numero_depart);
                $this->assertNull($d->signe_at);
                $this->assertNull($d->pdf_chemin);
                $this->assertNull($d->pdf_sha256);
                $this->assertSame(0, $d->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
            }
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
            if ($d->fresh()?->pdf_chemin) {
                Storage::disk('local')->delete($d->fresh()->pdf_chemin);
            }
        }
    }
}
