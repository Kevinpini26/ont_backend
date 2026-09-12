<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stagiaires\Enums\IssueProposee;

class AjouterLigneTableauRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('modifier', $this->route('tableau'));
    }

    public function rules(): array
    {
        return [
            'stagiaire_id' => ['required', 'integer', 'exists:stagiaires,id'],
            'direction_accueil_proposee_id' => ['required', 'integer', 'exists:directions,id'],
            'date_debut_proposee' => ['required', 'date'],
            'date_fin_proposee' => ['required', 'date', 'after_or_equal:date_debut_proposee'],
            'encadrant_pressenti' => ['required', 'string', 'max:255'],
            'issue_proposee' => ['sometimes', Rule::enum(IssueProposee::class)],
            'motif_non_retenu' => [
                Rule::requiredIf(fn () => $this->input('issue_proposee') === IssueProposee::NON_RETENU->value),
                'nullable', 'string', Rule::in(array_keys(config('stagiaires.motifs_non_retenu'))),
            ],
            'motif_non_retenu_libre' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
