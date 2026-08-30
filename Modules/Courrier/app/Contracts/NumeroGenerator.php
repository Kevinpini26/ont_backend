<?php

namespace Modules\Courrier\Contracts;

interface NumeroGenerator
{
    public function genererAccuseReception(): string;

    public function genererNumeroEnregistrement(): string;

    /**
     * Registre départ (courrier sortant) — préparé au lot 1, consommé à
     * partir du lot 2 (le courrier de réponse n'existe pas encore comme
     * enregistrement à part entière). Format provisoire, voir
     * docs/questions-ont.md.
     */
    public function genererNumeroDepart(): string;
}
