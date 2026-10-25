<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Http\Requests\PassationDePosteRequest;
use Modules\Kernel\Http\Requests\StoreUserRequest;
use Modules\Kernel\Http\Requests\UpdateUserRequest;
use Modules\Kernel\Http\Resources\UserResource;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\User;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->with('direction');

        if ($request->filled('recherche')) {
            $recherche = $request->string('recherche')->toString();
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$recherche}%")->orWhere('email', 'ilike', "%{$recherche}%"));
        }
        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }
        if ($request->filled('poste')) {
            $query->where('poste', $request->string('poste'));
        }
        if ($request->filled('direction_id')) {
            $query->where('direction_id', $request->integer('direction_id'));
        }
        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }
        if ($request->boolean('verrouille')) {
            $query->whereNotNull('verrouille_jusqu_a')->where('verrouille_jusqu_a', '>', now());
        }

        $tri = in_array($request->string('tri')->toString(), ['name', 'email', 'role', 'poste', 'created_at'], true)
            ? $request->string('tri')->toString()
            : 'name';
        $ordre = $request->string('ordre')->toString() === 'desc' ? 'desc' : 'asc';

        $parPage = min(200, max(1, $request->integer('par_page', 20)));

        return UserResource::collection($query->orderBy($tri, $ordre)->paginate($parPage));
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return (new UserResource($user->load('direction')))->additional([
            'derniere_connexion_at' => AuditLog::query()
                ->where('user_id', $user->id)
                ->where('action', 'auth.connexion')
                ->latest('created_at')
                ->value('created_at'),
            'sessions_actives' => $user->tokens()->orderByDesc('last_used_at')->get()->map(fn ($jeton) => [
                'id' => $jeton->id,
                'nom' => $jeton->name,
                'created_at' => $jeton->created_at,
                'last_used_at' => $jeton->last_used_at,
            ]),
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        $donnees = $request->validated();
        $this->assertPosteDisponible(isset($donnees['poste']) ? Poste::from($donnees['poste']) : null, null);

        // Création par invitation : l'administrateur ne choisit jamais le
        // mot de passe du compte. Un mot de passe aléatoire inutilisable
        // est posé ici uniquement pour satisfaire la contrainte NOT NULL
        // de la colonne ; le titulaire définit le sien via le lien reçu
        // ci-dessous (même mécanisme que "mot de passe oublié", voir
        // PasswordController::reinitialiser()).
        $user = User::query()->create([
            ...$donnees,
            'password' => bcrypt(Str::random(40)),
            'doit_changer_mot_de_passe' => true,
        ]);

        Password::broker()->sendResetLink(['email' => $user->email]);

        $this->audit->enregistrer('user.cree', $user, $request->user(), [
            'role' => $user->role?->value,
            'poste' => $user->poste?->value,
            'direction_id' => $user->direction_id,
        ]);

        return (new UserResource($user->load('direction')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $donnees = $request->validated();

        if (array_key_exists('poste', $donnees)) {
            $this->assertPosteDisponible($donnees['poste'] !== null ? Poste::from($donnees['poste']) : null, $user);
        }

        if (isset($donnees['password'])) {
            $donnees['password'] = bcrypt($donnees['password']);
            // Même règle qu'à la création : un mot de passe posé par un
            // administrateur (réinitialisation) doit être changé par le
            // titulaire avant de pouvoir faire autre chose.
            $donnees['doit_changer_mot_de_passe'] = true;
        }

        $avant = $user->only(['name', 'email', 'role', 'poste', 'direction_id']);
        $user->update($donnees);

        $this->audit->enregistrer('user.modifie', $user, $request->user(), [
            'champs_modifies' => array_keys($donnees),
            'avant' => $avant,
        ]);

        return new UserResource($user->load('direction'));
    }

    /**
     * Suppression réservée aux comptes sans trace métier : toute entrée du
     * journal d'audit où ce compte est l'acteur en est une — un compte
     * jamais utilisé (créé par erreur, jamais activé) reste supprimable,
     * un compte qui a servi doit être désactivé (voir desactiver()) pour
     * garder la traçabilité de ce qu'il a fait.
     */
    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);

        // Une trace existe si ce compte a agi lui-même (user_id, ex. une
        // connexion) OU si une action a été faite SUR lui par quelqu'un
        // d'autre (auditable_id, ex. création, modification,
        // désactivation par un administrateur) — se limiter à user_id
        // laisserait supprimable tout compte géré uniquement par un tiers
        // et qui ne s'est jamais connecté lui-même.
        $aUneTrace = AuditLog::query()
            ->where('user_id', $user->id)
            ->orWhere(fn ($q) => $q->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id))
            ->exists();

        if ($aUneTrace) {
            throw ValidationException::withMessages([
                'user' => ['Ce compte a déjà agi dans le système (voir le journal d\'audit) : il ne peut plus être supprimé, seulement désactivé.'],
            ]);
        }

        $cible = $user->only(['name', 'email', 'role', 'poste']);
        $user->delete();

        $this->audit->enregistrer('user.supprime', null, $request->user(), $cible);

        return response()->json(null, 204);
    }

    /**
     * Désactiver au lieu de supprimer : le compte garde son historique
     * (créations, signatures, audit...), mais ne peut plus se connecter
     * (voir AuthController::login()) et perd tous ses jetons actifs.
     */
    public function desactiver(Request $request, User $user)
    {
        $this->authorize('update', $user);

        // Même garde que delete() : jamais de verrouillage accidentel de
        // son propre accès administrateur.
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => ['Vous ne pouvez pas désactiver votre propre compte.']]);
        }

        if (! $user->actif) {
            throw ValidationException::withMessages(['user' => ['Ce compte est déjà désactivé.']]);
        }

        $user->actif = false;
        $user->save();
        $user->tokens()->delete();

        $this->audit->enregistrer('user.desactive', $user, $request->user());

        return new UserResource($user->load('direction'));
    }

    public function reactiver(Request $request, User $user)
    {
        $this->authorize('update', $user);

        if ($user->actif) {
            throw ValidationException::withMessages(['user' => ['Ce compte est déjà actif.']]);
        }

        $this->assertPosteDisponible($user->poste, $user);

        $user->actif = true;
        $user->save();

        $this->audit->enregistrer('user.reactive', $user, $request->user());

        return new UserResource($user->load('direction'));
    }

    /**
     * Un poste unique (DG, Secrétariat 01/02, Réception, DGA) ne peut avoir
     * qu'un titulaire actif à la fois (voir Poste::estUnique()). La
     * passation désactive l'ancien titulaire et affecte le poste au
     * nouveau, en une seule action tracée — jamais deux comptes actifs sur
     * le même poste, même pendant une transition.
     */
    public function passationDePoste(PassationDePosteRequest $request)
    {
        $this->authorize('create', User::class);

        $donnees = $request->validated();
        $poste = Poste::from($donnees['poste']);
        $nouveauTitulaire = User::query()->findOrFail($donnees['nouveau_titulaire_id']);

        $ancienTitulaire = User::query()->where('poste', $poste)->where('actif', true)
            ->whereKeyNot($nouveauTitulaire->id)->first();

        if ($ancienTitulaire) {
            $ancienTitulaire->actif = false;
            $ancienTitulaire->save();
            $ancienTitulaire->tokens()->delete();
        }

        $nouveauTitulaire->poste = $poste;
        $nouveauTitulaire->actif = true;
        $nouveauTitulaire->save();

        $this->audit->enregistrer('user.poste_passation', $nouveauTitulaire, $request->user(), [
            'poste' => $poste->value,
            'ancien_titulaire_id' => $ancienTitulaire?->id,
            'nouveau_titulaire_id' => $nouveauTitulaire->id,
        ]);

        return new UserResource($nouveauTitulaire->load('direction'));
    }

    /**
     * Révoque tous les jetons Sanctum d'un compte : à utiliser en cas de
     * compromission suspectée (appareil perdu, doute sur les identifiants).
     */
    public function revoquerJetons(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $user->tokens()->delete();

        $this->audit->enregistrer('user.jetons_revoques', $user, $request->user());

        return response()->json(['message' => 'Jetons révoqués avec succès.']);
    }

    /**
     * Révocation d'une session précise (un seul appareil/jeton), distincte
     * de revoquerJetons() ci-dessus qui révoque tout — voir la fiche
     * utilisateur (show(), clé "sessions_actives").
     */
    public function revoquerJeton(Request $request, User $user, int $jeton)
    {
        $this->authorize('update', $user);

        $supprime = $user->tokens()->where('id', $jeton)->delete();
        abort_unless($supprime > 0, 404);

        $this->audit->enregistrer('user.jeton_revoque', $user, $request->user(), ['jeton_id' => $jeton]);

        return response()->json(['message' => 'Session révoquée avec succès.']);
    }

    /**
     * Déverrouillage manuel avant l'expiration naturelle du verrou (voir
     * AuthController::enregistrerEchec()) : l'administrateur qui répond à
     * un titulaire de compte injustement bloqué n'a pas à attendre.
     */
    public function deverrouiller(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $user->echecs_connexion_consecutifs = 0;
        $user->verrouille_jusqu_a = null;
        $user->save();

        $this->audit->enregistrer('auth.compte_deverrouille_manuellement', $user, $request->user());

        return response()->json(['message' => 'Compte déverrouillé avec succès.']);
    }

    private function assertPosteDisponible(?Poste $poste, ?User $exclude): void
    {
        if ($poste === null || ! $poste->estUnique()) {
            return;
        }

        $occupe = User::query()->where('poste', $poste)->where('actif', true)
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude->id))
            ->exists();

        if ($occupe) {
            throw ValidationException::withMessages([
                'poste' => ["Le poste {$poste->label()} est déjà occupé par un titulaire actif. Utilisez la passation de poste pour le réaffecter."],
            ]);
        }
    }
}
