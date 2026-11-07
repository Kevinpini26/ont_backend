<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Jobs\EnvoyerNotificationEmailJob;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/** Lot 6, point 2 : tout dépôt au guichet donne un récépissé imprimable, et le déposant suit son dossier en ligne. */
class RecepisseGuichetTest extends CourrierTestCase
{
    use RefreshDatabase;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->reception = $this->agent(Poste::RECEPTION, Direction::factory()->create());
    }

    private function deposerAuGuichet(array $donnees = []): Courrier
    {
        $this->actingAs($this->reception)->post('/api/v1/courriers', $donnees + [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Agence Horizon',
            'mode_reception' => 'porteur',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'pieces_jointes' => [UploadedFile::fake()->create('annexe.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        return Courrier::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_le_recepisse_porte_tout_ce_dont_le_deposant_a_besoin(): void
    {
        $courrier = $this->deposerAuGuichet();
        $capture = new class implements PdfGenerationService
        {
            public string $html = '';

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->html = view($vue, $donnees)->render();

                return '%PDF-1.4';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);

        $this->actingAs($this->reception)->get("/api/v1/courriers/{$courrier->id}/accuse-reception-guichet")->assertOk();

        $html = $capture->html;
        $this->assertStringContainsString('Récépissé de dépôt', $html);
        $this->assertStringContainsString($courrier->numero_enregistrement, $html);
        $this->assertStringContainsString($courrier->created_at->setTimezone('Africa/Kinshasa')->format('H:i'), $html);
        $this->assertStringContainsString('Demande de partenariat', $html);
        $this->assertStringContainsString('Agence Horizon', $html);
        $this->assertStringContainsString('Nombre de pièces', $html);
        $this->assertMatchesRegularExpression('/Nombre de pièces<\/td>\s*<td>2</', $html);
        $this->assertStringContainsString('/suivi-dossier', $html);
        $this->assertStringContainsString('Suivez votre dossier en ligne', $html);
    }

    public function test_un_email_fourni_au_guichet_recoit_le_meme_accuse_avec_le_lien_de_suivi(): void
    {
        $courrier = $this->deposerAuGuichet(['expediteur_externe_email' => 'agence@horizon.test']);

        $job = Queue::pushed(EnvoyerNotificationEmailJob::class)->first(fn ($job) => $job->mailable instanceof AccuseReceptionCourrierExterneMail);
        $this->assertNotNull($job);
        $this->assertSame('agence@horizon.test', $job->email);
        $rendu = $job->mailable->render();
        $this->assertStringContainsString($courrier->numero_enregistrement, $rendu);
        $this->assertStringContainsString('/suivi-dossier?numero='.urlencode($courrier->numero_enregistrement), $rendu);
    }

    public function test_le_deposant_au_guichet_suit_son_dossier_avec_son_numero_et_son_nom_ou_son_email(): void
    {
        $courrier = $this->deposerAuGuichet(['expediteur_externe_email' => 'agence@horizon.test']);

        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $courrier->numero_enregistrement, 'nom' => 'Agence Horizon'])->assertOk();
        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $courrier->numero_enregistrement, 'nom' => 'agence@horizon.test'])->assertOk();
        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $courrier->numero_enregistrement, 'nom' => 'Quelqu’un d’autre'])->assertStatus(404);
    }

    public function test_la_feuille_de_couverture_porte_le_numero_darrivee_meme_sans_ancien_accuse(): void
    {
        $courrier = $this->deposerAuGuichet();
        $this->assertNull($courrier->numero_accuse_reception);
        $capture = new class implements PdfGenerationService
        {
            public string $html = '';

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->html = view($vue, $donnees)->render();

                return '%PDF-1.4';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);

        $this->actingAs($this->reception)->get("/api/v1/courriers/{$courrier->id}/feuille-couverture")->assertOk();

        $this->assertStringContainsString($courrier->numero_enregistrement, $capture->html);
    }
}
