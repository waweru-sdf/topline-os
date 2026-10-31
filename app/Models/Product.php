<?php

namespace App\Models;

class Product extends Model
{
    public const VAT_CLASSES = ['standard', 'zero_rated', 'exempt'];

    protected const ID_PREFIX = 'prd';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'gallery' => 'array',
        'specs' => 'array',
        'alt_units' => 'array',
        'published' => 'boolean',
        'sell_price' => 'integer',
        'buy_price' => 'integer',
        'stock' => 'integer',
        'reorder_at' => 'integer',
        'created_at' => 'datetime',
    ];
}
