<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DgInterim extends Model
{
    protected $table = 'dg_interims';

    protected $fillable = [
        'dg_titulaire_id',
        'dga_interimaire_id',
        'motif',
        'started_at',
        'started_by_id',
        'ended_at',
        'ended_by_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function dgTitulaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dg_titulaire_id');
    }

    public function dgaInterimaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dga_interimaire_id');
    }

    public function ouvertPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function terminePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }
}
