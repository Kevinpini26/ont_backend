<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Courrier\Models\Courrier;

/**
 * Un segment déjà découpé par le navigateur (voir
 * docs/numerisation-courrier.md, import par lot depuis une clé USB) :
 * la lecture du code-barres et la découpe du PDF multi-courriers se font
 * côté client, ce point d'entrée ne reçoit qu'un fichier déjà identifié.
 */
class ImporterLotNumerisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Lot 6 : le numéro d'arrivée (code-barres des nouvelles feuilles), ou un ancien accusé AR-.
            'numero_accuse_reception' => ['required', 'string', function (string $attribut, mixed $valeur, \Closure $echec): void {
                $existe = Courrier::query()->withoutGlobalScopes()
                    ->where(fn ($q) => $q->where('numero_enregistrement', $valeur)->orWhere('numero_accuse_reception', $valeur))->exists();
                if (! $existe) {
                    $echec('Aucun courrier ne porte ce numéro.');
                }
            }],
            'fichier' => [
                'required', 'file',
                'max:'.config('kernel.numerisation.plafond_ko_courrier'),
                'mimes:pdf', 'mimetypes:application/pdf',
            ],
        ];
    }
}
