<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

/** Marqueur de lecture d'une note de circuit par un utilisateur. */
class CourrierAnnotationLecture extends Model
{
    public $timestamps = false;

    protected $fillable = ['annotation_id', 'user_id', 'lu_at'];

    protected function casts(): array
    {
        return ['lu_at' => 'datetime'];
    }

    public function annotation(): BelongsTo
    {
        return $this->belongsTo(CourrierAnnotation::class, 'annotation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
