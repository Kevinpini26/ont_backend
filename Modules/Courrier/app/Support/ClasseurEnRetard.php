<?php

namespace Modules\Courrier\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Setting;
use Modules\Kernel\Support\CalendrierOuvre;

/**
 * Lot 6 : un courrier « normal » ne reste pas au classeur d'attente de SEC1 sans limite. Le délai se compte
 * en heures ouvrées depuis la mise au classeur (réglage courrier.classeur_delai_heures, 14 h provisoires).
 * Signal seulement : SEC1 reste seul à décider de transmettre le courrier à la DG.
 */
class ClasseurEnRetard
{
    public const DELAI_PAR_DEFAUT = 14;

    public static function delaiHeures(): int
    {
        return (int) Setting::valeur('courrier.classeur_delai_heures', self::DELAI_PAR_DEFAUT);
    }

    public static function seuil(): Carbon
    {
        return CalendrierOuvre::depuisReglages()->seuil(self::delaiHeures());
    }

    /** @param  Builder<Courrier>  $query */
    public static function filtrer(Builder $query): Builder
    {
        $seuil = self::seuil();

        return $query
            ->where('statut', CourrierStatut::EN_ATTENTE_CLASSEUR->value)
            ->whereNull('annule_at')
            ->whereHas('transitions', fn ($t) => $t
                ->where('statut', CourrierStatut::EN_ATTENTE_CLASSEUR->value)
                ->where('created_at', '<', $seuil)
                ->whereNotExists(fn ($plusRecente) => $plusRecente->selectRaw('1')
                    ->from('courrier_transitions as ct2')
                    ->whereColumn('ct2.courrier_id', 'courrier_transitions.courrier_id')
                    ->whereColumn('ct2.id', '>', 'courrier_transitions.id')));
    }

    public static function estEnRetard(Courrier $courrier): bool
    {
        if ($courrier->statut !== CourrierStatut::EN_ATTENTE_CLASSEUR) {
            return false;
        }
        $miseAuClasseur = $courrier->transitions->where('statut', CourrierStatut::EN_ATTENTE_CLASSEUR)->sortByDesc('id')->first();

        return $miseAuClasseur !== null && $miseAuClasseur->created_at->lt(self::seuil());
    }
}
