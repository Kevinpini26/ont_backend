<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;

class MissionDocumentaire extends Model
{
    protected $table = 'missions_documentaires';

    protected $fillable = [
        'courrier_id', 'dossier_id', 'demandeur_id', 'demandeur_poste',
        'autorite_poste', 'assistant_id', 'instruction', 'statut',
        'envoyee_at', 'prise_en_charge_at', 'compte_rendu',
        'projet_reponse_contenu', 'retournee_at', 'annulee_par_id',
        'motif_annulation', 'annulee_at',
    ];

    protected function casts(): array
    {
        return [
            'demandeur_poste' => Poste::class,
            'autorite_poste' => Poste::class,
            'statut' => MissionDocumentaireStatut::class,
            'envoyee_at' => 'datetime',
            'prise_en_charge_at' => 'datetime',
            'projet_reponse_contenu' => 'array',
            'retournee_at' => 'datetime',
            'annulee_at' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    public function annuleePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par_id');
    }
}
