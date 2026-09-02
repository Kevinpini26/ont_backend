<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\DegreUrgence;

/**
 * Le tri par degré d'urgence du Secrétariat 01 (Lot 2) se fait exactement
 * ici : transmettre à la DG exige désormais de choisir un degré, jamais
 * laissé à une valeur par défaut silencieuse — voir
 * CourrierCircuitService::transmettreEnAttenteAvisDg().
 */
class TransmettreAvisDgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transmettre', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'degre_urgence' => ['required', Rule::enum(DegreUrgence::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'degre_urgence.required' => "Choisissez un degré d'urgence avant de transmettre ce dossier à la Direction Générale.",
        ];
    }
}
