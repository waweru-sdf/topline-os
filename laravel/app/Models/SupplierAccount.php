<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Supplier balances: a supplier the shop buys from, and how much the shop owes them. */
class SupplierAccount extends Model
{
    protected const ID_PREFIX = 'sa';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['balance' => 'integer', 'created_at' => 'datetime'];

    public function entries(): HasMany
    {
        return $this->hasMany(SupplierAccountEntry::class, 'account_id')->orderByDesc('entry_date')->orderByDesc('id');
    }
}
