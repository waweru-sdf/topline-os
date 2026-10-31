<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Ctx;
use Illuminate\Support\Collection;

/**
 * The activity log: what happened in each store, for the Topline admin and the shop owner.
 * Call record() inside the same transaction as the change it describes.
 */
class Activity
{
    /** $actor: a User, a display name such as "Customer Jane", or null for the logged-in user. */
    public static function record(string $kind, string $summary, ?string $storeId = null, User|string|null $actor = null, array $data = []): void
    {
        $actor ??= Ctx::user();
        if (is_string($actor)) {
            [$actorId, $actorName] = [null, $actor];
        } elseif ($actor instanceof User) {
            [$actorId, $actorName] = [$actor->id, $actor->name];
        } else {
            [$actorId, $actorName] = [null, 'System'];
        }
        ActivityLog::create([
            'store_id' => $storeId,
            'actor_id' => $actorId,
            'actor_name' => mb_substr($actorName, 0, 120),
            'kind' => $kind,
            'summary' => mb_substr($summary, 0, 300),
            'data' => $data,
        ]);
    }

    /** Newest first (by time, then id). Page with $beforeId = the id of the last entry already shown. */
    public static function recent(?string $storeId = null, ?string $kind = null, ?int $beforeId = null, int $limit = 50): Collection
    {
        $query = ActivityLog::query()->orderByDesc('created_at')->orderByDesc('id')->limit(min(max($limit, 1), 200));
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        if ($kind) {
            $query->where('kind', $kind);
        }
        if ($beforeId && ($last = ActivityLog::find($beforeId))) {
            $query->where(fn ($q) => $q->where('created_at', '<', $last->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $last->created_at)->where('id', '<', $last->id)));
        }

        return $query->get();
    }
}
