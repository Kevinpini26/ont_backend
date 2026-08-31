<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Concerns\BelongsToDirectionScope;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Database\Factories\StagiaireFactory;
use Modules\Stagiaires\Enums\StagiaireOrigine;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;

class Stagiaire extends Model
{
    /** @use HasFactory<StagiaireFactory> */
    use BelongsToDirectionScope, HasFactory;

    protected $fillable = [
        'courrier_id',
        'matricule',
        'nom',
        'contact',
        'etablissement_origine',
        'etablissement_id',
        'type_stage',
        'lieu_naissance',
        'filiere_formation',
        'niveau_formation',
        'session_promotion',
        'maitre_stage',
        'maitre_stage_id',
        'conseiller_stage',
        'periode_debut_demandee',
        'periode_fin_demandee',
        'reference_courrier',
        'statut',
        'direction_id',
        'affecte_par_id',
        'affecte_at',
        'date_debut_stage',
        'date_fin_stage',
        'alerte_echeance_envoyee_at',
        'periode_evaluation_ouverte_at',
        'periode_evaluation_ouverte_par_id',
        'evaluation_direction_grille',
        'evaluation_direction_total',
        'evaluation_direction_at',
        'evaluation_dfp_grille',
        'evaluation_dfp_total',
        'evaluation_dfp_at',
        'note_finale',
        'cloture_at',
        'numero_attestation',
        'token_verification',
        'objectifs',
        'doublon_suspecte',
        'doublon_stagiaire_id',
        'convention_chemin',
        'convention_genere_at',
        'convention_signee_direction_at',
        'convention_signee_direction_par_id',
        'convention_signee_stagiaire_at',
        'convention_sha256',
        'engagement_confidentialite_chemin',
        'engagement_confidentialite_sha256',
        'engagement_confidentialite_genere_at',
        'engagement_confidentialite_signe_at',
        'affecte_hors_quota',
        'origine',
        'importe_par_id',
        'importe_at',
    ];

    protected static function newFactory(): StagiaireFactory
    {
        return StagiaireFactory::new();
    }

    protected function casts(): array
    {
        return [
            'statut' => StagiaireStatut::class,
            'origine' => StagiaireOrigine::class,
            'type_stage' => StagiaireTypeStage::class,
            'periode_debut_demandee' => 'date',
            'periode_fin_demandee' => 'date',
            'date_debut_stage' => 'date',
            'date_fin_stage' => 'date',
            'affecte_at' => 'datetime',
            'alerte_echeance_envoyee_at' => 'datetime',
            'periode_evaluation_ouverte_at' => 'datetime',
            'evaluation_direction_at' => 'datetime',
            'evaluation_dfp_at' => 'datetime',
            'cloture_at' => 'datetime',
            'evaluation_direction_grille' => 'array',
            'evaluation_direction_total' => 'float',
            'evaluation_dfp_grille' => 'array',
            'evaluation_dfp_total' => 'float',
            'note_finale' => 'float',
            'objectifs' => 'array',
            'doublon_suspecte' => 'boolean',
            'convention_genere_at' => 'datetime',
            'convention_signee_direction_at' => 'datetime',
            'convention_signee_stagiaire_at' => 'datetime',
            'engagement_confidentialite_genere_at' => 'datetime',
            'engagement_confidentialite_signe_at' => 'datetime',
            'affecte_hors_quota' => 'boolean',
            'importe_at' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(EtablissementFormation::class, 'etablissement_id');
    }

    public function affectePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affecte_par_id');
    }

    public function maitreStageUtilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maitre_stage_id');
    }

    public function periodeEvaluationOuvertePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'periode_evaluation_ouverte_par_id');
    }

    public function presences(): HasMany
    {
        return $this->hasMany(StagiairePresence::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StagiaireDocument::class);
    }

    public function suivis(): HasMany
    {
        return $this->hasMany(StagiaireSuivi::class)->latest('date_suivi');
    }

    public function doublonStagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'doublon_stagiaire_id');
    }

    public function conventionSigneeDirectionPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convention_signee_direction_par_id');
    }

    public function liensPublics(): HasMany
    {
        return $this->hasMany(StagiaireLienPublic::class);
    }

    public function retour(): HasOne
    {
        return $this->hasOne(StagiaireRetour::class);
    }

    public function importePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importe_par_id');
    }

    /**
     * Tri par id, pas par created_at seul : deux prolongations enregistrées
     * dans la même seconde (précision par défaut des timestamps Laravel)
     * auraient sinon un ordre non déterministe — l'id auto-incrémenté
     * reflète toujours l'ordre d'insertion réel, sans cette ambiguïté.
     */
    public function prolongations(): HasMany
    {
        return $this->hasMany(StagiaireProlongation::class)->latest('id');
    }

    public function joursRestants(): ?int
    {
        if (! $this->date_fin_stage) {
            return null;
        }

        // Carbon::diffInDays() peut renvoyer un float selon la configuration
        // de précision — un nombre de jours n'a de sens qu'entier ici.
        return (int) now()->startOfDay()->diffInDays($this->date_fin_stage, false);
    }
}
