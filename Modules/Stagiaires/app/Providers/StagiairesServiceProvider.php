<?php

namespace Modules\Stagiaires\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Stagiaires\Console\VerifierEcheancesStageCommand;
use Modules\Stagiaires\Contracts\AffectationRules;
use Modules\Stagiaires\Contracts\AttestationGenerator;
use Modules\Stagiaires\Contracts\BadgeStagiairePdfGenerator;
use Modules\Stagiaires\Contracts\CalculateurNoteFinale;
use Modules\Stagiaires\Contracts\CertificatGenerator;
use Modules\Stagiaires\Contracts\ConventionGenerator;
use Modules\Stagiaires\Contracts\DoublonDetector;
use Modules\Stagiaires\Contracts\EngagementConfidentialiteGenerator;
use Modules\Stagiaires\Contracts\NoteAffectationGenerator;
use Modules\Stagiaires\Contracts\SequenceGenerator;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Policies\StagiairePolicy;
use Modules\Stagiaires\Support\ActiveDirectionsAffectationRules;
use Modules\Stagiaires\Support\DatabaseSequenceGenerator;
use Modules\Stagiaires\Support\DompdfAttestationGenerator;
use Modules\Stagiaires\Support\DompdfBadgeStagiairePdfGenerator;
use Modules\Stagiaires\Support\DompdfCertificatGenerator;
use Modules\Stagiaires\Support\DompdfConventionGenerator;
use Modules\Stagiaires\Support\DompdfEngagementConfidentialiteGenerator;
use Modules\Stagiaires\Support\DompdfNoteAffectationGenerator;
use Modules\Stagiaires\Support\MoyenneCalculateurNoteFinale;
use Modules\Stagiaires\Support\SimilarTextDoublonDetector;
use Nwidart\Modules\Support\ModuleServiceProvider;

class StagiairesServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Stagiaires';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'stagiaires';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        VerifierEcheancesStageCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(AffectationRules::class, ActiveDirectionsAffectationRules::class);
        $this->app->bind(CalculateurNoteFinale::class, MoyenneCalculateurNoteFinale::class);
        $this->app->bind(AttestationGenerator::class, DompdfAttestationGenerator::class);
        $this->app->bind(ConventionGenerator::class, DompdfConventionGenerator::class);
        $this->app->bind(NoteAffectationGenerator::class, DompdfNoteAffectationGenerator::class);
        $this->app->bind(CertificatGenerator::class, DompdfCertificatGenerator::class);
        $this->app->bind(BadgeStagiairePdfGenerator::class, DompdfBadgeStagiairePdfGenerator::class);
        $this->app->bind(EngagementConfidentialiteGenerator::class, DompdfEngagementConfidentialiteGenerator::class);
        $this->app->bind(DoublonDetector::class, SimilarTextDoublonDetector::class);
        $this->app->bind(SequenceGenerator::class, DatabaseSequenceGenerator::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Stagiaire::class, StagiairePolicy::class);
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(VerifierEcheancesStageCommand::class)->dailyAt('07:00');
    }
}
