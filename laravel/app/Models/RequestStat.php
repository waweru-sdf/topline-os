<?php

namespace App\Models;

class RequestStat extends Model
{
    protected $table = 'request_stats';

    protected $primaryKey = 'hour';

    public $incrementing = false;

    protected $casts = ['hour' => 'datetime'];

    protected function dateColumns(): array
    {
        return [];
    }
}
