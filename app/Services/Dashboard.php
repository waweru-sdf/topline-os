<?php

namespace App\Services;

use App\Http\Presenters\Json;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;

/** Numbers for the shop owner's Overview page (last 14 days, Kenyan days), for the whole shop or one branch. */
class Dashboard
{
    public const DAYS = 14;

    public static function build(Store $store, bool $showCost = true, ?Branch $branch = null): array
    {
        $tz = config('topline.timezone');
        $now = now();
        $today = $now->copy()->setTimezone($tz);
        $since = $today->copy()->subDays(self::DAYS - 1)->startOfDay();
        $sales = Sale::with('items')->where('store_id', $store->id)->where('created_at', '>=', $since->copy()->utc())
            ->whereNotIn('status', [Sale::AWAITING_PAYMENT, Sale::AWAITING_CONFIRMATION, Sale::CANCELLED])
            ->when($branch, fn ($q) => $branch->is_main
                ? $q->where(fn ($w) => $w->where('branch_id', $branch->id)->orWhereNull('branch_id')) // online orders and older sales: main
                : $q->where('branch_id', $branch->id))
            ->orderByDesc('created_at')->get();

        $perDay = [];
        $byProduct = [];
        foreach ($sales as $s) {
            $key = $s->created_at->copy()->setTimezone($tz)->toDateString();
            $perDay[$key] ??= ['revenue' => 0, 'profit' => 0, 'count' => 0];
            $perDay[$key]['revenue'] += $s->total;
            $perDay[$key]['profit'] += $s->items->sum(fn ($i) => $i->qty * ($i->price - $i->cost));
            $perDay[$key]['count'] += 1;
            foreach ($s->items as $i) {
                $byProduct[$i->name] = ($byProduct[$i->name] ?? 0) + $i->qty * $i->price;
            }
        }
        $byDay = [];
        for ($offset = self::DAYS - 1; $offset >= 0; $offset--) {
            $key = $today->copy()->subDays($offset)->toDateString();
            $day = $perDay[$key] ?? ['revenue' => 0, 'profit' => 0, 'count' => 0];
            $byDay[] = ['date' => $key, 'revenue' => $day['revenue'], 'profit' => $showCost ? $day['profit'] : 0, 'count' => $day['count']];
        }
        $last7 = array_slice($byDay, -7);
        $prev7 = array_slice($byDay, 0, 7);

        $customers = Customer::where('store_id', $store->id)->get();
        $products = Product::where('store_id', $store->id)->get();
        $stock = $branch ? Branches::stockMap($store, $branch) : $products->pluck('stock', 'id')->all();
        arsort($byProduct);

        return [
            'byDay' => $byDay,
            'revenue7' => array_sum(array_column($last7, 'revenue')),
            'revenuePrev7' => array_sum(array_column($prev7, 'revenue')),
            'profit7' => array_sum(array_column($last7, 'profit')),
            'sales7' => array_sum(array_column($last7, 'count')),
            'owedToYou' => (int) $customers->sum('balance'),
            'overdueCustomers' => $customers->filter(fn ($c) => $c->balance > 0 && $c->due_at && $c->due_at->lt($now))->count(),
            // At a branch, only products it has ever stocked (a new branch doesn't list the whole catalogue).
            'lowStock' => $products->filter(fn ($p) => ($branch?->is_main === false ? array_key_exists($p->id, $stock) : true) && ($stock[$p->id] ?? 0) <= $p->reorder_at)
                ->map(fn ($p) => Json::product($p, $showCost, $stock[$p->id] ?? 0))->values()->all(),
            'newOrders' => Sale::where('store_id', $store->id)->where('channel', Sale::ONLINE)
                ->whereIn('status', [Sale::NEW, Sale::AWAITING_CONFIRMATION])->count(),
            'topProducts' => collect($byProduct)->take(5)->map(fn ($r, $n) => ['name' => $n, 'revenue' => $r])->values()->all(),
            'recent' => $sales->take(6)->map(fn ($s) => Json::sale($s, $showCost))->values()->all(),
        ];
    }
}
