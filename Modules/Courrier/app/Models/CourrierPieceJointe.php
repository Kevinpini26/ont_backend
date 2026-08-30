<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

class CourrierPieceJointe extends Model
{
    // Convention Eloquent par défaut ("courrier_piece_jointes") ne
    // correspond pas au nom de table réel ("courrier_pieces_jointes",
    // pluriel sur "pieces" et non sur "jointe").
    protected $table = 'courrier_pieces_jointes';

    protected $fillable = [
        'courrier_id',
        'libelle',
        'chemin',
        'type_mime',
        'taille_octets',
        'ordre',
        'uploaded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'ordre' => 'integer',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function uploadedPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
