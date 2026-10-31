<?php

namespace App\Models;

/** One thing that happened, for the Activity pages (Topline admin: every store; owner: their own). */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];
}
