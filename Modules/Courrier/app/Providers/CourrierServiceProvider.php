<?php

namespace Modules\Courrier\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Console\AnonymiserCandidaturesNonRetenuesCommand;
use Modules\Courrier\Console\GenererRegistreCourrierCommand;
use Modules\Courrier\Console\RelancerAvisDgEnAttenteCommand;
use Modules\Courrier\Contracts\BordereauLotPdfGenerator;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Contracts\FeuilleCouvertureGenerator;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Contracts\SequenceGenerator;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Policies\BordereauLotPolicy;
use Modules\Courrier\Policies\CourrierPolicy;
use Modules\Courrier\Policies\DispatchCourrierPolicy;
use Modules\Courrier\Policies\DocumentProduitDirectionPolicy;
use Modules\Courrier\Policies\MissionDocumentairePolicy;
use Modules\Courrier\Policies\TraitementDirectionPolicy;
use Modules\Courrier\Support\ConfigCircuitTransitionRules;
use Modules\Courrier\Support\DatabaseSequenceGenerator;
use Modules\Courrier\Support\DefaultNumeroGenerator;
use Modules\Courrier\Support\DompdfBordereauLotPdfGenerator;
use Modules\Courrier\Support\DompdfCourrierPdfGenerator;
use Modules\Courrier\Support\DompdfFeuilleCouvertureGenerator;
use Modules\Courrier\Support\DompdfRegistreCourrierPdfGenerator;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CourrierServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Courrier';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'courrier';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        RelancerAvisDgEnAttenteCommand::class,
        AnonymiserCandidaturesNonRetenuesCommand::class,
        GenererRegistreCourrierCommand::class,
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

        $this->app->bind(CircuitTransitionRules::class, ConfigCircuitTransitionRules::class);
        $this->app->bind(SequenceGenerator::class, DatabaseSequenceGenerator::class);
        $this->app->bind(NumeroGenerator::class, DefaultNumeroGenerator::class);
        $this->app->bind(CourrierPdfGenerator::class, DompdfCourrierPdfGenerator::class);
        $this->app->bind(RegistreCourrierPdfGenerator::class, DompdfRegistreCourrierPdfGenerator::class);
        $this->app->bind(FeuilleCouvertureGenerator::class, DompdfFeuilleCouvertureGenerator::class);
        $this->app->bind(BordereauLotPdfGenerator::class, DompdfBordereauLotPdfGenerator::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Courrier::class, CourrierPolicy::class);
        Gate::policy(DispatchCourrier::class, DispatchCourrierPolicy::class);
        Gate::policy(DocumentProduitDirection::class, DocumentProduitDirectionPolicy::class);
        Gate::policy(MissionDocumentaire::class, MissionDocumentairePolicy::class);
        Gate::policy(TraitementDirection::class, TraitementDirectionPolicy::class);
        Gate::policy(BordereauLot::class, BordereauLotPolicy::class);
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(RelancerAvisDgEnAttenteCommand::class)->hourly();
        $schedule->command(AnonymiserCandidaturesNonRetenuesCommand::class)->daily();
    }
}
