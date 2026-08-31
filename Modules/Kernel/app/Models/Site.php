<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Kernel\Database\Factories\SiteFactory;

/**
 * Site physique de l'ONT (siège ou représentation provinciale) — voir la
 * migration create_sites_table pour ce qui est volontairement hors
 * périmètre de cette préparation multi-site.
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $fillable = ['nom', 'ville', 'province', 'siege', 'actif'];

    protected function casts(): array
    {
        return [
            'siege' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }

    public function directions(): HasMany
    {
        return $this->hasMany(Direction::class);
    }
}
