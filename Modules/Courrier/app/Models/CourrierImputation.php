<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\MentionImputation;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class CourrierImputation extends Model
{
    protected $fillable = [
        'courrier_id',
        'direction_id',
        'mention',
        'est_principale',
        'imputee_par_id',
    ];

    protected function casts(): array
    {
        return [
            'mention' => MentionImputation::class,
            'est_principale' => 'boolean',
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

    public function imputeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imputee_par_id');
    }
}
