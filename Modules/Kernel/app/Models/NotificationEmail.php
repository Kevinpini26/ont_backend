<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Kernel\Enums\StatutNotificationEmail;

/**
 * Suivi de chaque e-mail externe mis en file via
 * Modules\Kernel\Contracts\NotificationService::envoyerMail() — tenu à
 * jour par Modules\Kernel\Jobs\EnvoyerNotificationEmailJob, jamais écrit
 * directement par un appelant métier.
 */
class NotificationEmail extends Model
{
    protected $table = 'notifications_email';

    protected $fillable = [
        'type',
        'destinataire',
        'notifiable_type',
        'notifiable_id',
        'statut',
        'job_uuid',
        'tentatives',
        'derniere_erreur',
        'envoye_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutNotificationEmail::class,
            'tentatives' => 'integer',
            'envoye_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
