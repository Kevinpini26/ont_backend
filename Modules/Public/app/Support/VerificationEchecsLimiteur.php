<?php

namespace Modules\Public\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Kernel\Contracts\AuditLogger;

/**
 * Verrouillage par identifiant (numéro de dossier, numéro d'attestation)
 * pour les points de vérification publics : la fenêtre glissante de
 * 'sensitive'/'public-lookup' (par IP) ne suffit pas seule à empêcher un
 * dictionnaire de noms tourné contre un seul numéro connu, potentiellement
 * distribué sur plusieurs IP. Cinq échecs sur le même identifiant en une
 * heure verrouillent cet identifiant précis pendant une heure, indépendamment
 * de l'IP appelante.
 */
class VerificationEchecsLimiteur
{
    private const MAX_ECHECS = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    public function estVerrouille(string $portee, string $identifiant): bool
    {
        return Cache::has($this->cleVerrou($portee, $identifiant));
    }

    public function enregistrerEchec(string $portee, string $identifiant, string $action, ?Model $sujet = null): void
    {
        $cleCompteur = $this->cleCompteur($portee, $identifiant);
        Cache::add($cleCompteur, 0, now()->addHour());
        $compteur = Cache::increment($cleCompteur);

        if ($compteur >= self::MAX_ECHECS) {
            Cache::put($this->cleVerrou($portee, $identifiant), true, now()->addHour());
        }

        $this->audit->enregistrer($action, $sujet, null, ['identifiant' => $identifiant]);
    }

    public function reinitialiser(string $portee, string $identifiant): void
    {
        Cache::forget($this->cleCompteur($portee, $identifiant));
        Cache::forget($this->cleVerrou($portee, $identifiant));
    }

    private function cleCompteur(string $portee, string $identifiant): string
    {
        return "public-verif-echecs:{$portee}:{$identifiant}";
    }

    private function cleVerrou(string $portee, string $identifiant): string
    {
        return "public-verif-verrou:{$portee}:{$identifiant}";
    }
}
