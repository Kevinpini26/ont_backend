<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Http\Resources\AuditLogResource;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\NotificationEmail;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DerniereSauvegardeResolver;

/**
 * Tableau de bord de l'administrateur : uniquement des compteurs
 * techniques (comptes, échecs, sauvegarde) et les dernières actions
 * sensibles — rien sur le contenu des courriers, qui ne relève pas de ce
 * rôle (voir CourrierPolicy::consulterContenu()).
 */
class AdminDashboardController extends Controller
{
    public function __construct(private readonly DerniereSauvegardeResolver $sauvegarde) {}

    public function index()
    {
        return response()->json([
            'comptes_actifs' => User::query()->where('actif', true)->count(),
            'comptes_verrouilles' => User::query()->whereNotNull('verrouille_jusqu_a')->where('verrouille_jusqu_a', '>', now())->count(),
            'emails_en_echec_aujourdhui' => NotificationEmail::query()
                ->where('statut', StatutNotificationEmail::ECHEC)
                ->whereDate('updated_at', today())
                ->count(),
            'jobs_en_echec' => DB::table('failed_jobs')->count(),
            'derniere_sauvegarde' => $this->sauvegarde->resoudre(),
            'dernieres_actions_sensibles' => AuditLogResource::collection(
                AuditLog::query()->with('utilisateur')->latest('created_at')->limit(10)->get()
            ),
        ]);
    }
}
