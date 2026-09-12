<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

/**
 * Lot B : trace d'un envoi de diffusion (retenu/non retenu) au candidat —
 * une ligne par envoi, jamais mise à jour. Voir
 * StagiaireCircuitService::notifierIssue()/renvoyerNotificationDiffusion().
 */
class NotificationDiffusion extends Model
{
    protected $table = 'notifications_diffusion';

    protected $fillable = [
        'stagiaire_id',
        'type',
        'canal',
        'destinataire',
        'contenu',
        'envoye_at',
        'statut_remise',
        'envoye_par_id',
    ];

    protected function casts(): array
    {
        return [
            'envoye_at' => 'datetime',
        ];
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class);
    }

    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par_id');
    }
}
