<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\SequenceGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/** Référence d'un document produit par une direction : NNN/ONT/<code direction>/AAAA (ex. 001/ONT/DMC/2026). */
class ReferenceDocumentInterneService
{
    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function attribuer(Courrier $document, Direction $direction, User $directeur): Courrier
    {
        return DB::transaction(function () use ($document, $direction, $directeur) {
            $document = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->id);
            $direction = Direction::query()->lockForUpdate()->findOrFail($direction->id);
            $directeur = User::query()->lockForUpdate()->findOrFail($directeur->id);

            if ($document->reference_documentaire !== null) {
                throw ValidationException::withMessages([
                    'reference_documentaire' => 'Une référence documentaire déjà attribuée ne peut pas être remplacée.',
                ]);
            }

            if (! $direction->actif || ! $direction->est_operationnelle) {
                throw ValidationException::withMessages([
                    'direction' => 'La référence interne est réservée à une direction active et opérationnelle. Le cas DG nécessite une phase dédiée.',
                ]);
            }

            if (! $directeur->role->estDirecteurDirection()
                || $directeur->direction_id !== $direction->id
                || $document->direction_origine_id !== $direction->id) {
                throw ValidationException::withMessages([
                    'direction' => 'Le Directeur et le document doivent appartenir à la direction productrice.',
                ]);
            }

            $sigle = mb_strtoupper(trim($direction->code));
            if (preg_match('/\A[A-Z0-9]{1,20}\z/', $sigle) !== 1) {
                throw ValidationException::withMessages([
                    'direction' => 'Le sigle de la direction productrice est invalide.',
                ]);
            }

            $annee = Date::now()->year;
            $numero = $this->sequences->suivantPourDirection('reference_documentaire', $annee, $direction->id);
            $document->reference_documentaire = sprintf('%03d/ONT/%s/%d', $numero, $sigle, $annee);
            $document->save();

            $this->audit->enregistrer('courrier.reference_documentaire_interne_attribuee', $document, $directeur, [
                'type_identite' => 'reference_documentaire_interne',
                'valeur' => $document->reference_documentaire,
                'direction_id' => $direction->id,
                'direction_code' => $sigle,
                'directeur_id' => $directeur->id,
                'attribuee_at' => now()->toISOString(),
            ]);

            return $document;
        });
    }
}
