<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\CourrierAnnotationType;
use Modules\Kernel\Enums\Poste;

class StoreAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('annoter', $this->route('courrier'));
    }

    public function rules(): array
    {
        // Les postes qui n'annotent pas (SEC2, assistant du DGA) ne pourraient pas lire une note qui les vise.
        $postesActuels = collect(Poste::cases())
            ->reject(fn (Poste $poste) => $poste->estHistorique() || in_array($poste, [Poste::SECRETARIAT_2, Poste::ASSISTANT_DGA], true))
            ->map(fn (Poste $poste) => $poste->value)
            ->all();

        return [
            'contenu' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', Rule::enum(CourrierAnnotationType::class)],
            'destinataire_poste' => ['nullable', 'string', Rule::in($postesActuels)],
            'destinataire_direction_id' => ['nullable', 'integer', 'exists:directions,id'],
        ];
    }
}
