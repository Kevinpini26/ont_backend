<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Courrier\Http\Resources\InstructionCourrierDgResource;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Courrier\Services\InstructionCourrierDgService;

class InstructionCourrierDgController extends Controller
{
    public function __construct(private readonly InstructionCourrierDgService $instructions) {}

    public function index(Request $request)
    {
        $acteur = $request->user();
        $donneur = $this->instructions->peutDonner($acteur);
        abort_unless($donneur || $this->instructions->estSec1($acteur), 403);

        $query = InstructionCourrierDg::query()->latest();
        if (! $donneur) {
            $query->whereNull('annule_at')->whereNull('consomme_at')
                ->where(fn ($q) => $q->whereNull('expire_at')->orWhere('expire_at', '>', now()))
                ->where(fn ($q) => $q->whereNull('destinataire_user_id')->orWhere('destinataire_user_id', $acteur->id));
        }

        return InstructionCourrierDgResource::collection($query->paginate(20));
    }

    public function store(Request $request)
    {
        abort_unless($this->instructions->peutDonner($request->user()), 403);
        $donnees = $request->validate([
            'instruction' => ['required', 'string', 'min:5', 'max:10000'],
            'destinataire_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'expire_at' => ['nullable', 'date', 'after:now'],
        ]);

        return (new InstructionCourrierDgResource($this->instructions->creer($request->user(), $donnees)))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, InstructionCourrierDg $instruction)
    {
        abort_unless($this->instructions->peutVoir($request->user(), $instruction), 404);

        return new InstructionCourrierDgResource($this->instructions->ouvrir($request->user(), $instruction));
    }

    public function annuler(Request $request, InstructionCourrierDg $instruction)
    {
        return new InstructionCourrierDgResource($this->instructions->annuler($request->user(), $instruction));
    }
}
