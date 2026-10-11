<?php

namespace Modules\Courrier\Services;

use Illuminate\Validation\ValidationException;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class DirecteurDirectionResolver
{
    public function resolve(int $directionId): User
    {
        $directeurs = User::query()
            ->where('direction_id', $directionId)
            ->where('role', UserRole::DIRECTEUR_DIRECTION)
            ->get();

        if ($directeurs->isEmpty()) {
            $directeurs = User::query()
                ->where('direction_id', $directionId)
                ->where('role', UserRole::RESPONSABLE_DIRECTION)
                ->get();
        }

        if ($directeurs->count() !== 1) {
            throw ValidationException::withMessages([
                'directeur' => $directeurs->isEmpty()
                    ? 'Aucun Directeur actif unique n’est affecté à cette direction.'
                    : 'Plusieurs Directeurs sont affectés à cette direction. Corrigez l’organisation avant transmission.',
            ]);
        }

        return $directeurs->firstOrFail();
    }
}
