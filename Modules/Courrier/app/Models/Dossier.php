<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Kernel\Models\User;

class Dossier extends Model
{
    protected $fillable = ['libelle', 'created_by', 'importe_historique', 'statut_archivage', 'archivage_decide_par_id', 'archivage_decide_at', 'archive_par_id', 'archive_at'];

    protected function casts(): array
    {
        return ['importe_historique' => 'boolean', 'archivage_decide_at' => 'datetime', 'archive_at' => 'datetime'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Courrier::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
