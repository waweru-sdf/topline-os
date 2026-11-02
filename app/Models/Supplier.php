<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A manufacturer or distributor that hardware shops can order from (Connect to suppliers). */
class Supplier extends Model
{
    public const PENDING = 'pending';   // never approved yet: not shown anywhere
    public const APPROVED = 'approved'; // shown on the landing page and to shops
    public const HIDDEN = 'hidden';     // taken down by a Topline admin

    protected const ID_PREFIX = 'sup';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['payment_options' => 'array', 'draft' => 'array', 'approved_at' => 'datetime', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class)->orderBy('name');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    public function isListed(): bool
    {
        return $this->status === self::APPROVED;
    }
}
