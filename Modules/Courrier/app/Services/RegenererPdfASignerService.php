<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Support\EmpreinteFichier;
use RuntimeException;
use Throwable;

/** Régénération technique du PDF pré-signature sans transition de workflow. */
class RegenererPdfASignerService
{
    public function __construct(private readonly CourrierPdfGenerator $generateur) {}

    public function regenererPdfASigner(Courrier $courrier): Courrier
    {
        $disque = Storage::disk('local');
        $cheminTemporaireAbsolu = null;
        $cheminSauvegardeAbsolu = null;
        $cheminFinalAbsolu = null;
        $remplacementEffectue = false;

        try {
            $courrierRegénéré = DB::transaction(function () use (
                $courrier,
                $disque,
                &$cheminTemporaireAbsolu,
                &$cheminSauvegardeAbsolu,
                &$cheminFinalAbsolu,
                &$remplacementEffectue,
            ): Courrier {
                $courrierVerrouille = Courrier::query()->lockForUpdate()->findOrFail($courrier->id);
                $courrierVerrouille->load('valideSignaturePar');
                $this->verifierEligibilite($courrierVerrouille);

                $cheminFinal = $courrierVerrouille->pdf_a_signer_chemin;
                $shaActuel = $courrierVerrouille->pdf_a_signer_sha256;
                $shaFichierActuel = hash('sha256', $disque->get($cheminFinal));
                if (! hash_equals($shaActuel, $shaFichierActuel)) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Le PDF pré-signature actuel ne correspond pas à son empreinte enregistrée.',
                    ]);
                }

                $sourceAutorite = $this->sourceAutoriteHistorique($courrierVerrouille);
                $repertoire = dirname($cheminFinal);
                $nomFichier = basename($cheminFinal);
                $cheminTemporaire = $repertoire.'/.'.$nomFichier.'.'.Str::uuid().'.tmp';
                $cheminSauvegarde = $repertoire.'/.'.$nomFichier.'.'.Str::uuid().'.bak';
                $cheminTemporaireAbsolu = $disque->path($cheminTemporaire);
                $cheminSauvegardeAbsolu = $disque->path($cheminSauvegarde);
                $cheminFinalAbsolu = $disque->path($cheminFinal);

                $this->generateur->genererPourSignatureDans($courrierVerrouille, $sourceAutorite, $cheminTemporaire);
                $nouveauSha = EmpreinteFichier::pourFichierStocke($cheminTemporaire);

                if (! @copy($cheminFinalAbsolu, $cheminSauvegardeAbsolu)) {
                    throw new RuntimeException('Impossible de préserver le PDF pré-signature actuel avant son remplacement.');
                }
                if (! @rename($cheminTemporaireAbsolu, $cheminFinalAbsolu)) {
                    throw new RuntimeException('Impossible de remplacer atomiquement le PDF pré-signature.');
                }
                $remplacementEffectue = true;

                if (! hash_equals($shaActuel, $nouveauSha)) {
                    $misAJour = DB::table('courriers')
                        ->where('id', $courrierVerrouille->id)
                        ->where('pdf_a_signer_sha256', $shaActuel)
                        ->update(['pdf_a_signer_sha256' => $nouveauSha]);

                    if ($misAJour !== 1) {
                        throw new RuntimeException("L'empreinte du PDF pré-signature n'a pas pu être mise à jour.");
                    }
                }

                $courrierVerrouille->setAttribute('pdf_a_signer_sha256', $nouveauSha);

                return $courrierVerrouille;
            });
        } catch (Throwable $erreur) {
            if ($remplacementEffectue) {
                if ($cheminSauvegardeAbsolu === null
                    || ! is_file($cheminSauvegardeAbsolu)
                    || ! @rename($cheminSauvegardeAbsolu, $cheminFinalAbsolu)) {
                    throw new RuntimeException(
                        'La régénération a échoué et la restauration du PDF pré-signature doit être vérifiée.',
                        previous: $erreur,
                    );
                }
            } elseif ($cheminSauvegardeAbsolu !== null && is_file($cheminSauvegardeAbsolu)) {
                @unlink($cheminSauvegardeAbsolu);
            }

            if ($cheminTemporaireAbsolu !== null && is_file($cheminTemporaireAbsolu)) {
                @unlink($cheminTemporaireAbsolu);
            }

            throw $erreur;
        }

        if ($cheminSauvegardeAbsolu !== null && is_file($cheminSauvegardeAbsolu) && ! @unlink($cheminSauvegardeAbsolu)) {
            Log::warning('La sauvegarde temporaire du PDF pré-signature n’a pas pu être supprimée.', [
                'courrier_id' => $courrier->id,
                'chemin' => $cheminSauvegardeAbsolu,
            ]);
        }

        return $courrierRegénéré;
    }

    private function verifierEligibilite(Courrier $courrier): void
    {
        if ($courrier->statut !== CourrierStatut::EN_ATTENTE_SIGNATURE) {
            throw ValidationException::withMessages(['courrier' => 'Le courrier doit être en attente de signature.']);
        }
        if (blank($courrier->numero_depart)
            || blank($courrier->pdf_a_signer_chemin)
            || blank($courrier->pdf_a_signer_sha256)
            || $courrier->valide_signature_par_id === null
            || $courrier->valide_signature_at === null
            || $courrier->valideSignaturePar === null) {
            throw ValidationException::withMessages(['courrier' => 'Les données de validation et le PDF pré-signature sont incomplets.']);
        }

        $cheminAttendu = "courriers-a-signer/courrier-{$courrier->id}-{$courrier->numero_depart}.pdf";
        if ($courrier->pdf_a_signer_chemin !== $cheminAttendu) {
            throw ValidationException::withMessages(['courrier' => 'Le chemin du PDF pré-signature ne correspond pas au courrier.']);
        }

        if ($courrier->signe_at !== null
            || $courrier->signataire_id !== null
            || filled($courrier->pdf_chemin)
            || filled($courrier->pdf_sha256)
            || $courrier->scan_signe_televerse_par_id !== null
            || $courrier->scan_signe_televerse_at !== null
            || $courrier->transitions()->whereIn('statut', [CourrierStatut::SIGNE->value, CourrierStatut::ENVOYE->value])->exists()
            || $courrier->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->exists()) {
            throw ValidationException::withMessages(['courrier' => 'Un courrier signé, finalisé ou transmis ne peut pas être régénéré.']);
        }

        if (! $courrier->transitions()
            ->where('statut', CourrierStatut::EN_ATTENTE_SIGNATURE->value)
            ->where('changed_by_id', $courrier->valide_signature_par_id)
            ->exists()) {
            throw ValidationException::withMessages(['courrier' => 'La validation pour signature ne correspond pas à l’historique du courrier.']);
        }
    }

    private function sourceAutoriteHistorique(Courrier $courrier): string
    {
        $audit = AuditLog::query()
            ->where('action', 'courrier.valide_pour_signature')
            ->where('auditable_type', $courrier->getMorphClass())
            ->where('auditable_id', $courrier->id)
            ->where('user_id', $courrier->valide_signature_par_id)
            ->latest('id')
            ->first();

        $meta = $audit?->meta ?? [];
        $sourceAutorite = $meta['source_autorite'] ?? null;
        if (! in_array($sourceAutorite, ['titulaire', 'interim_dga', 'delegation'], true)
            || ($meta['numero_depart'] ?? null) !== $courrier->numero_depart) {
            throw ValidationException::withMessages(['courrier' => 'La source historique de validation DG est introuvable ou incohérente.']);
        }

        return $sourceAutorite;
    }
}
