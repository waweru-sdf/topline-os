<?php

namespace App\Models;

class Upload extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['created_at' => 'datetime'];
}
