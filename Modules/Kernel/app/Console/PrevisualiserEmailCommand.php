<?php

namespace Modules\Kernel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Mail\AvisDgEnAttenteMail;
use Modules\Courrier\Mail\CourrierRecuMail;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Mail\StageEcheanceProcheMail;
use Modules\Stagiaires\Mail\StagiaireAffecteMail;
use Modules\Stagiaires\Models\Stagiaire;
use RuntimeException;

/**
 * Outil de développement local uniquement : construit des données fictives
 * (dans une transaction toujours annulée, jamais écrites) pour générer le
 * HTML réel d'un des 7 Mailables du projet, sans passer par un vrai envoi
 * ni par la file d'attente — pour relire visuellement le gabarit commun
 * (voir resources/views/vendor/mail/html/message.blade.php) après une
 * modification, sans attendre un email réel.
 */
class PrevisualiserEmailCommand extends Command
{
    protected $signature = 'mail:previsualiser {mailable? : Clé du mailable à prévisualiser (voir --lister)}
                            {--lister : Liste les clés disponibles}';

    protected $description = "Génère le HTML d'un email du projet à partir de données fictives, pour relecture locale dans un navigateur";

    /** @var array<string, string> */
    private array $mailables = [
        'accuse-reception-candidat' => AccuseReceptionCandidatMail::class,
        'accuse-reception-courrier-externe' => AccuseReceptionCourrierExterneMail::class,
        'avis-dg-en-attente' => AvisDgEnAttenteMail::class,
        'courrier-recu' => CourrierRecuMail::class,
        'reponse-finale-courrier-externe' => ReponseFinaleCourrierExterneMail::class,
        'stage-echeance-proche' => StageEcheanceProcheMail::class,
        'stagiaire-affecte' => StagiaireAffecteMail::class,
    ];

    public function handle(): int
    {
        if ($this->option('lister') || blank($this->argument('mailable'))) {
            $this->table(['Clé'], array_map(fn (string $cle) => [$cle], array_keys($this->mailables)));

            return self::SUCCESS;
        }

        $cle = $this->argument('mailable');
        if (! array_key_exists($cle, $this->mailables)) {
            $this->error("Clé inconnue : {$cle}. Voir --lister pour les clés disponibles.");

            return self::FAILURE;
        }

        $html = null;
        DB::beginTransaction();
        try {
            $html = $this->construire($cle)->render();
        } finally {
            DB::rollBack();
        }

        $chemin = storage_path("app/previsualisations-email/{$cle}.html");
        if (! is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0755, true);
        }
        file_put_contents($chemin, $html);

        $this->info("Prévisualisation générée : {$chemin}");
        $this->line('Ouvrez ce fichier dans un navigateur pour le relire.');

        return self::SUCCESS;
    }

    private function construire(string $cle): object
    {
        return match ($cle) {
            'accuse-reception-candidat' => new AccuseReceptionCandidatMail(
                Courrier::factory()->create(['type' => CourrierType::DEMANDE_STAGE, 'candidat_nom' => 'Jean Kabila']),
            ),
            'accuse-reception-courrier-externe' => new AccuseReceptionCourrierExterneMail(
                Courrier::factory()->create(['expediteur_externe_nom' => 'Entreprise Exemple']),
            ),
            'avis-dg-en-attente' => new AvisDgEnAttenteMail(Courrier::factory()->create()),
            'courrier-recu' => new CourrierRecuMail(Courrier::factory()->create()),
            'reponse-finale-courrier-externe' => new ReponseFinaleCourrierExterneMail(
                Courrier::factory()->create(['expediteur_externe_nom' => 'Partenaire source']),
                Courrier::factory()->create([
                    'numero_depart' => '2026-D000001',
                    'signataire_id' => User::factory()->administrateur()->create()->id,
                    'signe_at' => now(),
                    'date_envoi' => now(),
                    'destinataire_externe_nom' => 'Destinataire officiel',
                ]),
                'https://exemple.test/lien-signe',
            ),
            'stage-echeance-proche' => new StageEcheanceProcheMail(
                Stagiaire::factory()->create(['direction_id' => Direction::factory(), 'date_fin_stage' => now()->addDays(10)]),
            ),
            'stagiaire-affecte' => new StagiaireAffecteMail(Stagiaire::factory()->create(['direction_id' => Direction::factory()])),
            default => throw new RuntimeException("Clé non construite : {$cle}"),
        };
    }
}
