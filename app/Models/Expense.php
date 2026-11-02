<?php

namespace App\Models;

class Expense extends Model
{
    public const CATEGORIES = ['rent', 'salaries', 'transport', 'utilities', 'supplies', 'marketing', 'licences', 'repairs', 'taxes', 'other'];
    public const METHODS = ['cash', 'mpesa', 'bank'];

    protected $casts = ['amount' => 'integer', 'created_at' => 'datetime'];
}
