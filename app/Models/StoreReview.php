<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone's review of a hardware shop. Seen only by Topline admins. */
class StoreReview extends Model
{
    public const NEW = 'new';
    public const DONE = 'done';

    protected const ID_PREFIX = 'rv';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['rating' => 'integer', 'visited_on' => 'date', 'created_at' => 'datetime'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
