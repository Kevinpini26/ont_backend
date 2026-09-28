<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\NiveauConfidentialite;

class InitierReponseSortanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('initierReponse', $this->route('courrier'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('projet_reponse_contenu'))) {
            $this->merge(['projet_reponse_contenu' => json_decode($this->input('projet_reponse_contenu'), true)]);
        }
    }

    public function rules(): array
    {
        return [
            'objet' => ['nullable', 'string', 'max:255'],
            'destinataire_externe_nom' => ['nullable', 'string', 'max:255'],
            'destinataire_externe_email' => ['nullable', 'email', 'max:255'],
            'projet_reponse_contenu' => ['required', 'array'],
            'relecteur_id' => ['required', 'integer', 'exists:users,id'],
            'degre_urgence' => ['nullable', Rule::enum(DegreUrgence::class)],
            'niveau_confidentialite' => ['nullable', Rule::enum(NiveauConfidentialite::class)],
        ];
    }

    /**
     * Même garde que soumettreProjetReponse() : le relecteur désigné doit
     * être différent du rédacteur.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((int) $this->input('relecteur_id') === $this->user()->id) {
                $validator->errors()->add('relecteur_id', 'Le relecteur désigné doit être différent du rédacteur.');
            }
        });
    }
}
