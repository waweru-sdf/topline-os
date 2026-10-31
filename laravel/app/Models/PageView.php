<?php

namespace App\Models;

class PageView extends Model
{
    protected $table = 'page_views';

    protected $casts = ['views' => 'integer']; // day stays a plain Y-m-d string so lookups match on SQLite and MySQL alike

    protected function dateColumns(): array
    {
        return [];
    }
}
