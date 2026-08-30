<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;

/**
 * Réservé à l'administrateur en attendant confirmation DFP/Secrétariat
 * Général de qui doit pouvoir déléguer un poste (voir docs/questions-ont.md)
 * — un chef de service pourrait légitimement vouloir désigner lui-même son
 * remplaçant, mais rien dans le circuit actuel ne modélise cette hiérarchie.
 */
class CreerDelegationPosteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === UserRole::ADMINISTRATEUR;
    }

    public function rules(): array
    {
        return [
            'poste' => ['required', Rule::enum(Poste::class)],
            'delegataire_id' => ['required', 'integer', 'exists:users,id'],
            'debut' => ['required', 'date'],
            'fin' => ['required', 'date', 'after_or_equal:debut'],
            'motif' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
