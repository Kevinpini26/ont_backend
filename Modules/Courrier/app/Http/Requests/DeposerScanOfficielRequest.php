<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeposerScanOfficielRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(
            $this->user()?->can('deposerScanOfficiel', $this->route('document')),
            404,
        );

        return true;
    }

    public function rules(): array
    {
        return [
            'scan' => [
                'required',
                'file',
                'max:'.config('kernel.numerisation.plafond_ko_courrier'),
                'mimes:pdf',
                'mimetypes:application/pdf',
            ],
            'attestation_confirmee' => [
                'required',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! in_array($value, [true, 1, '1', 'true'], true)) {
                        $fail('Vous devez confirmer l’attestation nominative.');
                    }
                },
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
