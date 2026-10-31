<?php

namespace App\Models;

class Quote extends Model
{
    public const STATUSES = ['new', 'replied', 'won', 'lost'];

    protected const ID_PREFIX = 'q';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['created_at' => 'datetime'];
}
