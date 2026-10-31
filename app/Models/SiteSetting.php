<?php

namespace App\Models;

class SiteSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['value' => 'json', 'updated_at' => 'datetime'];

    protected function dateColumns(): array
    {
        return [];
    }
}
