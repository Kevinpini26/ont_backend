<?php

namespace Modules\Public\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Stagiaires\Models\StagiaireLienPublic */
class LienPublicResource extends JsonResource
{
    /**
     * Vue volontairement minimale : juste de quoi afficher le bon
     * formulaire côté public, sans exposer de données internes. Un lien
     * déjà consommé reste consultable (PublicLienPage.jsx affiche alors
     * "ce lien a déjà été utilisé" plutôt qu'une erreur brute), mais
     * n'expose plus les informations du stagiaire : le jeton n'est pas
     * énumérable, mais rien n'empêche qu'il finisse un jour dans une boîte
     * mail partagée ou un historique de navigateur longtemps après usage.
     */
    public function toArray(Request $request): array
    {
        $valide = $this->estValide();

        return [
            'type' => $this->type->value,
            'valide' => $valide,
            'stagiaire' => $valide ? [
                'nom' => $this->stagiaire->nom,
                'direction' => $this->stagiaire->direction?->nom,
                'date_debut_stage' => $this->stagiaire->date_debut_stage?->toDateString(),
                'date_fin_stage' => $this->stagiaire->date_fin_stage?->toDateString(),
            ] : null,
        ];
    }
}
