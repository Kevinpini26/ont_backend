<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Models\User;

class DocumentProduitDirection extends Model
{
    private const STATUTS_VISIBLES_DIRECTEUR = [
        DocumentProduitStatut::BROUILLON,
        DocumentProduitStatut::SOUMIS_DIRECTEUR,
        DocumentProduitStatut::A_CORRIGER,
        DocumentProduitStatut::VALIDE,
    ];

    protected $table = 'documents_produits_direction';

    protected $fillable = ['origine', 'traitement_direction_id', 'courrier_id', 'document_source_id', 'direction_id', 'direction_destination_souhaitee_id', 'directeur_id', 'version_contenu', 'modifie_par_id', 'modifie_at', 'statut', 'cree_par_id', 'cree_at', 'soumis_par_id', 'soumis_at', 'correction_demandee_par_id', 'motif_correction', 'correction_demandee_at', 'valide_par_id', 'valide_at', 'transmis_reception_par_id', 'transmis_reception_at', 'recu_par_id', 'recu_at'];

    protected function casts(): array
    {
        return ['origine' => DocumentProduitOrigine::class, 'statut' => DocumentProduitStatut::class, 'version_contenu' => 'integer', 'pdf_officiel_version_contenu' => 'integer', 'pdf_officiel_genere_at' => 'datetime', 'cree_at' => 'datetime', 'modifie_at' => 'datetime', 'soumis_at' => 'datetime', 'correction_demandee_at' => 'datetime', 'valide_at' => 'datetime', 'transmis_reception_at' => 'datetime', 'recu_at' => 'datetime'];
    }

    public function traitement(): BelongsTo
    {
        return $this->belongsTo(TraitementDirection::class, 'traitement_direction_id');
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function documentSource(): BelongsTo
    {
        return $this->belongsTo(Courrier::class, 'document_source_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function directionDestinationSouhaitee(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_destination_souhaitee_id');
    }

    public function scanOfficielDocument(): BelongsTo
    {
        return $this->belongsTo(DocumentNumerise::class, 'scan_officiel_document_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par_id');
    }

    public function soumisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soumis_par_id');
    }

    public function correctionDemandeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'correction_demandee_par_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'sujet', 'auditable_type', 'auditable_id');
    }

    public function directeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directeur_id');
    }

    public function estVisiblePour(User $user): bool
    {
        if ($this->origine !== DocumentProduitOrigine::AUTONOME) {
            return false;
        }

        if ($user->role === UserRole::SECRETARIAT_DIRECTION) {
            return $user->direction_id === $this->direction_id;
        }

        return $user->role->estDirecteurDirection()
            && $user->id === $this->directeur_id
            && $user->direction_id === $this->direction_id
            && in_array($this->statut, self::STATUTS_VISIBLES_DIRECTEUR, true)
            && $this->aEteSoumis();
    }

    public function scopeVisiblePour(Builder $query, User $user): Builder
    {
        return self::appliquerVisibilitePour($query, $user);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function appliquerVisibilitePour(Builder $query, User $user): Builder
    {
        $query->where('origine', DocumentProduitOrigine::AUTONOME->value);

        if ($user->role === UserRole::SECRETARIAT_DIRECTION) {
            return $query->where('direction_id', $user->direction_id);
        }

        return self::appliquerVisibiliteDirecteur($query, $user);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function scopeVisibleAuDirecteur(Builder $query, User $user): Builder
    {
        return self::appliquerVisibiliteDirecteur($query, $user);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    private static function appliquerVisibiliteDirecteur(Builder $query, User $user): Builder
    {
        if (! $user->role->estDirecteurDirection() || $user->direction_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('origine', DocumentProduitOrigine::AUTONOME->value)
            ->where('directeur_id', $user->id)
            ->where('direction_id', $user->direction_id)
            ->whereIn('statut', array_map(
                static fn (DocumentProduitStatut $statut): string => $statut->value,
                self::STATUTS_VISIBLES_DIRECTEUR,
            ))
            ->whereNotNull('soumis_at')
            ->whereNotNull('soumis_par_id');
    }

    private function aEteSoumis(): bool
    {
        return $this->soumis_at !== null && $this->soumis_par_id !== null;
    }
}
