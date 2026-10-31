<?php

namespace App\Models;

/** Something that broke. Repeats of the same problem bump `count` instead of adding rows. */
class Incident extends Model
{
    protected $casts = ['first_seen' => 'datetime', 'last_seen' => 'datetime', 'resolved' => 'boolean', 'count' => 'integer'];

    protected function dateColumns(): array
    {
        return [];
    }
}
