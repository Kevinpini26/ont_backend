<?php

namespace Modules\Courrier\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Modules\Kernel\Contracts\DirectionScopeBypassResolver;

/**
 * Un courrier appartient à deux directions potentielles (origine et
 * destination) : une direction doit voir aussi bien ses courriers émis que
 * reçus, d'où un OR sur les deux colonnes plutôt que le DirectionScope
 * générique du Kernel (qui ne compare qu'une seule colonne direction_id).
 * La logique de contournement (DFP, admin, postes centraux) reste
 * déléguée au même DirectionScopeBypassResolver que le reste du système.
 */
class CourrierDirectionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        if (app(DirectionScopeBypassResolver::class)->bypasses($user)) {
            return;
        }

        $builder->where(function (Builder $query) use ($model, $user) {
            $query->where($model->qualifyColumn('direction_origine_id'), $user->direction_id)
                ->orWhere($model->qualifyColumn('direction_destination_id'), $user->direction_id)
                ->orWhereExists(function ($sous) use ($model, $user) {
                    $sous->selectRaw('1')
                        ->from('courrier_imputations')
                        ->whereColumn('courrier_imputations.courrier_id', $model->qualifyColumn('id'))
                        ->where('courrier_imputations.direction_id', $user->direction_id);
                })->orWhereExists(function ($sous) use ($model, $user) {
                    $sous->selectRaw('1')
                        ->from('dispatchs_courrier')
                        ->whereColumn('dispatchs_courrier.courrier_id', $model->qualifyColumn('id'))
                        ->where('dispatchs_courrier.type_destination', 'direction')
                        ->where('dispatchs_courrier.statut', 'execute')
                        ->where('dispatchs_courrier.direction_id', $user->direction_id);
                });
        });
    }
}
