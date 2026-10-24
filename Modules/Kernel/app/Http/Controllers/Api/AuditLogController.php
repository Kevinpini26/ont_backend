<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Kernel\Http\Resources\AuditLogResource;
use Modules\Kernel\Models\AuditLog;

/**
 * Les entrées du journal d'audit ne sont ni modifiables ni supprimables :
 * ce contrôleur n'expose intentionnellement que index() (aucune route
 * update/destroy n'existe pour ce modèle, voir routes/api.php).
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with('utilisateur')->latest('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('sujet_type')) {
            $query->where('auditable_type', $request->string('sujet_type'));
        }

        if ($request->filled('sujet_id')) {
            $query->where('auditable_id', $request->integer('sujet_id'));
        }

        if ($request->filled('recherche')) {
            $recherche = $request->string('recherche')->toString();
            $query->where(fn ($q) => $q->where('action', 'ilike', "%{$recherche}%")->orWhere('description', 'ilike', "%{$recherche}%"));
        }

        if ($request->filled('depuis')) {
            $query->whereDate('created_at', '>=', $request->date('depuis'));
        }

        if ($request->filled('jusqu_a')) {
            $query->whereDate('created_at', '<=', $request->date('jusqu_a'));
        }

        $parPage = min(200, max(1, $request->integer('par_page', 50)));

        return AuditLogResource::collection($query->paginate($parPage));
    }
}
