<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Support\ReferenceDocumentaire;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Circuit de réception d'un document produit par une direction : transmis
 * par le secrétariat producteur, puis accepté (ou renvoyé en correction)
 * par la Réception, qui l'introduit dans le circuit courrier normal
 * (statut RECU, bordereau vers SEC1).
 */
class DocumentProduitReceptionService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
        private readonly DocumentProduitScanOfficielService $scansOfficiels,
        private readonly DocumentProduitDirectionHelpers $helpers,
    ) {}

    public function transmettreReception(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $secretariat) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->origine === DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::VALIDE
                || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION
                || $secretariat->direction_id !== $document->direction_id) {
                throw ValidationException::withMessages(['document' => 'Seul le document validé peut être transmis à la Réception.']);
            }
            $document->update(['statut' => DocumentProduitStatut::TRANSMIS_RECEPTION, 'transmis_reception_par_id' => $secretariat->id, 'transmis_reception_at' => now()]);
            $this->audit->enregistrer('document_direction.transmis_reception', $document, $secretariat);

            return $this->helpers->charger($document);
        });
    }

    public function transmettreAutonomeReception(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $secretariat) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $secretariat = User::query()->lockForUpdate()->findOrFail($secretariat->id);

            if ($document->statut === DocumentProduitStatut::TRANSMIS_RECEPTION) {
                throw new ConflictHttpException('Ce document a déjà été transmis à la Réception.');
            }

            if ($document->origine !== DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::SCAN_ENREGISTRE
                || $document->traitement_direction_id !== null
                || $document->document_source_id !== null
                || $document->courrier_id === null
                || $document->recu_par_id !== null
                || $document->recu_at !== null
                || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION
                || $secretariat->direction_id !== $document->direction_id) {
                throw ValidationException::withMessages([
                    'document' => 'Seul le secrétariat producteur peut transmettre un document autonome avec scan officiel enregistré.',
                ]);
            }

            $premiereTransmission = $document->transmis_reception_par_id === null
                && $document->transmis_reception_at === null;
            $retransmissionApresCorrection = $document->transmis_reception_par_id !== null
                && $document->transmis_reception_at !== null
                && $document->correction_demandee_at !== null;
            if (! $premiereTransmission && ! $retransmissionApresCorrection) {
                throw ValidationException::withMessages([
                    'document' => 'Les métadonnées de transmission ne permettent pas de transmettre ce document.',
                ]);
            }

            $courrier = Courrier::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($document->courrier_id);

            if ($courrier->direction_origine_id !== $document->direction_id
                || $courrier->reference_documentaire === null
                || trim($courrier->reference_documentaire) === ''
                || ! ReferenceDocumentaire::estValide($courrier->reference_documentaire)
                || $courrier->statut !== CourrierStatut::BROUILLON_DIRECTION
                || $courrier->numero_enregistrement !== null
                || $courrier->transitions()->exists()
                || $courrier->dispatchs()->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'Le Courrier autonome ne possède pas une référence documentaire valide ou ne satisfait pas les conditions de transmission.',
                ]);
            }

            $this->scansOfficiels->verifierPourTransmission($document);

            $transmisAt = now();
            $document->update([
                'statut' => DocumentProduitStatut::TRANSMIS_RECEPTION,
                'transmis_reception_par_id' => $secretariat->id,
                'transmis_reception_at' => $transmisAt,
            ]);
            $this->audit->enregistrer('document_direction.autonome_transmis_reception', $document, $secretariat, [
                'courrier_id' => $courrier->id,
                'reference_documentaire' => $courrier->reference_documentaire,
                'transmis_reception_at' => $transmisAt->toIso8601String(),
            ]);

            return $this->helpers->charger($document);
        });
    }

    public function accepterAutonomeReception(DocumentProduitDirection $document, User $agent): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $agent) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $agent = User::query()->lockForUpdate()->findOrFail($agent->id);

            if ($document->origine !== DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::TRANSMIS_RECEPTION
                || $document->transmis_reception_par_id === null
                || $document->transmis_reception_at === null
                || $document->scan_officiel_document_id === null
                || $document->recu_par_id !== null
                || $document->recu_at !== null
                || $agent->role !== UserRole::AGENT_CIRCUIT_COURRIER
                || $agent->poste !== Poste::RECEPTION) {
                throw new ConflictHttpException('Ce document ne peut plus être accepté par la Réception.');
            }

            $courrier = Courrier::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($document->courrier_id);

            if ($courrier->direction_origine_id !== $document->direction_id
                || $courrier->statut !== CourrierStatut::BROUILLON_DIRECTION
                || $courrier->reference_documentaire === null
                || ! ReferenceDocumentaire::estValide($courrier->reference_documentaire)
                || $courrier->numero_enregistrement !== null
                || $courrier->transitions()->exists()
                || $courrier->dispatchs()->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'Le Courrier autonome ne satisfait plus les conditions d’acceptation.',
                ]);
            }

            $this->scansOfficiels->verifierPourAcceptation($document);

            $numeroEnregistrement = $this->numeros->genererNumeroEnregistrement();
            $dateReception = now();
            $courrier->numero_enregistrement = $numeroEnregistrement;
            $courrier->statut = CourrierStatut::RECU;
            $courrier->save();

            CourrierTransition::query()->create([
                'courrier_id' => $courrier->id,
                'statut' => CourrierStatut::RECU,
                'nouveau_statut' => CourrierStatut::RECU,
                'tour' => $courrier->tour,
                'changed_by_id' => $agent->id,
                'expediteur_poste' => Poste::RECEPTION->value,
                'destinataire_poste' => Poste::SECRETARIAT_1->value,
                'created_at' => $dateReception,
            ]);

            $document->update([
                'statut' => DocumentProduitStatut::ENTRE_CIRCUIT,
                'recu_par_id' => $agent->id,
                'recu_at' => $dateReception,
            ]);
            $this->audit->enregistrer('document_direction.autonome_accepte_reception', $document, $agent, [
                'courrier_id' => $courrier->id,
                'numero_enregistrement' => $numeroEnregistrement,
                'reference_documentaire' => $courrier->reference_documentaire,
                'etape_suivante' => Poste::SECRETARIAT_1->value,
                'prise_en_charge_sec1' => false,
            ]);

            return $this->helpers->charger($document->fresh());
        }, attempts: 1);
    }

    public function demanderCorrectionAutonomeReception(
        DocumentProduitDirection $document,
        User $agent,
        string $motif,
    ): DocumentProduitDirection {
        return DB::transaction(function () use ($document, $agent, $motif) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $agent = User::query()->lockForUpdate()->findOrFail($agent->id);

            if ($document->origine !== DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::TRANSMIS_RECEPTION
                || $document->transmis_reception_par_id === null
                || $document->transmis_reception_at === null
                || $document->recu_par_id !== null
                || $document->recu_at !== null
                || $agent->role !== UserRole::AGENT_CIRCUIT_COURRIER
                || $agent->poste !== Poste::RECEPTION
                || trim($motif) === '') {
                throw new ConflictHttpException('Ce document ne peut plus faire l’objet d’une demande de correction.');
            }

            $courrier = Courrier::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($document->courrier_id);

            if ($courrier->direction_origine_id !== $document->direction_id
                || $courrier->numero_enregistrement !== null
                || $courrier->transitions()->exists()
                || $courrier->dispatchs()->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'Le Courrier autonome ne satisfait plus les conditions de demande de correction.',
                ]);
            }

            $dateCorrection = now();
            $document->update([
                'statut' => DocumentProduitStatut::A_CORRIGER,
                'correction_demandee_par_id' => $agent->id,
                'motif_correction' => trim($motif),
                'correction_demandee_at' => $dateCorrection,
            ]);
            $this->audit->enregistrer('document_direction.autonome_correction_demandee_reception', $document, $agent, [
                'motif' => trim($motif),
                'courrier_id' => $courrier->id,
                'reference_documentaire' => $courrier->reference_documentaire,
                'correction_demandee_at' => $dateCorrection->toIso8601String(),
            ]);

            return $this->helpers->charger($document->fresh());
        }, attempts: 1);
    }

    public function recevoir(DocumentProduitDirection $document, User $reception): DocumentProduitDirection
    {
        $document = DB::transaction(function () use ($document, $reception) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->origine === DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::TRANSMIS_RECEPTION
                || $reception->poste !== Poste::RECEPTION) {
                throw ValidationException::withMessages(['document' => 'Ce document ne peut pas être reçu.']);
            }
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
            $courrier->numero_enregistrement ??= $this->numeros->genererNumeroEnregistrement();
            $courrier->statut = CourrierStatut::RECU;
            $courrier->save();
            CourrierTransition::query()->create(['courrier_id' => $courrier->id, 'statut' => CourrierStatut::RECU, 'nouveau_statut' => CourrierStatut::RECU, 'tour' => $courrier->tour, 'changed_by_id' => $reception->id, 'expediteur_poste' => Poste::RECEPTION->value, 'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now()]);
            $document->update(['statut' => DocumentProduitStatut::ENTRE_CIRCUIT, 'recu_par_id' => $reception->id, 'recu_at' => now()]);
            $this->audit->enregistrer('document_direction.recu_circuit', $document, $reception, ['numero_enregistrement' => $courrier->numero_enregistrement]);

            return $document;
        });

        return $this->helpers->charger($document->fresh());
    }
}
