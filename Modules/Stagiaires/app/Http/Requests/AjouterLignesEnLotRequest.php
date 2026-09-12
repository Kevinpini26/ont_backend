<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stagiaires\Enums\IssueProposee;

/**
 * Lot A : "un seul geste" enregistre toutes les lignes sélectionnées sur
 * l'écran de sélection des dossiers, plutôt qu'un aller-retour par
 * dossier.
 */
class AjouterLignesEnLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('modifier', $this->route('tableau'));
    }

    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.stagiaire_id' => ['required', 'integer', 'exists:stagiaires,id', 'distinct'],
            'lignes.*.direction_accueil_proposee_id' => ['required', 'integer', 'exists:directions,id'],
            'lignes.*.date_debut_proposee' => ['required', 'date'],
            'lignes.*.date_fin_proposee' => ['required', 'date', 'after_or_equal:lignes.*.date_debut_proposee'],
            'lignes.*.encadrant_pressenti' => ['required', 'string', 'max:255'],
            'lignes.*.issue_proposee' => ['sometimes', Rule::enum(IssueProposee::class)],
            'lignes.*.motif_non_retenu' => ['nullable', 'string', Rule::in(array_keys(config('stagiaires.motifs_non_retenu')))],
            'lignes.*.motif_non_retenu_libre' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
