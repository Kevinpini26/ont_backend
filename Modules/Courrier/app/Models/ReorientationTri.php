<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

class ReorientationTri extends Model
{
    public $timestamps = false;

    protected $table = 'reorientations_tri';

    protected $fillable = [
        'courrier_id',
        'trie_par_id',
        'reoriente_par_id',
        'motif',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function triePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trie_par_id');
    }

    public function reorientePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reoriente_par_id');
    }
}
