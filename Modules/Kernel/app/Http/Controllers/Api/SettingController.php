<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Kernel\Contracts\AuditLogger;
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
        'courrier.tri_delai_alerte_heures' => ['type' => 'integer', 'defaut' => 4],
        'courrier.avis_dg_delai_alerte_heures' => ['type' => 'integer', 'defaut' => 48],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json([
            'data' => collect(self::DEFINITIONS)->map(fn ($definition, $cle) => [
                'cle' => $cle,
                'type' => $definition['type'],
                'valeur' => Setting::valeur($cle, $definition['defaut']),
                'defaut' => $definition['defaut'],
            ])->values(),
        ]);
    }

    public function update(Request $request, string $cle)
    {
        if (! array_key_exists($cle, self::DEFINITIONS)) {
            abort(404);
        }

        $definition = self::DEFINITIONS[$cle];
        $regle = match (true) {
            $cle === 'organisation.signataire_defaut_id' => ['required', 'integer', Rule::exists('users', 'id')],
            $definition['type'] === 'integer' => ['required', 'integer', 'min:0'],
            $definition['type'] === 'email' => ['required', 'email'],
            default => ['required', 'string', 'max:500'],
        };

        $donnees = $request->validate(['valeur' => $regle]);

        $ancienne = Setting::valeur($cle, $definition['defaut']);
        Setting::definir($cle, $donnees['valeur'], $request->user());

        $this->audit->enregistrer('parametre.modifie', null, $request->user(), [
            'cle' => $cle,
            'ancienne_valeur' => $ancienne,
            'nouvelle_valeur' => $donnees['valeur'],
        ]);

        return response()->json(['cle' => $cle, 'valeur' => $donnees['valeur']]);
    }
}
