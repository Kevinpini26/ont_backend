<?php

namespace Modules\Courrier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Exceptions\EmpruntOriginalException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Models\EmpruntOriginal;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;

/**
 * Orchestre les transitions du circuit courrier : c'est l'unique point
 * d'entrée qui vérifie l'ordre strict des statuts et l'habilitation du
 * poste de l'utilisateur, en s'appuyant sur CircuitTransitionRules (point
 * d'extension) plutôt que sur une logique codée en dur.
 *
 * Façade fine : chaque groupe thématique de transitions vit dans son
 * propre service (CourrierReceptionService, CourrierTriDispatchService,
 * CourrierReponseService, CourrierEnvoiService), et les primitives
 * transverses (traçage, verrouillage, assertions d'habilitation) dans
 * CourrierCircuitHelpers — cette classe reste l'unique point d'entrée
 * utilisé par les contrôleurs, pour qu'aucun appelant n'ait à connaître
 * ce découpage interne.
 */
class CourrierCircuitService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
        private readonly DossierWorkflowGuard $dossiers,
        private readonly CourrierCircuitHelpers $helpers,
        private readonly CourrierReceptionService $reception,
        private readonly CourrierTriDispatchService $triDispatch,
        private readonly CourrierReponseService $reponse,
        private readonly CourrierEnvoiService $envoi,
    ) {}

    public function creer(User $auteur, array $donnees, bool $numerisationImpossible = false): Courrier
    {
        return $this->reception->creer($auteur, $donnees, $numerisationImpossible);
    }

    public function creerDepuisPublic(array $donnees): Courrier
    {
        return $this->reception->creerDepuisPublic($donnees);
    }

    public function creerCourrierExterneDepuisPublic(array $donnees): Courrier
    {
        return $this->reception->creerCourrierExterneDepuisPublic($donnees);
    }

    public function initierParDg(User $secretariat1, array $donnees): Courrier
    {
        return $this->reception->initierParDg($secretariat1, $donnees);
    }

    public function validerAvantDiffusion(Courrier $courrier, User $dg): Courrier
    {
        return $this->reception->validerAvantDiffusion($courrier, $dg);
    }

    public function classerDemandeStage(Courrier $courrier): void
    {
        $this->reception->classerDemandeStage($courrier);
    }

    public function accuserReception(Courrier $courrier, User $utilisateur): Courrier
    {
        return $this->reception->accuserReception($courrier, $utilisateur);
    }

    public function prendreEnChargeAutonomeSec1(DocumentProduitDirection $document, User $utilisateur): Courrier
    {
        return $this->reception->prendreEnChargeAutonomeSec1($document, $utilisateur);
    }

    public function transmettreAutonomeDgDepuisSec1(DocumentProduitDirection $document, User $utilisateur): Courrier
    {
        return $this->reception->transmettreAutonomeDgDepuisSec1($document, $utilisateur);
    }

    public function transmettreTri(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return $this->triDispatch->transmettreTri($courrier, $utilisateur, $instruction);
    }

    public function grouperEnBordereauLot(array $courrierIds, User $emetteur): BordereauLot
    {
        return $this->triDispatch->grouperEnBordereauLot($courrierIds, $emetteur);
    }

    public function accuserReceptionLot(BordereauLot $bordereau, User $destinataire): BordereauLot
    {
        return $this->triDispatch->accuserReceptionLot($bordereau, $destinataire);
    }

    public function transmettreEnAttenteAvisDg(Courrier $courrier, User $utilisateur, DegreUrgence $degreUrgence, ?string $instruction = null): Courrier
    {
        return $this->triDispatch->transmettreEnAttenteAvisDg($courrier, $utilisateur, $degreUrgence, $instruction);
    }

    public function transmettreDepuisClasseur(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return $this->triDispatch->transmettreDepuisClasseur($courrier, $utilisateur, $instruction);
    }

    public function requalifierUrgence(Courrier $courrier, User $dg, DegreUrgence $nouveauDegre): Courrier
    {
        return $this->triDispatch->requalifierUrgence($courrier, $dg, $nouveauDegre);
    }

    public function renvoyerAuTri(Courrier $courrier, User $utilisateur, ?string $motif): Courrier
    {
        return $this->triDispatch->renvoyerAuTri($courrier, $utilisateur, $motif);
    }

    public function transmettreRetourVersSec1(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return $this->triDispatch->transmettreRetourVersSec1($courrier, $utilisateur, $instruction);
    }

    public function dispatcherVersDirection(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return $this->triDispatch->dispatcherVersDirection($courrier, $utilisateur, $instruction);
    }

    public function rendreAvisDg(Courrier $courrier, User $utilisateur, AvisDg $avis, ?string $commentaire): Courrier
    {
        return $this->triDispatch->rendreAvisDg($courrier, $utilisateur, $avis, $commentaire);
    }

    public function soumettreProjetReponse(Courrier $courrier, User $utilisateur, array $projetReponseContenu, int $relecteurId): Courrier
    {
        return $this->reponse->soumettreProjetReponse($courrier, $utilisateur, $projetReponseContenu, $relecteurId);
    }

    /** Crée le brouillon sortant D rattaché à une mission DG nominative. */
    public function creerProjetReponseMission(MissionDocumentaire $mission, User $assistant, array $donnees): Courrier
    {
        return $this->reponse->creerProjetReponseMission($mission, $assistant, $donnees);
    }

    public function sauvegarderProjetReponseMission(MissionDocumentaire $mission, User $assistant, array $donnees): Courrier
    {
        return $this->reponse->sauvegarderProjetReponseMission($mission, $assistant, $donnees);
    }

    public function soumettreProjetReponseMission(MissionDocumentaire $mission, User $assistant, array $projetReponseContenu): Courrier
    {
        return $this->reponse->soumettreProjetReponseMission($mission, $assistant, $projetReponseContenu);
    }

    public function validerRelecture(Courrier $courrier, User $utilisateur, ?string $commentaire): Courrier
    {
        return $this->reponse->validerRelecture($courrier, $utilisateur, $commentaire);
    }

    public function renvoyerPourCorrection(Courrier $courrier, User $utilisateur, string $observation): Courrier
    {
        return $this->reponse->renvoyerPourCorrection($courrier, $utilisateur, $observation);
    }

    public function signer(Courrier $courrier, User $utilisateur): Courrier
    {
        return $this->reponse->signer($courrier, $utilisateur);
    }

    public function validerPourSignature(Courrier $courrier, User $utilisateur): Courrier
    {
        return $this->reponse->validerPourSignature($courrier, $utilisateur);
    }

    public function finaliserSignaturePhysique(Courrier $courrier, User $utilisateur, UploadedFile $scan): Courrier
    {
        return $this->reponse->finaliserSignaturePhysique($courrier, $utilisateur, $scan);
    }

    /**
     * Numérote et classe un courrier entrant (circuit normal ou dépôt
     * public pas encore pris en charge par la Réception). Reste ici — pas
     * dans CourrierReceptionService — car genererCoteClassement() ci-dessous
     * n'est utilisée que par cette méthode : les deux voyagent ensemble.
     */
    public function enregistrer(
        Courrier $courrier,
        User $utilisateur,
        CourrierClassification $classification,
        ?string $noteTechnique,
        ?string $accuseReceptionPartenaire,
        ?string $emplacementPhysique = null,
    ): Courrier {
        return DB::transaction(function () use ($courrier, $utilisateur, $classification, $noteTechnique, $accuseReceptionPartenaire, $emplacementPhysique) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception === ModeReception::DEPOT_EN_LIGNE) {
                if ($utilisateur->poste !== Poste::RECEPTION) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
                if ($courrier->enregistre_at !== null) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Ce dépôt public est déjà enregistré.',
                    ]);
                }
                if ($courrier->estNonRecevable() || $courrier->estAnnule()) {
                    throw ValidationException::withMessages(['courrier' => 'Ce dépôt est clos (non recevable ou annulé).']);
                }

                // Lot 6 : le numéro d'arrivée existe déjà depuis le dépôt ; un ancien dépôt (accusé AR- seul)
                // reçoit ici son numéro d'arrivée. Jamais de second numéro.
                $nouveauNumero = $courrier->numero_enregistrement === null;
                $courrier->numero_enregistrement ??= $this->numeros->genererNumeroEnregistrement();
                $courrier->classification = $classification;
                $courrier->note_technique = $noteTechnique;
                $courrier->accuse_reception_partenaire = $accuseReceptionPartenaire;
                $courrier->cote_classement = $this->genererCoteClassement($courrier);
                if ($emplacementPhysique !== null) {
                    $courrier->emplacement_physique = $emplacementPhysique;
                }
                $courrier->enregistre_at = now();
                $courrier->save();
                if ($nouveauNumero) {
                    $this->helpers->auditerIdentite($courrier, $utilisateur, 'numero_enregistrement', $courrier->numero_enregistrement);
                }
                $this->audit->enregistrer('courrier.depot_verifie', $courrier, $utilisateur, [
                    'numero_enregistrement' => $courrier->numero_enregistrement,
                ]);

                return $courrier;
            }

            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::ENREGISTRE);

            $courrier->classification = $classification;
            $courrier->note_technique = $noteTechnique;
            $courrier->accuse_reception_partenaire = $accuseReceptionPartenaire;
            $courrier->cote_classement = $this->genererCoteClassement($courrier);
            if ($emplacementPhysique !== null) {
                $courrier->emplacement_physique = $emplacementPhysique;
            }
            $courrier->enregistre_at = now();
            $courrier->statut = CourrierStatut::ENREGISTRE;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    private function genererCoteClassement(Courrier $courrier): string
    {
        $codeDirection = $courrier->directionPrincipale()?->direction?->code ?? 'ONT';

        if ($courrier->numero_enregistrement === null) {
            return $this->numeros->genererCote($codeDirection, 'classement_courrier');
        }

        [$annee, $sequence] = array_pad(explode('-', (string) $courrier->numero_enregistrement, 2), 2, '0000');

        return strtr(config('courrier.format_cote_classement', '{direction}-{annee}-{sequence}'), [
            '{direction}' => $codeDirection,
            '{annee}' => $annee,
            '{sequence}' => $sequence,
        ]);
    }

    public function initierReponseSortante(User $auteur, Courrier $original, array $donnees): Courrier
    {
        return $this->envoi->initierReponseSortante($auteur, $original, $donnees);
    }

    public function initierCourrierSortant(User $auteur, array $donnees): Courrier
    {
        return $this->envoi->initierCourrierSortant($auteur, $donnees);
    }

    public function choisirModeSortie(Courrier $courrier, User $utilisateur, ModeSortie $mode): Courrier
    {
        return $this->envoi->choisirModeSortie($courrier, $utilisateur, $mode);
    }

    public function envoyerParCourriel(Courrier $courrier, User $utilisateur): Courrier
    {
        return $this->envoi->envoyerParCourriel($courrier, $utilisateur);
    }

    public function rendreDisponiblePourRetrait(Courrier $courrier, User $utilisateur, ?string $observation): Courrier
    {
        return $this->envoi->rendreDisponiblePourRetrait($courrier, $utilisateur, $observation);
    }

    public function confirmerRemisePhysique(
        Courrier $courrier,
        User $utilisateur,
        string $remisA,
        ?string $observation,
        ?UploadedFile $decharge,
    ): Courrier {
        return $this->envoi->confirmerRemisePhysique($courrier, $utilisateur, $remisA, $observation, $decharge);
    }

    public function envoyer(Courrier $courrier, User $utilisateur, array $donnees): Courrier
    {
        return $this->envoi->envoyer($courrier, $utilisateur, $donnees);
    }

    /**
     * Preuve de remise — un courrier sortant confié à un porteur n'est
     * réellement délivré qu'une fois la décharge signée rapportée.
     * Distinct de l'envoi lui-même : un courrier peut être "envoyé" (parti
     * de l'Office) sans que la remise soit encore confirmée.
     */
    public function enregistrerRemise(Courrier $courrier, User $utilisateur, array $donnees): Courrier
    {
        return $this->envoi->enregistrerRemise($courrier, $utilisateur, $donnees);
    }

    /**
     * @param  array<string, mixed>  $attributs  type, destinataire_poste, destinataire_direction_id, visibilite (voir CourrierAnnotationService)
     */
    public function ajouterAnnotation(Courrier $courrier, User $auteur, string $contenu, array $attributs = []): CourrierAnnotation
    {
        return DB::transaction(function () use ($courrier, $auteur, $contenu, $attributs) {
            if ($courrier->dossier_id === null) {
                throw ValidationException::withMessages([
                    'dossier' => 'Une annotation métier exige un dossier actif.',
                ]);
            }

            User::query()->sharedLock()->findOrFail($auteur->id);
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($courrier->dossier_id);
            $this->dossiers->assertActifPourNouvelleActivite($dossier);

            /** @var CourrierAnnotation $annotation */
            $annotation = $courrier->annotations()->create([
                'auteur_id' => $auteur->id,
                'contenu' => $contenu,
            ] + $attributs);

            $this->audit->enregistrer('courrier.annotation_creee', $courrier, $auteur, [
                'annotation_id' => $annotation->id,
                'type' => $annotation->type->value,
                'visibilite' => $annotation->visibilite->value,
                // Le contenu reste dans la table métier protégée ; le journal
                // ne conserve qu'une donnée non sensible utile au contrôle.
                'contenu_longueur' => mb_strlen($contenu),
            ]);

            return $annotation;
        });
    }

    /**
     * Sortie de l'original physique — voir docs/numerisation-courrier.md
     * (Lot 5). Refuse une nouvelle sortie tant que la précédente n'a pas
     * été restituée : un original ne peut matériellement être qu'à un seul
     * endroit à la fois.
     */
    public function sortirOriginal(Courrier $courrier, User $utilisateur, string $motif): EmpruntOriginal
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $motif) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if ($courrier->empruntEnCours() !== null) {
                throw EmpruntOriginalException::dejaEmprunte();
            }

            /** @var EmpruntOriginal $emprunt */
            $emprunt = $courrier->empruntsOriginaux()->create([
                'emprunte_par_id' => $utilisateur->id,
                'emprunte_le' => now(),
                'motif' => $motif,
            ]);

            $this->audit->enregistrer('courrier.original_sorti', $courrier, $utilisateur, [
                'emprunt_id' => $emprunt->id,
                'emprunte_le' => $emprunt->emprunte_le?->toIso8601String(),
            ]);

            return $emprunt;
        });
    }

    public function restituerOriginal(Courrier $courrier, User $utilisateur): EmpruntOriginal
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $emprunt = $courrier->empruntEnCours();

            if ($emprunt === null) {
                throw EmpruntOriginalException::aucunEmpruntEnCours();
            }

            $emprunt->update(['restitue_le' => now(), 'restitue_par_id' => $utilisateur->id]);

            $this->audit->enregistrer('courrier.original_restitue', $courrier, $utilisateur, [
                'emprunt_id' => $emprunt->id,
                'emprunte_par_id' => $emprunt->emprunte_par_id,
                'restitue_le' => $emprunt->restitue_le?->toIso8601String(),
            ]);

            return $emprunt;
        });
    }
}
