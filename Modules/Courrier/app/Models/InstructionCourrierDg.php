<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

class InstructionCourrierDg extends Model
{
    protected $table = 'instructions_courrier_dg';

    protected $fillable = [
        'donneur_id', 'destinataire_user_id', 'instruction', 'expire_at',
        'ouvert_at', 'ouvert_par_id', 'consomme_at', 'consomme_par_id',
        'annule_at', 'courrier_id',
    ];

    protected function casts(): array
    {
        return [
            'expire_at' => 'datetime',
            'ouvert_at' => 'datetime',
            'consomme_at' => 'datetime',
            'annule_at' => 'datetime',
        ];
    }

    public function active(): bool
    {
        return $this->annule_at === null
            && $this->consomme_at === null
            && ($this->expire_at === null || $this->expire_at->isFuture());
    }

    public function donneur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donneur_id');
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_user_id');
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }
}
