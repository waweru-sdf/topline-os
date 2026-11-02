<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shop asking for stock credit through Topline's lending partner. */
class CreditRequest extends Model
{
    public const STATUSES = ['new', 'reviewing', 'approved', 'declined', 'closed'];
    public const OPEN = ['new', 'reviewing'];
    public const PURPOSES = ['stock', 'supplier_order', 'expansion', 'other'];
    public const PERIODS = [1, 3, 6, 12];

    protected const ID_PREFIX = 'cr';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['amount' => 'integer', 'period_months' => 'integer', 'monthly_sales' => 'integer',
        'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
