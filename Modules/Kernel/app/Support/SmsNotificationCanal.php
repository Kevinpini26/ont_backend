<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Kernel\Contracts\NotificationCanal;

/**
 * Canal SMS — complément du courriel pour les destinataires qui n'ont
 * renseigné qu'un numéro de téléphone (candidat, stagiaire, expéditeur
 * externe : voir Stagiaire::contact/Courrier::candidat_contact, qui ne
 * distinguent pas email et téléphone à la saisie).
 *
 * Aucun fournisseur SMS n'est choisi par l'ONT à ce jour (voir
 * docs/questions-ont.md) : le pilote de passerelle est donc configurable
 * (`config('kernel.sms.driver')`) plutôt que codé en dur sur un
 * fournisseur précis.
 * - 'log' (par défaut) : consigne le message sans l'envoyer réellement —
 *   permet au reste du système de fonctionner en attendant un choix de
 *   fournisseur.
 * - 'http' : passerelle HTTP générique, dont l'URL et les champs de
 *   requête sont eux-mêmes configurables une fois un fournisseur retenu.
 */
class SmsNotificationCanal implements NotificationCanal
{
    /**
     * Un contact est traité comme un numéro de téléphone s'il n'est pas
     * une adresse email valide et contient au moins 8 chiffres — heuristique
     * volontairement simple, la validation stricte du format international
     * relevant du fournisseur SMS retenu.
     */
    public function gere(string $contact): bool
    {
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return preg_match('/[0-9]{8,}/', preg_replace('/[\s.\-()]/', '', $contact)) === 1;
    }

    public function envoyer(string $contact, string $message): void
    {
        match (config('kernel.sms.driver', 'log')) {
            'http' => $this->envoyerViaHttp($contact, $message),
            default => Log::info('SMS (canal "log", aucun fournisseur configuré)', [
                'destinataire' => $contact,
                'message' => $message,
            ]),
        };
    }

    private function envoyerViaHttp(string $contact, string $message): void
    {
        Http::asForm()->post(config('kernel.sms.http_url'), [
            ...config('kernel.sms.http_params_supplementaires', []),
            config('kernel.sms.http_champ_destinataire', 'to') => $contact,
            config('kernel.sms.http_champ_message', 'message') => $message,
        ]);
    }
}
