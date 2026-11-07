<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Setting;

/**
 * Écran "Paramètres de l'organisation" : remplace à l'exécution les
 * valeurs par défaut codées en dur qui relèvent de l'organisation
 * (nom, contacts, formats, délais d'alerte...), jamais du comportement
 * technique (ça reste dans config/, voir docs/questions-ont.md pour la
 * liste exacte des clés couvertes).
 */
class SettingController extends Controller
{
    /**
     * @var array<string, array{type: string, defaut: mixed}>
     */
    private const DEFINITIONS = [
        'organisation.nom' => ['type' => 'string', 'defaut' => 'Office National du Tourisme'],
        'organisation.adresse' => ['type' => 'string', 'defaut' => 'Kinshasa, République Démocratique du Congo'],
        'organisation.contact_email' => ['type' => 'email', 'defaut' => 'contact@exemple-ont.cd'],
        'organisation.contact_telephone' => ['type' => 'string', 'defaut' => '+243 000 000 000'],
        'organisation.logo_url' => ['type' => 'string', 'defaut' => null],
        'organisation.signataire_defaut_id' => ['type' => 'integer', 'defaut' => null],
        'courrier.format_numero_depart' => ['type' => 'string', 'defaut' => '%d-D%04d'],
        'courrier.lien_ttl_minutes' => ['type' => 'integer', 'defaut' => 10080],
        'courrier.piece_jointe_limite_octets' => ['type' => 'integer', 'defaut' => 10 * 1024 * 1024],
        // Délais en HEURES OUVRÉES depuis le lot 6 (voir CalendrierOuvre). Valeurs provisoires.
        'courrier.tri_delai_alerte_heures' => ['type' => 'integer', 'defaut' => 4],
        'courrier.avis_dg_delai_alerte_heures' => ['type' => 'integer', 'defaut' => 14],
        'courrier.retard_delai_heures' => ['type' => 'integer', 'defaut' => 14],
        // Lot 6 : délai maximum au classeur d'attente de SEC1, en heures ouvrées (provisoire).
        'courrier.classeur_delai_heures' => ['type' => 'integer', 'defaut' => 14],
        // Cartouche de signature des registres (lot 6) : l'ONT n'a pas de Secrétaire Général, SEC2 par défaut.
        'registre.arrivee.signataire_poste' => ['type' => 'poste', 'defaut' => 'secretariat_2'],
        'registre.arrivee.signataire_intitule' => ['type' => 'string', 'defaut' => 'Le Secrétariat 02'],
        'registre.depart.signataire_poste' => ['type' => 'poste', 'defaut' => 'secretariat_2'],
        'registre.depart.signataire_intitule' => ['type' => 'string', 'defaut' => 'Le Secrétariat 02'],
        // Calendrier de travail (lot 6) : valeurs par défaut dans config('kernel.calendrier').
        'calendrier.jours_ouvres' => ['type' => 'jours', 'defaut' => null],
        'calendrier.heure_debut' => ['type' => 'heure', 'defaut' => null],
        'calendrier.heure_fin' => ['type' => 'heure', 'defaut' => null],
        'calendrier.fuseau' => ['type' => 'fuseau', 'defaut' => null],
        'calendrier.jours_feries' => ['type' => 'jours_feries', 'defaut' => null],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json([
            'data' => collect(self::DEFINITIONS)->map(fn ($definition, $cle) => [
                'cle' => $cle,
                'type' => $definition['type'],
                'valeur' => Setting::valeur($cle, $this->defaut($cle)),
                'defaut' => $this->defaut($cle),
            ])->values(),
        ]);
    }

    public function update(Request $request, string $cle)
    {
        if (! array_key_exists($cle, self::DEFINITIONS)) {
            abort(404);
        }

        $definition = self::DEFINITIONS[$cle];
        $regles = match (true) {
            $cle === 'organisation.signataire_defaut_id' => ['valeur' => ['required', 'integer', Rule::exists('users', 'id')]],
            $definition['type'] === 'integer' => ['valeur' => ['required', 'integer', 'min:0']],
            $definition['type'] === 'email' => ['valeur' => ['required', 'email']],
            $definition['type'] === 'jours' => ['valeur' => ['required', 'array', 'min:1', 'max:7'], 'valeur.*' => ['integer', 'between:1,7', 'distinct']],
            $definition['type'] === 'heure' => ['valeur' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']],
            $definition['type'] === 'poste' => ['valeur' => ['required', 'string', Rule::in(array_map(
                fn (Poste $poste) => $poste->value,
                array_filter(Poste::cases(), fn (Poste $poste) => ! $poste->estHistorique()),
            ))]],
            $definition['type'] === 'fuseau' => ['valeur' => ['required', 'string', Rule::in(timezone_identifiers_list())]],
            $definition['type'] === 'jours_feries' => [
                'valeur' => ['present', 'array', 'max:100'],
                'valeur.*.date' => ['required', 'string', 'regex:/^(\d{4}-)?(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
                'valeur.*.libelle' => ['required', 'string', 'max:120'],
            ],
            default => ['valeur' => ['required', 'string', 'max:500']],
        };

        $donnees = $request->validate($regles);

        // Les horaires doivent rester cohérents : la fin après le début.
        if ($definition['type'] === 'heure') {
            $debut = $cle === 'calendrier.heure_debut' ? $donnees['valeur'] : Setting::valeur('calendrier.heure_debut', $this->defaut('calendrier.heure_debut'));
            $fin = $cle === 'calendrier.heure_fin' ? $donnees['valeur'] : Setting::valeur('calendrier.heure_fin', $this->defaut('calendrier.heure_fin'));
            if ($fin <= $debut) {
                throw ValidationException::withMessages(['valeur' => "L'heure de fin doit être après l'heure de début."]);
            }
        }
        if ($definition['type'] === 'jours') {
            $donnees['valeur'] = array_values(array_map('intval', $donnees['valeur']));
        }
        if ($definition['type'] === 'jours_feries') {
            $donnees['valeur'] = collect($donnees['valeur'])->map(fn ($f) => ['date' => $f['date'], 'libelle' => $f['libelle']])->values()->all();
        }

        $ancienne = Setting::valeur($cle, $this->defaut($cle));
        Setting::definir($cle, $donnees['valeur'], $request->user());

        $this->audit->enregistrer('parametre.modifie', null, $request->user(), [
            'cle' => $cle,
            'ancienne_valeur' => $ancienne,
            'nouvelle_valeur' => $donnees['valeur'],
        ]);

        return response()->json(['cle' => $cle, 'valeur' => $donnees['valeur']]);
    }

    private function defaut(string $cle): mixed
    {
        return str_starts_with($cle, 'calendrier.')
            ? config('kernel.'.$cle)
            : self::DEFINITIONS[$cle]['defaut'];
    }
}
