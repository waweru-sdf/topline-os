<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\PageView;
use App\Models\RequestStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traffic counters and incident tracking (with alerts) for the Topline admin.
 * Best-effort: a monitoring hiccup must never break a customer's request.
 */
class Monitoring
{
    public const ALERT_AT = [1, 10, 100, 1000, 10000]; // alert on the 1st, 10th, 100th... occurrence

    /** +1 visit to the main site or to a store page, for today (Kenyan date). */
    public static function countView(string $page, ?string $storeId = null): void
    {
        try {
            $day = now(config('topline.timezone'))->toDateString();
            $query = PageView::where('day', $day)->where('page', $page);
            $storeId === null ? $query->whereNull('store_id') : $query->where('store_id', $storeId);
            $updated = $query->increment('views');
            if (! $updated) {
                PageView::create(['day' => $day, 'store_id' => $storeId, 'page' => $page, 'views' => 1]);
            }
        } catch (Throwable $e) {
            Log::debug('countView: '.$e->getMessage());
        }
    }

    /** Hourly API totals: requests, 4xx, 5xx, time spent. */
    public static function countRequest(int $status, int $ms): void
    {
        try {
            $hour = now()->utc()->startOfHour()->format('Y-m-d H:i:s');
            DB::table('request_stats')->insertOrIgnore(['hour' => $hour]);
            DB::table('request_stats')->where('hour', $hour)->update([
                'requests' => DB::raw('requests + 1'),
                'client_errors' => DB::raw('client_errors + '.(($status >= 400 && $status < 500) ? 1 : 0)),
                'server_errors' => DB::raw('server_errors + '.($status >= 500 ? 1 : 0)),
                'total_ms' => DB::raw('total_ms + '.$ms),
                'slowest_ms' => DB::raw("CASE WHEN slowest_ms < {$ms} THEN {$ms} ELSE slowest_ms END"),
            ]);
        } catch (Throwable $e) {
            Log::debug('countRequest: '.$e->getMessage());
        }
    }

    /** Log a problem. The same problem again only bumps its counter; admins are alerted at 1, 10, 100... */
    public static function recordIncident(string $kind, string $title, string $detail = '', string $path = ''): ?Incident
    {
        try {
            $fingerprint = hash('sha256', "{$kind}|{$title}|{$path}");
            $now = now();
            $incident = Incident::firstOrNew(['fingerprint' => $fingerprint]);
            if (! $incident->exists) {
                $incident->fill(['kind' => $kind, 'title' => mb_substr($title, 0, 200), 'detail' => mb_substr($detail, 0, 4000),
                    'path' => mb_substr($path, 0, 200), 'count' => 0, 'notified_count' => 0, 'first_seen' => $now]);
            }
            $incident->count += 1;
            $incident->last_seen = $now;
            $incident->detail = mb_substr($detail, 0, 4000) ?: $incident->detail;
            $reopened = (bool) $incident->resolved;
            $incident->resolved = false;
            $alert = in_array($incident->count, self::ALERT_AT, true) || $reopened;
            if ($alert) {
                $incident->notified_count += 1;
            }
            $incident->save();
            if ($alert) {
                Alerts::send("[Topline] {$kind}: {$title}", "{$title}\nSeen {$incident->count} time(s). Path: ".($path ?: '-')."\n".mb_substr($detail, 0, 500));
            }

            return $incident;
        } catch (Throwable $e) {
            Log::error("Could not record incident {$title}: ".$e->getMessage());

            return null;
        }
    }
}
