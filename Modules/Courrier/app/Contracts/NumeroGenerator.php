<?php

namespace Modules\Courrier\Contracts;

use Modules\Kernel\Models\Direction;

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

    public function genererReferenceDocumentaire(Direction $direction, ?int $annee = null): string;

    /**
     * Lot C (transmission par lot) — numéro du bordereau qui porte
     * plusieurs dossiers à la fois, distinct du numéro d'accusé de
     * réception (qui reste propre à chaque courrier).
     */
    public function genererNumeroBordereauLot(): string;

    /**
     * Lot D (classement retrouvable) — cote de classement générique,
     * indépendante d'un numéro d'enregistrement préexistant (contrairement
     * à celle calquée sur `numero_enregistrement`, voir
     * CourrierCircuitService::genererCoteClassement()) : une séquence
     * dédiée par `$cleSequence`, pour classer un objet qui n'a jamais lui-
     * même de numéro d'enregistrement (une demande de stage restée en
     * dispatch, un tableau de répartition).
     */
    public function genererCote(string $codeDirection, string $cleSequence): string;
}
