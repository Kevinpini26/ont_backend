<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Courrier\Models\MissionDocumentaire;

class SoumettreProjetReponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mission = MissionDocumentaire::query()
            ->where('projet_courrier_id', $this->route('courrier')->id)
            ->first();

        if ($mission !== null) {
            return $this->user()->can('redigerProjet', $mission);
        }

        return $this->user()->can('transmettre', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'projet_reponse_contenu' => ['required', 'array'],
            'relecteur_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * La relecture doit être validée par un compte différent de celui qui a
     * rédigé le projet de réponse — sinon la relecture obligatoire n'est
     * qu'une formalité que le rédacteur peut s'auto-accorder.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((int) $this->input('relecteur_id') === $this->user()->id) {
                $validator->errors()->add('relecteur_id', 'Le relecteur désigné doit être différent du rédacteur du projet de réponse.');
            }
        });
    }
}
