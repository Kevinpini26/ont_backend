<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\AuditLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReponseCourrierPublicController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function telecharger(Request $request, int $courrier): StreamedResponse|Response
    {
        if (! $request->hasValidSignature()) {
            return response("Ce lien de consultation a expiré ou n'est plus valide.", 403);
        }

        $reponse = Courrier::withoutGlobalScopes()->find($courrier);
        if (! $this->estReponseExterneCommunicable($reponse)) {
            return response('Cette réponse n’est pas disponible.', 404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($reponse->pdf_chemin)) {
            Log::warning('PDF final externe absent du stockage privé.', ['courrier_id' => $reponse->id]);

            return response('Le document demandé est momentanément indisponible.', 404);
        }

        $contenu = $disk->get($reponse->pdf_chemin);
        if (! hash_equals($reponse->pdf_sha256, hash('sha256', $contenu))) {
            Log::error('Empreinte du PDF final externe invalide.', ['courrier_id' => $reponse->id]);

            return response('Le document demandé ne peut pas être communiqué.', 409);
        }

        $this->audit->enregistrer('courrier.reponse_finale_consultee', $reponse, null, [
            'dossier_id' => $reponse->dossier_id,
            'courrier_origine_id' => $reponse->en_reponse_a_courrier_id,
            'consultee_at' => now()->toISOString(),
        ]);

        $reference = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($reponse->numero_depart ?? $reponse->id));

        return $disk->response($reponse->pdf_chemin, "reponse-{$reference}.pdf", [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"reponse-{$reference}.pdf\"",
        ]);
    }

    private function estReponseExterneCommunicable(?Courrier $reponse): bool
    {
        if ($reponse === null
            || $reponse->sens !== SensCourrier::SORTANT
            || $reponse->en_reponse_a_courrier_id === null
            || blank($reponse->pdf_chemin)
            || blank($reponse->pdf_sha256)
            || $reponse->signe_at === null
            || $reponse->signataire_id === null
            || $reponse->date_envoi === null) {
            return false;
        }

        if (! $reponse->transitions()->where('statut', CourrierStatut::ENVOYE)->exists()) {
            return false;
        }

        return Courrier::withoutGlobalScopes()
            ->whereKey($reponse->en_reponse_a_courrier_id)
            ->where('dossier_id', $reponse->dossier_id)
            ->where('sens', SensCourrier::ENTRANT)
            ->where('mode_reception', ModeReception::DEPOT_EN_LIGNE)
            ->whereNotNull('expediteur_externe_email')
            ->exists();
    }
}
