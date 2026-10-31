<?php

namespace App\Models;

/** Money coming IN: one M-PESA prompt. `reference` is ours; `provider_ref` is the M-PESA receipt. */
class Payment extends Model
{
    public const SUBSCRIPTION = 'subscription';
    public const ORDER = 'order';
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['claimed' => 'boolean', 'created_at' => 'datetime', 'paid_at' => 'datetime', 'amount' => 'integer'];
}
