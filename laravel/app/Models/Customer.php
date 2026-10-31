<?php

namespace App\Models;

/** A shop's regular buyer, mainly to track credit (what they owe). */
class Customer extends Model
{
    protected const ID_PREFIX = 'cu';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['due_at' => 'datetime', 'created_at' => 'datetime', 'balance' => 'integer', 'credit_limit' => 'integer'];
}
