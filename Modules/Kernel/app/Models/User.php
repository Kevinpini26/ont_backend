<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Kernel\Database\Factories\UserFactory;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Notifications\ReinitialisationMotDePasseNotification;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'poste',
        'direction_id',
        'dg_disponible',
        'doit_changer_mot_de_passe',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'poste' => Poste::class,
            'dg_disponible' => 'boolean',
            'doit_changer_mot_de_passe' => 'boolean',
            'verrouille_jusqu_a' => 'datetime',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ReinitialisationMotDePasseNotification($token));
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }
}
