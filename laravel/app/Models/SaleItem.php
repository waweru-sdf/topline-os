<?php

namespace App\Models;

/** A line on a sale. Name, unit, price and cost are copied at the time of sale. */
class SaleItem extends Model
{
    protected $casts = ['qty' => 'integer', 'price' => 'integer', 'cost' => 'integer'];

    protected function dateColumns(): array
    {
        return [];
    }
}
