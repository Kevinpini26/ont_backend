<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Courrier\Database\Factories\CourrierFactory;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Scopes\CourrierDirectionScope;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class Courrier extends Model
{
    /** @use HasFactory<CourrierFactory> */
    use HasFactory;

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
