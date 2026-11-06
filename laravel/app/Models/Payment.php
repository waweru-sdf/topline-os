<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money coming IN: an M-PESA prompt, or a till payment with its code. `reference` is ours; `provider_ref` is the M-PESA code. */
class Payment extends Model
{
    public const SUBSCRIPTION = 'subscription';
    public const ORDER = 'order';
    public const PENDING = 'pending';   // M-PESA prompt sent, waiting for the customer
    public const CHECKING = 'checking'; // paid from the M-PESA menu; a Topline admin checks the code
    public const PAID = 'paid';
    public const FAILED = 'failed';

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['claimed' => 'boolean', 'created_at' => 'datetime', 'paid_at' => 'datetime', 'amount' => 'integer'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
