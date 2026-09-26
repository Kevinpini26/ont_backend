<?php

namespace Modules\Kernel\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Numerisable
{
    public function numerisations(): MorphMany;
}
