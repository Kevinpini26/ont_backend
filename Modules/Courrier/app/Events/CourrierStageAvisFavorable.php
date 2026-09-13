<?php

namespace Modules\Courrier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Courrier\Models\Courrier;

/**
 * Émis uniquement quand un courrier de type demande_stage reçoit un avis
 * favorable de la DG. Le payload transporte les informations du candidat
 * déjà saisies à la réception du courrier, afin que le module Stagiaires
 * puisse créer automatiquement la fiche sans ressaisie.
 */
class CourrierStageAvisFavorable
{
    use Dispatchable;

    public function __construct(
        public readonly Courrier $courrier,
    ) {}

    public function candidatNom(): ?string
    {
        return $this->courrier->candidat_nom;
    }

    /**
     * candidat_contact (téléphone) est facultatif au dépôt public — seul
     * candidat_email est requis (voir DeposerDemandeStageRequest). La fiche
     * Stagiaire exige pourtant un contact non nul : le téléphone prime
     * quand il existe, l'e-mail (garanti présent) sert de repli plutôt que
     * de faire échouer la création automatique de la fiche.
     */
    public function candidatContact(): ?string
    {
        return $this->courrier->candidat_contact ?: $this->courrier->candidat_email;
    }

    public function candidatEtablissement(): ?string
    {
        return $this->courrier->candidat_etablissement;
    }

    public function periodeDebutDemandee(): ?string
    {
        return $this->courrier->periode_souhaitee_debut?->toDateString();
    }

    public function periodeFinDemandee(): ?string
    {
        return $this->courrier->periode_souhaitee_fin?->toDateString();
    }

    public function referenceCourrier(): string
    {
        return $this->courrier->numero_accuse_reception;
    }

    public function typeStage(): ?string
    {
        return $this->courrier->type_stage;
    }

    public function cvChemin(): ?string
    {
        return $this->courrier->cv_chemin;
    }

    public function diplomeEtatChemin(): ?string
    {
        return $this->courrier->diplome_etat_chemin;
    }

    public function dernierDiplomeChemin(): ?string
    {
        return $this->courrier->dernier_diplome_chemin;
    }

    public function lettreDemandeChemin(): ?string
    {
        return $this->courrier->lettre_demande_chemin;
    }
}
