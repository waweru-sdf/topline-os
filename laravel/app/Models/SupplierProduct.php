<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    protected const ID_PREFIX = 'sp';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['price' => 'integer', 'min_qty' => 'integer', 'in_stock' => 'boolean', 'created_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
