<?php

namespace Modules\Stagiaires\Contracts;

/**
 * Une notification "à la demande" (candidat/stagiaire sans compte
 * utilisateur) qui expose, en plus de son contenu email, un message court
 * utilisable tel quel par SMS — voir
 * StagiaireCircuitService::envoyerLienParCanalDisponible().
 */
interface NotificationAvecSms
{
    public function messageSms(): string;
}
