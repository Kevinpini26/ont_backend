<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Modules\Courrier\Database\Factories\CourrierFactory;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\ModeExpedition;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\ModeRemise;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Scopes\CourrierDirectionScope;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class Courrier extends Model
{
    /** @use HasFactory<CourrierFactory> */
    use HasFactory;

    /**
     * Défaut applicatif, pas seulement la valeur par défaut de la colonne
     * (voir migration) : Eloquent ne recharge jamais depuis la base les
     * valeurs par défaut posées côté SQL après un create() (seul l'id
     * auto-incrémenté l'est) — sans ce défaut ici, l'instance en mémoire
     * renvoyée immédiatement après la création (donc la réponse API) a
     * `nombre_annexes` à null tant qu'on ne recharge pas explicitement le
     * modèle, alors que la ligne en base contient bien 0.
     */
    protected $attributes = [
        'nombre_annexes' => 0,
        'degre_urgence' => 'normal',
        'niveau_confidentialite' => 'ordinaire',
        'sens' => 'entrant',
    ];

    protected $fillable = [
        'numero_accuse_reception',
        'numero_enregistrement',
        'objet',
        'contenu',
        'type',
        'statut',
        'direction_origine_id',
        'direction_destination_id',
        'necessite_avis_dg',
        'initie_par_dg',
        'validation_dg_requise',
        'valide_par_dg_at',
        'expediteur_externe_nom',
        'expediteur_externe_email',
        'expediteur_externe_telephone',
        'piece_jointe_chemin',
        'candidat_nom',
        'candidat_contact',
        'candidat_email',
        'candidat_etablissement',
        'periode_souhaitee_debut',
        'periode_souhaitee_fin',
        'type_stage',
        'lettre_stage_chemin',
        'cv_chemin',
        'diplome_etat_chemin',
        'dernier_diplome_chemin',
        'lettre_demande_chemin',
        'avis_dg',
        'avis_dg_commentaire',
        'avis_dg_rendu_at',
        'avis_dg_rendu_par_id',
        'avis_dg_rendu_en_interim',
        'projet_reponse_contenu',
        'relecteur_id',
        'relecture_validee_at',
        'relecture_commentaire',
        'signataire_id',
        'signe_at',
        'pdf_chemin',
        'classification',
        'note_technique',
        'accuse_reception_partenaire',
        'enregistre_at',
        'created_by',
        'relance_avis_dg_envoyee_at',
        'anonymise_at',
        'date_courrier',
        'reference_expediteur',
        'qualite_expediteur',
        'mode_reception',
        'nombre_annexes',
        'degre_urgence',
        'niveau_confidentialite',
        'cote_classement',
        'emplacement_physique',
        'sens',
        'en_reponse_a_courrier_id',
        'destinataire_externe_nom',
        'destinataire_externe_email',
        'mode_expedition',
        'date_envoi',
        'numero_depart',
        'remis_le',
        'remis_a',
        'mode_remise',
        'decharge_remise_chemin',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CourrierDirectionScope);
    }

    protected static function newFactory(): CourrierFactory
    {
        return CourrierFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => CourrierType::class,
            'statut' => CourrierStatut::class,
            'avis_dg' => AvisDg::class,
            'classification' => CourrierClassification::class,
            'necessite_avis_dg' => 'boolean',
            'initie_par_dg' => 'boolean',
            'validation_dg_requise' => 'boolean',
            'valide_par_dg_at' => 'datetime',
            'avis_dg_rendu_en_interim' => 'boolean',
            'contenu' => 'array',
            'projet_reponse_contenu' => 'array',
            'periode_souhaitee_debut' => 'date',
            'periode_souhaitee_fin' => 'date',
            'relecture_validee_at' => 'datetime',
            'signe_at' => 'datetime',
            'enregistre_at' => 'datetime',
            'relance_avis_dg_envoyee_at' => 'datetime',
            'avis_dg_rendu_at' => 'datetime',
            'anonymise_at' => 'datetime',
            'date_courrier' => 'date',
            'mode_reception' => ModeReception::class,
            'nombre_annexes' => 'integer',
            'degre_urgence' => DegreUrgence::class,
            'niveau_confidentialite' => NiveauConfidentialite::class,
            'sens' => SensCourrier::class,
            'mode_expedition' => ModeExpedition::class,
            'date_envoi' => 'date',
            'remis_le' => 'datetime',
            'mode_remise' => ModeRemise::class,
        ];
    }

    public function directionOrigine(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_origine_id');
    }

    public function directionDestination(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_destination_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function relecteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relecteur_id');
    }

    public function signataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signataire_id');
    }

    public function avisDgRenduPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avis_dg_rendu_par_id');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(CourrierAnnotation::class)->latest();
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(CourrierImputation::class);
    }

    /**
     * Annexes multiples — voir courrier_pieces_jointes. Coexiste avec
     * piece_jointe_chemin (toujours lue ailleurs, voir la migration de
     * création de cette table) plutôt que de la remplacer d'un coup.
     */
    /**
     * Fil de correspondance : les courriers sortants créés en réponse à
     * celui-ci (voir CourrierCircuitService::initierReponseSortante()).
     */
    public function reponses(): HasMany
    {
        return $this->hasMany(Courrier::class, 'en_reponse_a_courrier_id')->oldest('created_at');
    }

    public function courrierOrigine(): BelongsTo
    {
        return $this->belongsTo(Courrier::class, 'en_reponse_a_courrier_id');
    }

    public function piecesJointes(): HasMany
    {
        return $this->hasMany(CourrierPieceJointe::class)->orderBy('ordre');
    }

    /**
     * La direction imputée à titre principal — celle qui doit agir,
     * distincte des directions simplement mises en copie (voir
     * directionsEnCopie()).
     */
    public function directionPrincipale(): ?CourrierImputation
    {
        return $this->imputations->firstWhere('est_principale', true);
    }

    /**
     * @return Collection<int, CourrierImputation>
     */
    public function directionsEnCopie()
    {
        return $this->imputations->where('est_principale', false);
    }

    /**
     * Tri explicite par id, pas created_at seul : deux transitions
     * enregistrées dans la même seconde (précision par défaut des
     * timestamps Laravel) auraient sinon un ordre non garanti — même
     * correctif que StagiaireProlongation::prolongations().
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(CourrierTransition::class)->oldest('id');
    }

    /**
     * Le bordereau qui a amené le courrier à son statut actuel — celui
     * dont la décharge conditionne la possibilité d'agir. Utilise la
     * relation déjà chargée (pas de requête séparée) quand elle l'est.
     */
    public function bordereauCourant(): ?CourrierTransition
    {
        /** @var CourrierTransition|null $bordereau */
        $bordereau = $this->transitions->where('statut', $this->statut)->last();

        return $bordereau;
    }

    /**
     * "En transit" n'est pas un statut à part : c'est le bordereau courant
     * qui n'a pas encore été acquitté par son destinataire — orthogonal à
     * `statut`, qui ne change qu'à la prochaine transition franchie.
     */
    public function enTransit(): bool
    {
        return $this->bordereauCourant()?->accuse_reception_at === null;
    }

    public function relectureEstValidee(): bool
    {
        return $this->relecture_validee_at !== null;
    }

    /**
     * Recherche plein texte réelle (PostgreSQL, configuration française),
     * pas un simple ILIKE — voir la migration
     * add_recherche_tsvector_to_courriers pour la colonne alimentée par
     * trigger. Un numéro qui correspond exactement (enregistrement, accusé
     * de réception, départ, cote de classement) est toujours priorisé en
     * tête : c'est un code, jamais raciniser comme du texte.
     */
    public function scopeRecherchePleinTexte(Builder $query, string $terme): Builder
    {
        return $query
            ->where(function (Builder $q) use ($terme) {
                $q->whereRaw('recherche_tsvector @@ plainto_tsquery(\'french\', ?)', [$terme])
                    ->orWhere('numero_enregistrement', $terme)
                    ->orWhere('numero_accuse_reception', $terme)
                    ->orWhere('numero_depart', $terme)
                    ->orWhere('cote_classement', $terme);
            })
            ->orderByRaw(
                '(case when numero_enregistrement = ? or numero_accuse_reception = ? or numero_depart = ? or cote_classement = ? then 0 else 1 end) asc, ts_rank(recherche_tsvector, plainto_tsquery(\'french\', ?)) desc',
                [$terme, $terme, $terme, $terme, $terme]
            );
    }

    /**
     * Classement interne/externe déterminé par la nature réelle du
     * courrier, jamais laissé au libre choix de l'agent qui enregistre
     * (voir EnregistrerCourrierRequest::withValidator()) :
     * - un candidat identifié (demande de stage) ou un expéditeur externe
     *   nommé rend le courrier externe, quel que soit son créateur (même
     *   une demande de stage recommandée par une direction concerne un
     *   candidat hors de l'ONT) ;
     * - à défaut, l'absence de direction d'origine trahit un courrier créé
     *   par la Réception (posteDeCreation) — jamais par une direction, qui
     *   se voit toujours forcer sa propre direction_origine_id à la
     *   création (voir CourrierCircuitService::creer()) — donc un mail
     *   physique externe, même quand l'expéditeur précis n'a pas été saisi ;
     * - sinon (direction d'origine renseignée, pas de candidat/expéditeur
     *   externe) c'est un échange interne entre directions, ou vers la DG
     *   elle-même (direction_destination_id alors nul sans que ce soit un
     *   signe d'extériorité) ;
     * - exception à la règle "direction_origine_id nul" ci-dessus : un
     *   courrier initié par la DG elle-même (initie_par_dg) a aussi
     *   direction_origine_id nul (la DG n'est pas rattachée à une
     *   Direction), sans pour autant être externe — c'est une
     *   communication interne à l'ONT, voir CourrierCircuitService::initierParDg().
     */
    public function classificationAttendue(): CourrierClassification
    {
        $estExterne = filled($this->candidat_nom)
            || filled($this->expediteur_externe_nom)
            || ($this->direction_origine_id === null && ! $this->initie_par_dg);

        return $estExterne ? CourrierClassification::EXTERNE : CourrierClassification::INTERNE;
    }
}
