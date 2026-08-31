<?php

namespace Modules\Kernel\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Kernel\Console\RestaurerBaseDeDonneesCommand;
use Modules\Kernel\Console\SauvegarderBaseDeDonneesCommand;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\DirectionScopeBypassResolver;
use Modules\Kernel\Contracts\NotificationCanal;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Contracts\QrCodeService;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Policies\AuditLogPolicy;
use Modules\Kernel\Policies\DirectionPolicy;
use Modules\Kernel\Policies\UserPolicy;
use Modules\Kernel\Support\BackupDiskProductionGuard;
use Modules\Kernel\Support\DatabaseAuditLogger;
use Modules\Kernel\Support\DefaultDirectionScopeBypassResolver;
use Modules\Kernel\Support\DompdfPdfGenerationService;
use Modules\Kernel\Support\EndroidQrCodeService;
use Modules\Kernel\Support\LaravelNotificationService;
use Modules\Kernel\Support\SmsNotificationCanal;
use Nwidart\Modules\Support\ModuleServiceProvider;

class KernelServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Kernel';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'kernel';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SauvegarderBaseDeDonneesCommand::class,
        RestaurerBaseDeDonneesCommand::class,
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

        $this->app->bind(DirectionScopeBypassResolver::class, DefaultDirectionScopeBypassResolver::class);
        $this->app->bind(AuditLogger::class, DatabaseAuditLogger::class);
        $this->app->bind(PdfGenerationService::class, DompdfPdfGenerationService::class);
        $this->app->bind(QrCodeService::class, EndroidQrCodeService::class);
        $this->app->bind(NotificationCanal::class, SmsNotificationCanal::class);
        $this->app->bind(NotificationService::class, LaravelNotificationService::class);
    }

    public function boot(): void
    {
        parent::boot();

        BackupDiskProductionGuard::verifier();

        Gate::policy(Direction::class, DirectionPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        // Pas de modèle "Rapport" à associer à une Policy classique : Gate
        // ad hoc, réservée à l'administrateur et à la DFP (seule direction
        // à produire elle-même ce rapport pour la tutelle).
        Gate::define('genererRapportTutelle', fn (User $user) => in_array(
            $user->role,
            [UserRole::ADMINISTRATEUR, UserRole::AGENT_DFP],
            true,
        ));
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(SauvegarderBaseDeDonneesCommand::class)->dailyAt('03:00');
    }
}
