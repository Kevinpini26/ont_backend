<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Mail\EmailTestSupervisionMail;
use Modules\Kernel\Models\NotificationEmail;
use Modules\Kernel\Support\DerniereSauvegardeResolver;

/**
 * Page "Santé du système" (lecture seule, hormis les deux actions de
 * relance ci-dessous) : tout ce qu'un administrateur technique doit savoir
 * sans avoir à se connecter au serveur (file d'attente, planificateur,
 * dernière sauvegarde, espace disque, échecs d'envoi d'email).
 */
class SupervisionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DerniereSauvegardeResolver $sauvegarde,
    ) {}

    public function index()
    {
        $dernierTick = Cache::get('supervision.planificateur_dernier_tick');

        return response()->json([
            'file_attente' => [
                'connexion' => config('queue.default'),
                'en_attente' => $this->tableExiste('jobs') ? DB::table('jobs')->count() : null,
                'en_echec' => DB::table('failed_jobs')->count(),
            ],
            'planificateur' => [
                'dernier_tick' => $dernierTick,
                // Une minute de marge sur l'intervalle everyMinute() :
                // au-delà, "cron -> artisan schedule:run" a probablement
                // cessé de tourner.
                'actif' => $dernierTick !== null && now()->diffInMinutes($dernierTick) <= 2,
            ],
            'emails' => [
                'en_file' => NotificationEmail::query()->where('statut', StatutNotificationEmail::EN_FILE)->count(),
                'en_echec' => NotificationEmail::query()->where('statut', StatutNotificationEmail::ECHEC)->count(),
            ],
            'sauvegarde' => $this->sauvegarde->resoudre(),
            'disque' => $this->espaceDisque(),
        ]);
    }

    public function jobsEnEchec()
    {
        return response()->json([
            'data' => DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get()->map(fn ($job) => [
                'id' => $job->uuid,
                'connexion' => $job->connection,
                'file' => $job->queue,
                'exception' => mb_substr((string) $job->exception, 0, 500),
                'failed_at' => $job->failed_at,
            ]),
        ]);
    }

    public function relancerJobEnEchec(string $uuid)
    {
        $existe = DB::table('failed_jobs')->where('uuid', $uuid)->exists();
        abort_unless($existe, 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        $this->audit->enregistrer('supervision.job_relance', null, request()->user(), ['uuid' => $uuid]);

        return response()->json(['message' => 'Job remis en file.']);
    }

    public function emailsEnEchec()
    {
        return response()->json([
            'data' => NotificationEmail::query()
                ->where('statut', StatutNotificationEmail::ECHEC)
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn (NotificationEmail $n) => [
                    'id' => $n->id,
                    'type' => $n->type,
                    'destinataire' => $n->destinataire,
                    'tentatives' => $n->tentatives,
                    'derniere_erreur' => $n->derniere_erreur,
                    'renvoi_possible' => $n->job_uuid !== null,
                    'updated_at' => $n->updated_at,
                ]),
        ]);
    }

    public function renvoyerEmailEnEchec(NotificationEmail $notification)
    {
        if ($notification->statut !== StatutNotificationEmail::ECHEC) {
            throw ValidationException::withMessages(['notification' => ['Cette notification n\'est pas en échec.']]);
        }

        if ($notification->job_uuid === null || ! DB::table('failed_jobs')->where('uuid', $notification->job_uuid)->exists()) {
            throw ValidationException::withMessages(['notification' => ['Le job d\'origine n\'est plus disponible pour relance (voir la rétention de failed_jobs).']]);
        }

        Artisan::call('queue:retry', ['id' => [$notification->job_uuid]]);
        $notification->update(['statut' => StatutNotificationEmail::EN_FILE]);

        $this->audit->enregistrer('supervision.email_renvoye', $notification, request()->user());

        return response()->json(['message' => 'Email remis en file.']);
    }

    /**
     * Preuve de bon fonctionnement de bout en bout (SMTP, file, worker) :
     * passe par le même mécanisme que tout autre email du projet (voir
     * LaravelNotificationService), donc visible dans ce même tableau de
     * supervision si jamais il échoue lui aussi.
     */
    public function envoyerEmailTest(Request $request)
    {
        $admin = $request->user();

        $this->notifications->envoyerMail($admin->email, new EmailTestSupervisionMail, 'supervision.test', $admin);

        $this->audit->enregistrer('supervision.email_test_envoye', null, $admin, ['destinataire' => $admin->email]);

        return response()->json(['message' => "Email de test mis en file vers {$admin->email}."]);
    }

    private function tableExiste(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function espaceDisque(): array
    {
        $chemin = storage_path();

        return [
            'libre_octets' => disk_free_space($chemin),
            'total_octets' => disk_total_space($chemin),
        ];
    }
}
