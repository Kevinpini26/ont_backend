<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;

class InitierCourrierSortantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === UserRole::RESPONSABLE_DIRECTION || $this->user()->poste === Poste::SECRETARIAT_1;
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
            // Pas de courrier d'origine dont dériver un objet par défaut ici.
            'objet' => ['required', 'string', 'max:255'],
            'projet_reponse_contenu' => ['required', 'array'],
            'relecteur_id' => ['required', 'integer', 'exists:users,id'],
            'degre_urgence' => ['nullable', Rule::enum(DegreUrgence::class)],
            'niveau_confidentialite' => ['nullable', Rule::enum(NiveauConfidentialite::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((int) $this->input('relecteur_id') === $this->user()->id) {
                $validator->errors()->add('relecteur_id', 'Le relecteur désigné doit être différent du rédacteur.');
            }
        });
    }
}
