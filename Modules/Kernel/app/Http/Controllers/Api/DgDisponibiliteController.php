<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\User;
use Modules\Kernel\Services\DgInterimService;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Disponibilité de la DG pour le circuit courrier — voir DgDisponibilite et
 * CourrierCircuitService::rendreAvisDg(). Endpoint lisible par tout
 * utilisateur authentifié (le poste DGA doit savoir s'il est en intérim),
 * modifiable seulement par la DG elle-même ou un administrateur.
 */
class DgDisponibiliteController extends Controller
{
    public function __construct(
        private readonly DgAuthorityResolver $autorite,
        private readonly DgInterimService $interims,
    ) {}

    public function show(Request $request)
    {
        $interim = $this->autorite->interimOuvert()?->load(['dgTitulaire', 'dgaInterimaire', 'ouvertPar']);
        $peutVoirPeriode = $request->user()->role === UserRole::ADMINISTRATEUR
            || $request->user()->poste === Poste::DG
            || $request->user()->id === $interim?->dga_interimaire_id;

        return response()->json([
            'disponible' => $interim === null,
            'source_autorite' => $this->autorite->source($request->user()),
            'interim' => $interim !== null && $peutVoirPeriode ? $this->presenter($interim) : null,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === UserRole::ADMINISTRATEUR || $user->poste === Poste::DG, 403);

        $data = $request->validate([
            'disponible' => ['required', 'boolean'],
            'dga_interimaire_id' => ['required_if:disponible,false', 'integer', 'exists:users,id'],
            'motif' => ['required_if:disponible,false', 'string', 'min:1', 'max:1000', 'regex:/\\S/u'],
        ]);

        $interim = $data['disponible']
            ? $this->interims->terminer($user)
            : $this->interims->ouvrir($user, User::query()->findOrFail($data['dga_interimaire_id']), $data['motif']);

        return response()->json(['disponible' => (bool) $data['disponible'], 'interim' => $this->presenter($interim->load(['dgTitulaire', 'dgaInterimaire', 'ouvertPar', 'terminePar']))]);
    }

    public function historique(Request $request)
    {
        abort_unless($request->user()->role === UserRole::ADMINISTRATEUR || $request->user()->poste === Poste::DG, 403);

        return DgInterim::query()->with(['dgTitulaire', 'dgaInterimaire', 'ouvertPar', 'terminePar'])
            ->latest('started_at')->paginate(30)->through(fn (DgInterim $interim) => $this->presenter($interim));
    }

    private function presenter(DgInterim $interim): array
    {
        return [
            'id' => $interim->id,
            'dg_titulaire_id' => $interim->dg_titulaire_id,
            'dg_titulaire' => ['id' => $interim->dg_titulaire_id, 'name' => $interim->dgTitulaire?->name],
            'dga_interimaire_id' => $interim->dga_interimaire_id,
            'dga_interimaire' => ['id' => $interim->dga_interimaire_id, 'name' => $interim->dgaInterimaire?->name],
            'motif' => $interim->motif,
            'started_at' => $interim->started_at?->toIso8601String(),
            'ouvert_par' => ['id' => $interim->started_by_id, 'name' => $interim->ouvertPar?->name],
            'ended_at' => $interim->ended_at?->toIso8601String(),
            'termine_par' => $interim->ended_by_id === null ? null : ['id' => $interim->ended_by_id, 'name' => $interim->terminePar?->name],
        ];
    }
}
