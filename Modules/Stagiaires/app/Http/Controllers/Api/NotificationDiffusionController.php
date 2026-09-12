<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Stagiaires\Http\Resources\NotificationDiffusionResource;
use Modules\Stagiaires\Models\NotificationDiffusion;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Services\StagiaireCircuitService;

/**
 * Lot B : la DFP doit pouvoir répondre à un stagiaire qui affirme ne pas
 * avoir été prévenu — l'historique complet des envois (retenu/non retenu),
 * et la possibilité de renvoyer.
 */
class NotificationDiffusionController extends Controller
{
    public function __construct(private readonly StagiaireCircuitService $circuit) {}

    public function index(Request $request, Stagiaire $stagiaire)
    {
        $this->authorize('gererDossier', Stagiaire::class);

        return NotificationDiffusionResource::collection(
            $stagiaire->notificationsDiffusion()->with('envoyePar')->get()
        );
    }

    public function renvoyer(Request $request, NotificationDiffusion $notification)
    {
        $this->authorize('gererDossier', Stagiaire::class);

        $nouvelle = $this->circuit->renvoyerNotificationDiffusion($notification, $request->user());

        return new NotificationDiffusionResource($nouvelle->load('envoyePar'));
    }
}
