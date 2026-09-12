<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Facades\Date;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Contracts\SequenceGenerator;

class DefaultNumeroGenerator implements NumeroGenerator
{
    public function __construct(private readonly SequenceGenerator $sequences) {}

    public function genererAccuseReception(): string
    {
        $annee = Date::now()->year;
        $sequence = $this->sequences->suivant('accuse_reception', $annee);

        return sprintf('AR-%d-%06d', $annee, $sequence);
    }

    public function genererNumeroEnregistrement(): string
    {
        $annee = Date::now()->year;
        $sequence = $this->sequences->suivant('enregistrement', $annee);

        return sprintf('%d-%04d', $annee, $sequence);
    }

    public function genererNumeroDepart(): string
    {
        $annee = Date::now()->year;
        $sequence = $this->sequences->suivant('depart', $annee);

        return sprintf(config('courrier.format_numero_depart', '%d-D%04d'), $annee, $sequence);
    }

    public function genererNumeroBordereauLot(): string
    {
        $annee = Date::now()->year;
        $sequence = $this->sequences->suivant('bordereau_lot', $annee);

        return sprintf('BL-%d-%04d', $annee, $sequence);
    }
}
