<?php

namespace Modules\Courrier\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Modules\Courrier\Services\CourrierVisibilityService;

/**
 * Applique à toutes les requêtes authentifiées la même matrice que la
 * policy. La visibilité provient d'une relation métier, jamais du seul fait
 * d'être connecté ou d'appartenir à une direction quelconque.
 */
class CourrierDirectionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        app(CourrierVisibilityService::class)->scopeVisiblePour($builder, $user);
    }
}
