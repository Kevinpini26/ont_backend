<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class ReponseCourrierPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_seul_le_pdf_de_la_reponse_sortante_envoyee_est_communicable(): void
    {
        Storage::fake('local');
        [$a1, $d1, $contenuD1] = $this->creerReponseCommunicable('dossier-1');
        [$a2, $d2] = $this->creerReponseCommunicable('dossier-2');
        $b = Courrier::factory()->create(['dossier_id' => $a1->dossier_id, 'sens' => SensCourrier::ENTRANT]);
        $c = Courrier::factory()->create(['dossier_id' => $a1->dossier_id, 'sens' => SensCourrier::ENTRANT]);

        $urlD1 = $this->urlSignee($d1);
        $telechargement = $this->get($urlD1)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame($d1->pdf_sha256, hash('sha256', $telechargement->streamedContent()));
        $this->assertSame($contenuD1, Storage::disk('local')->get($d1->pdf_chemin));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.reponse_finale_consultee',
            'auditable_id' => $d1->id,
        ]);

        foreach ([$a1, $b, $c] as $documentInterneOuEntrant) {
            $this->get($this->urlSignee($documentInterneOuEntrant))->assertNotFound();
        }

        $urlD1Altere = str_replace("/{$d1->id}/pdf", "/{$d2->id}/pdf", $urlD1);
        $this->get($urlD1Altere)->assertForbidden();
        $this->get($this->urlSignee($d2))->assertOk();
        $this->assertNotSame($a1->dossier_id, $a2->dossier_id);
    }

    public function test_un_lien_expire_ou_une_reponse_non_envoyee_sont_refuses(): void
    {
        Storage::fake('local');
        [, $d] = $this->creerReponseCommunicable('expiration');
        $url = URL::temporarySignedRoute('api.public.reponses.telecharger', now()->addMinute(), ['courrier' => $d->id]);

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        $d->transitions()->delete();
        $this->get($this->urlSignee($d))->assertNotFound();
    }

    public function test_un_pdf_absent_ou_corrompu_nest_jamais_servi(): void
    {
        Storage::fake('local');
        [, $absent] = $this->creerReponseCommunicable('absent');
        Storage::disk('local')->delete($absent->pdf_chemin);
        $this->get($this->urlSignee($absent))->assertNotFound();

        [, $corrompu] = $this->creerReponseCommunicable('corrompu');
        Storage::disk('local')->put($corrompu->pdf_chemin, 'contenu modifié après signature');
        $this->get($this->urlSignee($corrompu))->assertStatus(409);
    }

    /** @return array{Courrier, Courrier, string} */
    private function creerReponseCommunicable(string $suffixe): array
    {
        $signataire = User::factory()->create();
        $origine = Courrier::factory()->create([
            'sens' => SensCourrier::ENTRANT,
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'expediteur_externe_nom' => 'Demandeur '.$suffixe,
            'expediteur_externe_email' => $suffixe.'@example.test',
        ]);
        $contenu = '%PDF-1.4 réponse officielle '.$suffixe;
        $chemin = "courriers-signes/reponse-{$suffixe}.pdf";
        Storage::disk('local')->put($chemin, $contenu);
        $reponse = Courrier::factory()->create([
            'dossier_id' => $origine->dossier_id,
            'sens' => SensCourrier::SORTANT,
            'statut' => CourrierStatut::ENVOYE,
            'en_reponse_a_courrier_id' => $origine->id,
            'destinataire_externe_nom' => $origine->expediteur_externe_nom,
            'destinataire_externe_email' => $origine->expediteur_externe_email,
            'numero_depart' => 'DEP-'.$suffixe,
            'signataire_id' => $signataire->id,
            'signe_at' => now()->subMinute(),
            'date_envoi' => now(),
            'pdf_chemin' => $chemin,
            'pdf_sha256' => hash('sha256', $contenu),
        ]);
        $reponse->transitions()->create([
            'statut' => CourrierStatut::ENVOYE,
            'ancien_statut' => CourrierStatut::SIGNE,
            'nouveau_statut' => CourrierStatut::ENVOYE,
            'created_at' => now(),
        ]);

        return [$origine, $reponse, $contenu];
    }

    private function urlSignee(Courrier $courrier): string
    {
        return URL::temporarySignedRoute(
            'api.public.reponses.telecharger',
            now()->addHour(),
            ['courrier' => $courrier->id],
        );
    }
}
