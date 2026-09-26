<?php

namespace Modules\Courrier\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Point d'entrée métier de la future action Directeur. Volontairement sans
 * route en Phase 3 : le workflow directeur sera raccordé dans une phase dédiée.
 */
class ReferenceDocumentaireService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws AuthorizationException */
    public function attribuer(Courrier $document, User $acteur, ?int $annee = null): Courrier
    {
        return DB::transaction(function () use ($document, $acteur, $annee) {
            $document = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->getKey());
            $direction = $document->direction_origine_id === null
                ? null
                : Direction::query()->find($document->direction_origine_id);

            if (! $acteur->role->estDirecteurDirection()
                || $direction === null
                || $acteur->direction_id !== $direction->getKey()) {
                throw new AuthorizationException("Seul le Directeur de la direction d'origine peut attribuer cette référence.");
            }

            if (! $direction->actif || ! $direction->est_operationnelle) {
                throw ValidationException::withMessages([
                    'direction' => 'La référence directionnelle est réservée à une direction active et opérationnelle. Le cas DG reste à confirmer.',
                ]);
            }

            if ($document->reference_documentaire !== null) {
                throw new LogicException('Une référence documentaire déjà attribuée est immuable.');
            }

            $document->reference_documentaire = $this->numeros->genererReferenceDocumentaire($direction, $annee);
            $document->save();

            $this->audit->enregistrer('courrier.reference_documentaire_attribuee', $document, $acteur, [
                'type_identite' => 'reference_documentaire',
                'valeur' => $document->reference_documentaire,
                'direction_id' => $direction->getKey(),
                'direction_code' => $direction->code,
                'attribuee_at' => now()->toISOString(),
            ]);

            return $document;
        });
    }
}
