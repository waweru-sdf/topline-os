<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    // Lifecycle: draft -> active -> past_due (grace) -> suspended -> active again on payment
    public const DRAFT = 'draft';
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const SUSPENDED = 'suspended';
    public const STATUSES = [self::DRAFT, self::ACTIVE, self::PAST_DUE, self::SUSPENDED];

    // Where a shop receives its money
    public const TILL = 'till';
    public const PAYBILL = 'paybill';
    public const POCHI = 'pochi';
    public const SEND_MONEY = 'send_money';
    public const PAYOUT_METHODS = [self::TILL, self::PAYBILL, self::POCHI, self::SEND_MONEY];

    // How delivery is charged
    public const DELIVERY_ZONES = 'zones';
    public const DELIVERY_QUOTE = 'quote';

    protected const ID_PREFIX = 'st';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'delivery_zones' => 'array',
        'delivery_enabled' => 'boolean',
        'accepts_direct_payment' => 'boolean',
        'delivery_free_over' => 'integer',
        'next_billing_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function owner(): ?User
    {
        $member = $this->members()->where('role', StoreMember::OWNER)->orderBy('id')->first();

        return $member?->user;
    }

    /** Customers can browse and order only from active or grace-period stores. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::PAST_DUE], true);
    }

    public function canReceivePayouts(): bool
    {
        if ($this->payout_method === self::PAYBILL) {
            return (bool) ($this->payout_number && $this->payout_account);
        }

        return (bool) $this->payout_number;
    }
}
