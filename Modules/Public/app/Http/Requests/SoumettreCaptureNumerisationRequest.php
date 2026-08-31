<?php

namespace Modules\Public\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Kernel\Models\JetonCaptureNumerisation;
use Modules\Stagiaires\Models\Stagiaire;

class SoumettreCaptureNumerisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $jeton = JetonCaptureNumerisation::query()->where('token', $this->route('token'))->first();
        $plafondKo = $jeton?->capturable_type === (new Stagiaire)->getMorphClass()
            ? config('kernel.numerisation.plafond_ko_stagiaire')
            : config('kernel.numerisation.plafond_ko_courrier');

        return [
            'fichier' => ['required', 'file', "max:{$plafondKo}", 'mimes:pdf', 'mimetypes:application/pdf'],
            'nombre_pages_annonce' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
