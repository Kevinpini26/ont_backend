<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\ClassementDocumentStatut;
use Modules\Kernel\Models\User;

class ClassementDocument extends Model
{
    protected $table = 'classements_documents';

    protected $fillable = ['courrier_id', 'dossier_id', 'dispatch_courrier_id', 'statut', 'cote', 'emplacement', 'observation', 'classe_par_id', 'classe_at', 'archive_par_id', 'archive_at'];

    protected function casts(): array
    {
        return ['statut' => ClassementDocumentStatut::class, 'classe_at' => 'datetime', 'archive_at' => 'datetime'];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(DispatchCourrier::class, 'dispatch_courrier_id');
    }

    public function classePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classe_par_id');
    }

    public function archivePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archive_par_id');
    }
}
