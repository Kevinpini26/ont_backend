<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Http\Requests\StoreUserRequest;
use Modules\Kernel\Http\Requests\UpdateUserRequest;
use Modules\Kernel\Http\Resources\UserResource;
use Modules\Kernel\Models\User;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        return UserResource::collection(
            User::query()->with('direction')->orderBy('name')->paginate(20)
        );
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('direction'));
    }

    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        // Mot de passe attribué par l'administrateur, jamais choisi par le
        // titulaire du compte : à changer obligatoirement à la première
        // connexion (voir EnsureMotDePasseAJour).
        $user = User::query()->create([
            ...$request->validated(),
            'password' => bcrypt($request->validated()['password']),
            'doit_changer_mot_de_passe' => true,
        ]);

        return (new UserResource($user->load('direction')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
            // Même règle qu'à la création : un mot de passe posé par un
            // administrateur (réinitialisation) doit être changé par le
            // titulaire avant de pouvoir faire autre chose.
            $data['doit_changer_mot_de_passe'] = true;
        }

        $user->update($data);

        return new UserResource($user->load('direction'));
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * Révoque tous les jetons Sanctum d'un compte : à utiliser en cas de
     * compromission suspectée (appareil perdu, doute sur les identifiants).
     */
    public function revoquerJetons(User $user)
    {
        $this->authorize('update', $user);

        $user->tokens()->delete();

        return response()->json(['message' => 'Jetons révoqués avec succès.']);
    }

    /**
     * Déverrouillage manuel avant l'expiration naturelle du verrou (voir
     * AuthController::enregistrerEchec()) : l'administrateur qui répond à
     * un titulaire de compte injustement bloqué n'a pas à attendre.
     */
    public function deverrouiller(User $user)
    {
        $this->authorize('update', $user);

        $user->echecs_connexion_consecutifs = 0;
        $user->verrouille_jusqu_a = null;
        $user->save();

        $this->audit->enregistrer('auth.compte_deverrouille_manuellement', $user, request()->user());

        return response()->json(['message' => 'Compte déverrouillé avec succès.']);
    }
}
