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

    /**
     * Lot A (tableau généré, non ressaisi) : un dossier est éligible à un
     * tableau seulement si son courrier porteur a été imputé à la DFP —
     * voir docs/questions-ont.md. Si la DG rend un avis favorable sans
     * jamais imputer explicitement (le geste reste distinct, voir Lot 3),
     * ce réglage impute automatiquement le courrier à la DFP ("pour
     * attribution") au moment de l'avis favorable, pour qu'aucune demande
     * de stage ne reste orpheline de tableau faute d'un geste manuel
     * oublié. À désactiver si l'ONT confirme que la DG doit toujours
     * imputer elle-même.
     */
    'imputation_automatique_dfp' => true,

    /**
     * Lot A : plusieurs tableaux par période (dont des compléments pour
     * les dossiers arrivés après coup) sont autorisés par défaut — voir
     * docs/questions-ont.md. À activer si l'ONT préfère un tableau unique
     * par période.
     */
    'tableau_un_seul_par_periode' => false,

    /**
     * Lot B : motifs de refus proposés à la DFP, liste ouverte (texte
     * libre facultatif en complément, voir
     * TableauRepartitionLigne::motif_non_retenu_libre) — voir
     * docs/questions-ont.md, valeurs à faire valider par la DFP.
     */
    'motifs_non_retenu' => [
        'places_epuisees' => 'Places épuisées',
        'dossier_incomplet' => 'Dossier incomplet',
        'profil_sans_correspondance' => 'Profil sans correspondance avec les besoins',
        'hors_periode' => 'Hors période',
    ],
];
