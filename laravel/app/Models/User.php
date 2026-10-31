<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    public const VENDOR = 'vendor';
    public const PLATFORM_ADMIN = 'platform_admin';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->id ??= Text::newId('u');
            $user->created_at ??= now();
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StoreMember::class)->orderBy('id');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === self::PLATFORM_ADMIN;
    }

    public function primaryMembership(): ?StoreMember
    {
        return $this->memberships()->first();
    }
}
