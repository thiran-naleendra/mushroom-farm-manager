<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    protected $attributes = [
        'role' => 'worker',
        'is_active' => true,
        'can_work_production' => false,
        'can_work_harvest' => false,
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_work_production' => 'boolean',
            'can_work_harvest' => 'boolean',
        ];
    }

    public function canAccessApp(): bool
    {
        return $this->is_active && $this->accessRole?->is_active === true;
    }

    public function accessRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'name');
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner' && $this->canAccessApp();
    }
}
