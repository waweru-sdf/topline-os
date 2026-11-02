<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money going OUT: an online order's takings sent to the shop's till / paybill / Pochi / phone. */
class Payout extends Model
{
    protected $casts = ['created_at' => 'datetime', 'paid_at' => 'datetime', 'amount' => 'integer', 'gross' => 'integer', 'commission' => 'integer'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
