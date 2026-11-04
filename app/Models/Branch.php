<?php

namespace App\Models;

/** One of a shop's locations. Every shop has a main branch; its stock is products.stock. */
class Branch extends Model
{
    protected const ID_PREFIX = 'br';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['is_main' => 'boolean', 'created_at' => 'datetime'];
}
