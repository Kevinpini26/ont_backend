<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Http\Requests\CreerDelegationPosteRequest;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\DelegationPoste;

class DelegationPosteController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->role === UserRole::ADMINISTRATEUR, 403);

        return response()->json([
            'data' => DelegationPoste::query()
                ->with(['delegataire', 'creePar', 'revoqueePar'])
                ->latest('id')
                ->get()
                ->map(fn (DelegationPoste $delegation) => $this->representer($delegation)),
        ]);
    }

    public function store(CreerDelegationPosteRequest $request)
    {
        $donnees = $request->validated();
        $delegation = DB::transaction(function () use ($request, $donnees) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['delegation-poste:'.$donnees['poste']]);
            if (DelegationPoste::query()->where('poste', $donnees['poste'])
                ->whereNull('revoquee_at')
                ->whereDate('debut', '<=', $donnees['fin'])
                ->whereDate('fin', '>=', $donnees['debut'])->exists()) {
                throw ValidationException::withMessages(['poste' => 'Une délégation de ce poste couvre déjà cette période.']);
            }
            $delegation = DelegationPoste::query()->create([
                ...$donnees,
                'cree_par_id' => $request->user()->id,
            ]);
            $this->audit->enregistrer('delegation_poste.creee', $delegation, $request->user(), [
                'poste' => $delegation->poste->value,
                'delegataire_id' => $delegation->delegataire_id,
                'debut' => $delegation->debut->toDateString(),
                'fin' => $delegation->fin->toDateString(),
            ]);

            return $delegation;
        });

        return response()->json([
            'data' => $this->representer($delegation->load(['delegataire', 'creePar', 'revoqueePar'])),
        ], 201);
    }

    public function revoquer(Request $request, DelegationPoste $delegation)
    {
        abort_unless($request->user()->role === UserRole::ADMINISTRATEUR, 403);
        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) === '') {
                    $fail('Le motif de révocation est obligatoire.');
                }
            }],
        ]);
        $delegation = DB::transaction(function () use ($delegation, $request, $donnees) {
            $delegation = DelegationPoste::query()->whereKey($delegation->id)->lockForUpdate()->firstOrFail();
            if ($delegation->revoquee_at !== null) {
                throw ValidationException::withMessages(['delegation' => 'Cette délégation est déjà révoquée.']);
            }
            if ($delegation->fin->isBefore(today())) {
                throw ValidationException::withMessages(['delegation' => 'Une délégation expirée ne peut pas être révoquée.']);
            }
            $revoqueeAt = now();
            $delegation->update([
                'revoquee_at' => $revoqueeAt,
                'revoquee_par_id' => $request->user()->id,
                'motif_revocation' => trim($donnees['motif']),
            ]);
            $this->audit->enregistrer('delegation_poste.revoquee', $delegation, $request->user(), [
                'poste' => $delegation->poste->value,
                'delegataire_id' => $delegation->delegataire_id,
                'revoquee_at' => $revoqueeAt->toIso8601String(),
                'motif' => $delegation->motif_revocation,
            ]);

            return $delegation;
        });

        return response()->json(['data' => $this->representer($delegation->load(['delegataire', 'creePar', 'revoqueePar']))]);
    }

    private function representer(DelegationPoste $delegation): array
    {
        return [...$delegation->toArray(), 'etat' => $delegation->etat()];
    }
}
