<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Models\User;

class DocumentProduitScanOfficiel extends Model
{
    protected $table = 'document_produit_scans_officiels';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'empreinte_requete' => 'string',
            'attestation_confirmee' => 'boolean',
            'attestation_version' => 'integer',
            'atteste_at' => 'datetime',
            'est_actif' => 'boolean',
        ];
    }

    /** @return BelongsTo<DocumentProduitDirection, $this> */
    public function documentProduitDirection(): BelongsTo
    {
        return $this->belongsTo(DocumentProduitDirection::class);
    }

    /** @return BelongsTo<Courrier, $this> */
    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    /** @return BelongsTo<DocumentNumerise, $this> */
    public function documentNumerise(): BelongsTo
    {
        return $this->belongsTo(DocumentNumerise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deposePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depose_par_id');
    }
}
