<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Courrier\Enums\CourrierAnnotationType;
use Modules\Courrier\Enums\CourrierAnnotationVisibilite;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Note de circuit : une annotation du circuit n'est jamais modifiée ni supprimée.
 * Une correction est une nouvelle note qui référence l'ancienne (annule_par_id,
 * porté par l'ancienne). Seul un rappel personnel peut être retiré par son auteur.
 */
class CourrierAnnotation extends Model
{
    protected $fillable = [
        'courrier_id',
        'auteur_id',
        'contenu',
        'type',
        'destinataire_poste',
        'destinataire_direction_id',
        'visibilite',
        'annule_par_id',
    ];

    protected $attributes = [
        'type' => 'observation',
        'visibilite' => 'circuit',
    ];

    protected function casts(): array
    {
        return [
            'type' => CourrierAnnotationType::class,
            'visibilite' => CourrierAnnotationVisibilite::class,
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function destinataireDirection(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'destinataire_direction_id');
    }

    /** Note qui a corrigé celle-ci (null tant qu'elle est à jour). */
    public function corrigeePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'annule_par_id');
    }

    /** Note corrigée par celle-ci (celle qui l'a remplacée). */
    public function correctionDe(): HasOne
    {
        return $this->hasOne(self::class, 'annule_par_id');
    }

    public function lectures(): HasMany
    {
        return $this->hasMany(CourrierAnnotationLecture::class, 'annotation_id');
    }

    /** Notes que cet utilisateur a le droit de voir : celles du circuit, plus ses propres rappels personnels. */
    public function scopeVisiblesPour(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('courrier_annotations.visibilite', CourrierAnnotationVisibilite::CIRCUIT->value)
            ->orWhere('courrier_annotations.auteur_id', $user->id));
    }

    /** Horodatage rendu selon le fuseau de session (colonne sans fuseau), voir CourrierAnnotationResource. */
    public function scopeAvecFuseau(Builder $query): Builder
    {
        return $query
            ->select('courrier_annotations.*')
            ->selectRaw("courrier_annotations.created_at AT TIME ZONE current_setting('TimeZone') AS created_at_with_timezone");
    }
}
