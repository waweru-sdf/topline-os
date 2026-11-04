<?php

namespace App\Models;

/** A bill (goods taken on credit: the shop owes more) or a payment (the shop owes less). */
class SupplierAccountEntry extends Model
{
    public const BILL = 'bill';
    public const PAYMENT = 'payment';

    protected $casts = ['amount' => 'integer', 'entry_date' => 'date', 'due_on' => 'date', 'created_at' => 'datetime'];
}
