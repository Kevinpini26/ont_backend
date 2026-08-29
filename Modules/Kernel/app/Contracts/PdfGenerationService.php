<?php

namespace Modules\Kernel\Contracts;

/**
 * Point d'extension : rend une vue Blade en PDF. Capacité générique
 * partagée par tout le système (convention et attestation de stage,
 * rapport périodique tutelle...) — aucun code applicatif ne doit appeler
 * une librairie de génération PDF directement, tout passe par ce contrat,
 * pour que remplacer la librairie sous-jacente reste localisé à la seule
 * implémentation liée dans Modules\Kernel\Providers\KernelServiceProvider.
 */
interface PdfGenerationService
{
    /**
     * @param  array<string, mixed>  $donnees  Variables passées à la vue Blade.
     * @return string Contenu binaire du PDF généré (jamais un chemin ni une
     *                réponse HTTP — à l'appelant de décider s'il le stocke
     *                sur disque ou le sert en téléchargement).
     */
    public function genererDepuisVue(string $vue, array $donnees = []): string;
}
