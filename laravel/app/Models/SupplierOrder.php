<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shop's order to a supplier. Money and delivery are arranged between them, outside Topline. */
class SupplierOrder extends Model
{
    public const STATUSES = ['new', 'contacted', 'completed', 'declined', 'cancelled'];

    /** What the supplier may move an order to, from each status. */
    public const SUPPLIER_TRANSITIONS = [
        'new' => ['contacted', 'completed', 'declined'],
        'contacted' => ['completed', 'declined'],
    ];

    protected const ID_PREFIX = 'so';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['items' => 'array', 'estimated_total' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
