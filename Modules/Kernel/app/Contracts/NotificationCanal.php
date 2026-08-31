<?php

namespace Modules\Kernel\Contracts;

/**
 * Un canal de notification texte (SMS aujourd'hui, éventuellement WhatsApp
 * ou autre demain) capable de délivrer un message court à un contact brut
 * — complémentaire au courriel, pas un remplacement : voir
 * envoyerLienParCanalDisponible() dans StagiaireCircuitService, qui choisit
 * le canal selon la forme du contact renseigné (`Stagiaire::contact` /
 * `Courrier::candidat_contact` ne distinguent pas email et téléphone à la
 * saisie — voir docs/questions-ont.md).
 */
interface NotificationCanal
{
    /**
     * Vrai si ce canal sait délivrer un message à ce contact (ex: un
     * numéro de téléphone pour le canal SMS).
     */
    public function gere(string $contact): bool;

    public function envoyer(string $contact, string $message): void;
}
