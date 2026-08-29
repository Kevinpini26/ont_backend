<?php

namespace Modules\Kernel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\User;

class DatabaseAuditLogger implements AuditLogger
{
    public function enregistrer(string $action, ?Model $sujet = null, ?User $acteur = null, array $contexte = []): void
    {
        $requete = request();

        AuditLog::query()->create([
            'user_id' => $acteur?->id ?? Auth::id(),
            'action' => $action,
            'auditable_type' => $sujet?->getMorphClass(),
            'auditable_id' => $sujet?->getKey(),
            'description' => Arr::pull($contexte, 'description'),
            'ip_address' => $requete->ip(),
            'user_agent' => mb_substr((string) $requete->userAgent(), 0, 255),
            'meta' => $contexte === [] ? null : $contexte,
            'created_at' => now(),
        ]);
    }
}
