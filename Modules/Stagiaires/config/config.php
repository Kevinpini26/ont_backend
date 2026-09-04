<?php

return [
    'name' => 'Stagiaires',

    // %d = année, %04d = compteur séquentiel — même convention que le
    // numéro d'attestation (voir StagiaireCircuitService::cloturerSiEvaluationsCompletes()).
    'format_matricule' => '%d-STG-%04d',

    /**
     * Lot 4 (tableau de répartition) : la DFP n'est jamais rattachée à une
     * direction (voir UserRole::rolesRequiringDirection()) — "la direction
     * qui établit le tableau" (voir TableauRepartition::direction) est donc
     * résolue par ce code plutôt que lue sur l'utilisateur connecté.
     */
    'direction_dfp_code' => 'DFP',
];
