<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Courrier\Models\BordereauConservation;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Models\PlanClassementRubrique;
use Modules\Courrier\Services\ConservationService;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Lot 6 : plan de classement (référentiel réglé par l'administrateur), fin de conservation et bordereaux
 * d'élimination ou de versement (SEC2 propose, la DG valide). Aucune destruction automatique.
 */
class PlanClassementController extends Controller
{
    public function __construct(private readonly ConservationService $conservation, private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json(['data' => PlanClassementRubrique::query()->orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === UserRole::ADMINISTRATEUR, 403);
        $donnees = $request->validate($this->regles(true));
        $rubrique = PlanClassementRubrique::query()->create($donnees);
        $this->audit->enregistrer('plan_classement.rubrique_creee', $rubrique, $request->user(), $donnees);

        return response()->json(['data' => $rubrique], 201);
    }

    public function update(Request $request, PlanClassementRubrique $rubrique)
    {
        abort_unless($request->user()->role === UserRole::ADMINISTRATEUR, 403);
        $donnees = $request->validate($this->regles(false, $rubrique));
        $avant = $rubrique->only(array_keys($donnees));
        $rubrique->update($donnees);
        $this->audit->enregistrer('plan_classement.rubrique_modifiee', $rubrique, $request->user(), ['avant' => $avant, 'apres' => $donnees]);

        return response()->json(['data' => $rubrique->fresh()]);
    }

    public function finConservation(Request $request)
    {
        $this->exigerSec2OuDg($request->user());

        return response()->json(['data' => $this->conservation->dossiersEchus()->map(fn (Dossier $d) => [
            'dossier_id' => $d->id,
            'libelle' => $d->libelle,
            'rubrique' => $d->rubriqueClassement?->intitule(),
            'archive_le' => $d->archive_at?->toDateString(),
            'fin_conservation_le' => $d->finConservationLe()?->toDateString(),
            'sort_final' => $d->rubriqueClassement?->sort_final,
        ])->values()]);
    }

    public function bordereaux(Request $request)
    {
        $this->exigerSec2OuDg($request->user());

        return response()->json(['data' => BordereauConservation::query()->withCount('dossiers')->latest('id')->get()
            ->map(fn (BordereauConservation $b) => $this->bordereauEnTableau($b))]);
    }

    public function proposer(Request $request)
    {
        abort_unless(app(DelegationResolver::class)->utilisateurHabilite($request->user(), [Poste::SECRETARIAT_2]), 403);
        $donnees = $request->validate([
            'type' => ['required', 'in:elimination,versement'],
            'dossier_ids' => ['required', 'array', 'min:1'],
            'dossier_ids.*' => ['integer', 'distinct'],
            'motif' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $bordereau = $this->conservation->proposer($donnees['type'], array_map('intval', $donnees['dossier_ids']), $donnees['motif'], $request->user());

        return response()->json(['data' => $this->bordereauEnTableau($bordereau->loadCount('dossiers'))], 201);
    }

    public function valider(Request $request, BordereauConservation $bordereau)
    {
        abort_unless(app(DgAuthorityResolver::class)->estAutorite($request->user()), 403);

        return response()->json(['data' => $this->bordereauEnTableau($this->conservation->valider($bordereau, $request->user())->loadCount('dossiers'))]);
    }

    public function pdf(Request $request, BordereauConservation $bordereau, PdfGenerationService $pdf)
    {
        $this->exigerSec2OuDg($request->user());
        $contenu = $pdf->genererDepuisVue('courrier::bordereau-conservation', ['bordereau' => $bordereau->load(['dossiers', 'proposePar', 'validePar'])]);

        return response($contenu, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$bordereau->numero}.pdf\""]);
    }

    private function exigerSec2OuDg(User $user): void
    {
        abort_unless(app(DelegationResolver::class)->utilisateurHabilite($user, [Poste::SECRETARIAT_2])
            || app(DgAuthorityResolver::class)->estAutorite($user), 403);
    }

    /** @return array<string, mixed> */
    private function regles(bool $creation, ?PlanClassementRubrique $rubrique = null): array
    {
        $requis = $creation ? 'required' : 'sometimes';

        return [
            'code' => [$requis, 'string', 'max:50', Rule::unique('plan_classement_rubriques', 'code')->ignore($rubrique?->id)],
            'libelle' => [$requis, 'string', 'max:255'],
            'duree_conservation_annees' => ['nullable', 'integer', 'min:0', 'max:200'],
            'sort_final' => ['nullable', Rule::in(PlanClassementRubrique::SORTS_FINAUX)],
            'actif' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function bordereauEnTableau(BordereauConservation $b): array
    {
        return [
            'id' => $b->id, 'numero' => $b->numero, 'type' => $b->type, 'type_label' => $b->libelleType(),
            'statut' => $b->statut, 'motif' => $b->motif, 'dossiers_count' => $b->dossiers_count ?? $b->dossiers()->count(),
            'propose_par_id' => $b->propose_par_id, 'propose_at' => $b->propose_at, 'valide_par_id' => $b->valide_par_id, 'valide_at' => $b->valide_at,
        ];
    }
}
