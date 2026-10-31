<?php

namespace App\Models;

/** Every change to stock, so counts can be audited and rebuilt. */
class StockMovement extends Model
{
    protected $casts = ['created_at' => 'datetime', 'delta' => 'integer'];
}
