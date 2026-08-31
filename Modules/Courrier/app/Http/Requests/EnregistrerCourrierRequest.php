<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\CourrierClassification;

class EnregistrerCourrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transmettre', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'classification' => ['required', Rule::enum(CourrierClassification::class)],
            'note_technique' => [Rule::requiredIf(fn () => $this->input('classification') === CourrierClassification::INTERNE->value), 'nullable', 'string'],
            'accuse_reception_partenaire' => [Rule::requiredIf(fn () => $this->input('classification') === CourrierClassification::EXTERNE->value), 'nullable', 'string', 'max:255'],
            // Localisation de l'original (armoire, chrono, année — voir
            // docs/numerisation-courrier.md) : renseignée à l'enregistrement,
            // en cohérence avec cote_classement généré au même moment.
            'emplacement_physique' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Le classement interne/externe est déterminé par la nature réelle du
     * courrier (voir Courrier::classificationAttendue()), jamais laissé au
     * libre choix de l'agent : une valeur soumise qui ne correspond pas est
     * rejetée plutôt que silencieusement acceptée.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $courrier = $this->route('courrier');

            if (! $this->filled('classification') || ! $courrier) {
                return;
            }

            $attendue = $courrier->classificationAttendue();

            if ($this->input('classification') !== $attendue->value) {
                $validator->errors()->add(
                    'classification',
                    "Ce courrier doit être classé « {$attendue->value} » d'après sa nature (expéditeur externe/candidat ou échange interne) — le classement n'est pas modifiable manuellement.",
                );
            }
        });
    }
}
