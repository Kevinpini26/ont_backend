<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Http\Requests\StoreDirectionRequest;
use Modules\Kernel\Http\Requests\UpdateDirectionRequest;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Kernel\Models\Direction;

class DirectionController extends Controller
{
    /**
     * Statuts considérés "en cours" pour le garde-fou de update() ci-dessous
     * : tout ce qui n'est pas une fin de circuit (enregistré côté entrant,
     * envoyé ou remis côté sortant). Ne couvre que direction_origine_id et
     * direction_destination_id, pas la totalité du graphe métier
     * (imputations, dispatchs, documents produits...) : un garde-fou
     * simple contre la désactivation accidentelle en pleine activité, pas
     * un audit exhaustif de toute relation possible avec la direction.
     */
    private const STATUTS_TERMINAUX = [CourrierStatut::ENREGISTRE, CourrierStatut::ENVOYE, CourrierStatut::REMIS];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Direction::class);

        return DirectionResource::collection(Direction::query()->orderBy('code')->get());
    }

    public function show(Direction $direction)
    {
        $this->authorize('view', $direction);

        return new DirectionResource($direction);
    }

    public function store(StoreDirectionRequest $request)
    {
        $direction = Direction::query()->create([
            'actif' => true,
            ...$request->validated(),
        ]);

        return (new DirectionResource($direction))->response()->setStatusCode(201);
    }

    public function update(UpdateDirectionRequest $request, Direction $direction)
    {
        $donnees = $request->validated();

        if (($donnees['actif'] ?? true) === false && $direction->actif) {
            $this->assertAucunCourrierEnCours($direction);
        }

        $direction->update($donnees);

        return new DirectionResource($direction);
    }

    private function assertAucunCourrierEnCours(Direction $direction): void
    {
        $enCours = Courrier::query()
            ->where(fn ($q) => $q->where('direction_origine_id', $direction->id)->orWhere('direction_destination_id', $direction->id))
            ->whereNotIn('statut', self::STATUTS_TERMINAUX)
            ->exists();

        if ($enCours) {
            throw ValidationException::withMessages([
                'actif' => ['Cette direction a des courriers en cours : elle ne peut pas être désactivée tant qu\'ils ne sont pas enregistrés, envoyés ou remis.'],
            ]);
        }
    }

    public function destroy(Direction $direction)
    {
        $this->authorize('delete', $direction);

        $direction->delete();

        return response()->json(null, 204);
    }
}
