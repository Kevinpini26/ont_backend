<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Facades\DB;
use Modules\Courrier\Contracts\SequenceGenerator;

class DatabaseSequenceGenerator implements SequenceGenerator
{
    public function suivant(string $type, int $annee): int
    {
        // Upsert atomique (INSERT ... ON CONFLICT) : évite toute condition de
        // course entre deux enregistrements concurrents, y compris pour le
        // tout premier numéro de l'année.
        $ligne = DB::selectOne(
            <<<'SQL'
                insert into courrier_numero_compteurs (type, annee, dernier_compteur)
                values (?, ?, 1)
                on conflict (type, annee) where direction_id is null
                do update set dernier_compteur = courrier_numero_compteurs.dernier_compteur + 1
                returning dernier_compteur
            SQL,
            [$type, $annee],
        );

        return (int) $ligne->dernier_compteur;
    }

    public function suivantPourDirection(string $type, int $annee, int $directionId): int
    {
        $ligne = DB::selectOne(
            <<<'SQL'
                insert into courrier_numero_compteurs (type, annee, direction_id, dernier_compteur)
                values (?, ?, ?, 1)
                on conflict (type, annee, direction_id) where direction_id is not null
                do update set dernier_compteur = courrier_numero_compteurs.dernier_compteur + 1
                returning dernier_compteur
            SQL,
            [$type, $annee, $directionId],
        );

        return (int) $ligne->dernier_compteur;
    }
}
