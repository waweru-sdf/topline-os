<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreMember extends Model
{
    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const CASHIER = 'cashier';

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
