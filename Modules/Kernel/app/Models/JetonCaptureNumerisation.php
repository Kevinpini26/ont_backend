<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Jeton de capture mobile à usage unique et à durée courte (15 minutes) —
 * voir la migration create_jetons_capture_numerisation_table.
 */
class JetonCaptureNumerisation extends Model
{
    public $timestamps = false;

    protected $table = 'jetons_capture_numerisation';

    protected $fillable = [
        'capturable_type',
        'capturable_id',
        'token',
        'expire_at',
        'consomme_at',
        'cree_par_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expire_at' => 'datetime',
            'consomme_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function genererPour(Model $capturable, User $creePar, int $dureeMinutes = 15): self
    {
        return self::query()->create([
            'capturable_type' => $capturable->getMorphClass(),
            'capturable_id' => $capturable->getKey(),
            'token' => Str::random(48),
            'expire_at' => now()->addMinutes($dureeMinutes),
            'cree_par_id' => $creePar->id,
            'created_at' => now(),
        ]);
    }

    public function estValide(): bool
    {
        return $this->consomme_at === null && now()->lt($this->expire_at);
    }

    public function consommer(): void
    {
        $this->update(['consomme_at' => now()]);
    }

    public function capturable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
