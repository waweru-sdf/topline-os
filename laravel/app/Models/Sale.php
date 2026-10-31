<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A counter sale (channel "pos") or online order (channel "online"), shown to people as "S-<number>". */
class Sale extends Model
{
    public const POS = 'pos';
    public const ONLINE = 'online';

    public const AWAITING_PAYMENT = 'awaiting_payment';       // M-PESA prompt sent, stock held
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation'; // customer paid the shop directly; shop must check
    public const NEW = 'new';
    public const CONFIRMED = 'confirmed';
    public const DISPATCHED = 'dispatched';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const PAYMENT_METHODS = ['mpesa', 'cash', 'bank', 'credit'];
    public const PAY_PROMPT = 'prompt';
    public const PAY_DIRECT = 'direct';

    public const ORDER_TRANSITIONS = [
        self::AWAITING_CONFIRMATION => [self::NEW, self::CANCELLED],
        self::NEW => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::DISPATCHED, self::COMPLETED, self::CANCELLED],
        self::DISPATCHED => [self::COMPLETED],
    ];

    protected $primaryKey = 'number';

    protected $casts = ['created_at' => 'datetime', 'paid_at' => 'datetime', 'total' => 'integer', 'subtotal' => 'integer', 'delivery_fee' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_number', 'number')->orderBy('id');
    }

    public function getCodeAttribute(): string
    {
        return 'S-'.$this->number;
    }

    public static function numberFromCode(mixed $code): ?int
    {
        $digits = preg_replace('/^S-/', '', (string) $code);

        return ctype_digit($digits) ? (int) $digits : null;
    }
}
