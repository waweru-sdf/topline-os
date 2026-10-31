<?php

namespace App\Models;

class Plan extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['limits' => 'array', 'features' => 'array', 'popular' => 'boolean', 'active' => 'boolean'];

    protected function dateColumns(): array
    {
        return [];
    }

    public function priceFor(string $period): int
    {
        return $period === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }
}
