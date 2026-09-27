<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Courrier\Models\Courrier;

class InitierCourrierDgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('initierParDg', Courrier::class);
    }

    /**
     * Même technique que StoreCourrierRequest : le contenu TipTap transite
     * en JSON string quand la requête est multipart (pièce jointe).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('projet_reponse_contenu'))) {
            $this->merge(['projet_reponse_contenu' => json_decode($this->input('projet_reponse_contenu'), true)]);
        }
    }

    public function rules(): array
    {
        return [
            'instruction_courrier_dg_id' => ['required', 'integer'],
            'en_reponse_a_courrier_id' => ['prohibited'],
            'mission_documentaire_id' => ['prohibited'],
            'dossier_id' => ['prohibited'],
            'direction_destination_id' => ['required', 'integer', 'exists:directions,id'],
            'objet' => ['required', 'string', 'max:255'],
            'projet_reponse_contenu' => ['required', 'array'],
            'relecteur_id' => ['required', 'integer', 'exists:users,id'],
            'validation_dg_requise' => ['nullable', 'boolean'],
            'piece_jointe' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
            ],
        ];
    }

    /**
     * Même garde que SoumettreProjetReponseRequest : le relecteur désigné
     * doit être différent du rédacteur, sinon la relecture obligatoire
     * n'est qu'une formalité que le Secrétariat 01 peut s'auto-accorder.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((int) $this->input('relecteur_id') === $this->user()->id) {
                $validator->errors()->add('relecteur_id', 'Le relecteur désigné doit être différent du rédacteur du courrier.');
            }
        });
    }
}
