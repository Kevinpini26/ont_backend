<?php

return [
    'name' => 'Stagiaires',

    // %d = année, %04d = compteur séquentiel — même convention que le
    // numéro d'attestation (voir StagiaireCircuitService::cloturerSiEvaluationsCompletes()).
    'format_matricule' => '%d-STG-%04d',
];
