<?php

namespace Modules\Courrier\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Notifications\DocumentCircuitDirectNotification;
use Modules\Courrier\Support\ReferenceDocumentaire;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\Setting;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Lot 6 : circuit direct entre directions, DÉSACTIVÉ par défaut. Le schéma officiel de l'ONT fait passer
 * tout document interne par la Réception, SEC1, la DG et SEC2 ; seul l'administrateur peut ouvrir ce raccourci,
 * et seulement pour les natures autorisées. Restent toujours dans le circuit central : un document adressé à la
 * DG, confidentiel ou secret, un engagement financier ou contractuel, et toute nature non autorisée.
 * Le document est créé, validé, référencé, signé et scanné comme les autres ; il reçoit son numéro d'arrivée
 * à la transmission, et la DG en reçoit une copie pour information.
 */
class CircuitDirectService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DgAuthorityResolver $autoriteDg,
    ) {}

    public static function estActif(): bool
    {
        return (bool) Setting::valeur('circuit_direct.actif', false);
    }

    /** @return list<string> */
    public static function naturesAutorisees(): array
    {
        $autorisees = (array) Setting::valeur('circuit_direct.natures_autorisees', config('courrier.circuit_direct.natures_autorisees_par_defaut', []));

        return array_values(array_diff($autorisees, (array) config('courrier.circuit_direct.exclues_toujours', [])));
    }

    /** Raison pour laquelle le document doit suivre le circuit central, ou null s'il peut circuler directement. */
    public function refus(DocumentProduitDirection $document): ?string
    {
        if (! self::estActif()) {
            return 'Le circuit direct est désactivé : ce document passe par la Réception.';
        }
        if ($document->origine !== DocumentProduitOrigine::AUTONOME || $document->statut !== DocumentProduitStatut::SCAN_ENREGISTRE) {
            return 'Seul un document interne signé et scanné peut être transmis.';
        }
        if ($document->nature === null || ! in_array($document->nature, self::naturesAutorisees(), true)) {
            return 'Cette nature de document reste dans le circuit central.';
        }
        $destination = $document->direction_destination_souhaitee_id === null ? null : Direction::query()->find($document->direction_destination_souhaitee_id);
        if ($destination === null || ! $destination->actif || ! $destination->est_operationnelle) {
            return 'Le circuit direct exige une direction destinataire active.';
        }
        if (mb_strtoupper((string) $destination->code) === 'DG') {
            return 'Un document adressé à la DG passe par le circuit central.';
        }
        $courrier = $document->courrier_id === null ? null : Courrier::withoutGlobalScopes()->find($document->courrier_id);
        if ($courrier === null || $courrier->niveau_confidentialite !== NiveauConfidentialite::ORDINAIRE) {
            return 'Un document confidentiel ou secret passe par le circuit central.';
        }

        return null;
    }

    public function transmettre(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        $document = DB::transaction(function () use ($document, $secretariat) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($secretariat->role !== UserRole::SECRETARIAT_DIRECTION || $secretariat->direction_id !== $document->direction_id) {
                throw ValidationException::withMessages(['document' => 'Seul le secrétariat producteur transmet ce document.']);
            }
            $refus = $this->refus($document);
            if ($refus !== null) {
                throw ValidationException::withMessages(['document' => $refus]);
            }

            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
            if ($courrier->direction_origine_id !== $document->direction_id
                || ! is_string($courrier->reference_documentaire)
                || ! ReferenceDocumentaire::estValide($courrier->reference_documentaire)
                || $courrier->statut !== CourrierStatut::BROUILLON_DIRECTION
                || $courrier->numero_enregistrement !== null
                || $document->scan_officiel_document_id === null) {
                throw ValidationException::withMessages(['document' => 'Le document n’est pas dans un état cohérent pour le circuit direct.']);
            }

            // Enregistrement : numéro d'arrivée unique, comme à la Réception (registre d'arrivée, origine interne).
            $courrier->numero_enregistrement = $this->numeros->genererNumeroEnregistrement();
            $courrier->enregistre_at = now();
            $courrier->direction_destination_id = $document->direction_destination_souhaitee_id;
            $courrier->save();
            $document->update(['statut' => DocumentProduitStatut::TRANSMIS_DIRECTION, 'circuit_direct_par_id' => $secretariat->id, 'circuit_direct_at' => now()]);
            $this->audit->enregistrer('document_direction.circuit_direct_transmis', $document, $secretariat, [
                'courrier_id' => $courrier->id,
                'numero_enregistrement' => $courrier->numero_enregistrement,
                'reference_documentaire' => $courrier->reference_documentaire,
                'nature' => $document->nature,
                'direction_destination_id' => $document->direction_destination_souhaitee_id,
            ]);

            return $document;
        });

        DB::afterCommit(function () use ($document): void {
            User::query()->where('role', UserRole::SECRETARIAT_DIRECTION)->where('direction_id', $document->direction_destination_souhaitee_id)->where('actif', true)
                ->each(fn (User $u) => $this->notifications->notifier($u, new DocumentCircuitDirectNotification($document, 'destinataire')));
            $interim = $this->autoriteDg->interimOuvert();
            $dg = $interim !== null
                ? User::query()->whereKey($interim->dga_interimaire_id)->get()
                : User::query()->where('role', UserRole::AGENT_CIRCUIT_COURRIER)->where('poste', Poste::DG)->where('actif', true)->get();
            $dg->each(fn (User $u) => $this->notifications->notifier($u, new DocumentCircuitDirectNotification($document, 'copie_dg')));
        });

        return $document->fresh();
    }

    public function accuserReception(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $secretariat) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->statut !== DocumentProduitStatut::TRANSMIS_DIRECTION
                || $document->recu_destination_at !== null
                || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION
                || $secretariat->direction_id !== $document->direction_destination_souhaitee_id) {
                throw ValidationException::withMessages(['document' => 'Seul le secrétariat destinataire accuse réception, une seule fois.']);
            }
            $document->update(['recu_destination_par_id' => $secretariat->id, 'recu_destination_at' => now()]);
            $this->audit->enregistrer('document_direction.circuit_direct_recu', $document, $secretariat, ['courrier_id' => $document->courrier_id]);

            return $document;
        });
    }

    /** @return Builder<DocumentProduitDirection> */
    public function recusPour(User $user): Builder
    {
        return DocumentProduitDirection::query()
            ->where('statut', DocumentProduitStatut::TRANSMIS_DIRECTION->value)
            ->where('direction_destination_souhaitee_id', $user->direction_id ?? 0)
            ->orderByDesc('circuit_direct_at');
    }
}
