<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Kernel\Database\Factories\DirectionFactory;

class Direction extends Model
{
    /** @use HasFactory<DirectionFactory> */
    use HasFactory;

    protected $fillable = ['code', 'nom', 'actif', 'est_operationnelle', 'capacite_max', 'site_id'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'est_operationnelle' => 'boolean',
            'capacite_max' => 'integer',
        ];
    }

    protected static function newFactory(): DirectionFactory
    {
        return DirectionFactory::new();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
