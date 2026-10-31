<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Base for Topline tables: no automatic updated_at; created_at filled in on insert;
 * prefixed string ids ("st_...", "prd_...") where the table uses them.
 */
abstract class Model extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    /** Prefix for generated string ids; null for tables with integer ids. */
    protected const ID_PREFIX = null;

    protected static function booted(): void
    {
        static::creating(function (Model $model) {
            if ($model::ID_PREFIX && ! $model->getKey()) {
                $model->setAttribute($model->getKeyName(), Text::newId($model::ID_PREFIX));
            }
            if (in_array('created_at', $model->dateColumns(), true) && ! $model->getAttribute('created_at')) {
                $model->setAttribute('created_at', now());
            }
        });
    }

    /** Columns that hold a creation time for this table. */
    protected function dateColumns(): array
    {
        return ['created_at'];
    }
}
