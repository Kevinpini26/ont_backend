<?php

namespace Modules\Courrier\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Console\AnonymiserCandidaturesNonRetenuesCommand;
use Modules\Courrier\Console\GenererRegistreCourrierCommand;
use Modules\Courrier\Console\RelancerAvisDgEnAttenteCommand;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Contracts\SequenceGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Policies\CourrierPolicy;
use Modules\Courrier\Support\ConfigCircuitTransitionRules;
use Modules\Courrier\Support\DatabaseSequenceGenerator;
use Modules\Courrier\Support\DefaultNumeroGenerator;
use Modules\Courrier\Support\DompdfCourrierPdfGenerator;
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
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Courrier::class, CourrierPolicy::class);
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
