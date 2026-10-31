<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Services\Access;
use App\Services\Activity;
use App\Services\Analytics;
use App\Services\Billing;
use App\Services\Passwords;
use App\Services\Payouts;
use App\Services\Site;
use App\Support\ApiError;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Topline admin: overview, stores, activity, payments, payouts, site content, system health.
 * Admins can also open any store's shop admin through /api/vendor/stores/{id}/...
 */
class AdminController extends ApiController
{
    public function overview()
    {
        return $this->json(Analytics::overview());
    }

    public function stores()
    {
        $since = now()->subDays(30);
        $productCounts = Product::groupBy('store_id')->selectRaw('store_id, COUNT(*) as n')->pluck('n', 'store_id');
        $gmv = Sale::where('created_at', '>=', $since)
            ->whereNotIn('status', [Sale::AWAITING_PAYMENT, Sale::AWAITING_CONFIRMATION, Sale::CANCELLED])
            ->groupBy('store_id')->selectRaw('store_id, SUM(total) as total')->pluck('total', 'store_id');
        $stores = Store::with('plan')->orderByDesc('created_at')->get();

        return $this->json($stores->map(fn ($s) => [
            ...Json::storePrivate($s),
            'products' => (int) ($productCounts[$s->id] ?? 0),
            'gmv30' => (int) ($gmv[$s->id] ?? 0),
            'plan' => $s->plan ? Json::plan($s->plan) : null,
        ])->all());
    }

    /** Suspend/reactivate, change plan without payment (e.g. a trial), or extend the paid period. */
    public function updateStore(Request $request, string $storeId)
    {
        $store = Store::find($storeId) ?? throw ApiError::notFound('Store not found.');
        $data = $this->body($request);
        $admin = Access::currentUser();
        DB::transaction(function () use ($store, $data, $admin) {
            if (array_key_exists('status', $data)) {
                if (! in_array($data['status'], Store::STATUSES, true)) {
                    throw ApiError::invalid('Unknown status.', 'status');
                }
                if ($data['status'] !== $store->status) {
                    $verb = ['suspended' => 'suspended', 'active' => 'reactivated'][$data['status']] ?? "set to {$data['status']}";
                    Activity::record('store_status', "{$admin->name} {$verb} {$store->name}", $store->id, $admin,
                        ['before' => $store->status, 'after' => $data['status']]);
                }
                $store->status = $data['status'];
            }
            if (array_key_exists('planId', $data)) {
                $plan = Billing::plan($data['planId']);
                if ($plan->id !== $store->plan_id) {
                    Activity::record('store_status', "{$admin->name} moved {$store->name} to the {$plan->name} plan", $store->id, $admin);
                }
                $store->plan_id = $plan->id;
            }
            if (array_key_exists('extendDays', $data)) {
                $days = (int) $data['extendDays'];
                $start = $store->next_billing_at && $store->next_billing_at->gt(now()) ? $store->next_billing_at->copy() : now();
                $store->next_billing_at = $start->addDays($days);
                Activity::record('store_status', "{$admin->name} gave {$store->name} {$days} extra days", $store->id, $admin);
            }
            $store->save();
        });

        return $this->json(Json::storePrivate($store->refresh()));
    }

    /** A temporary password for the shop owner, shown once. They must change it when they log in. */
    public function resetOwnerPassword(string $storeId)
    {
        $store = Store::find($storeId);
        $owner = $store?->owner();
        if (! $owner) {
            throw ApiError::notFound('Store not found.');
        }
        $temp = Passwords::adminReset($owner, Access::currentUser(), $store->id);

        return $this->json(['temporaryPassword' => $temp, 'owner' => $owner->name]);
    }

    public function activity(Request $request)
    {
        $before = (string) $request->query('before', '');
        $rows = Activity::recent($request->query('storeId') ?: null, $request->query('kind') ?: null,
            ctype_digit($before) ? (int) $before : null, $this->limit($request, 50));
        $names = Store::pluck('name', 'id')->all();

        return $this->json($rows->map(fn ($a) => Json::activity($a, $names))->all());
    }

    public function payments(Request $request)
    {
        $names = Store::pluck('name', 'id')->all();
        $rows = Payment::orderByDesc('created_at')->limit($this->limit($request))->get();

        return $this->json($rows->map(fn (Payment $p) => [
            'reference' => $p->reference, 'kind' => $p->kind, 'status' => $p->status, 'provider' => $p->provider, 'amount' => $p->amount,
            'phone' => $p->phone, 'payee' => $p->payee, 'storeId' => $p->store_id, 'storeName' => $names[$p->store_id] ?? null,
            'planId' => $p->plan_id, 'mpesaRef' => $p->provider_ref, 'createdAt' => Text::iso($p->created_at), 'paidAt' => Text::iso($p->paid_at),
            'failureReason' => $p->failure_reason,
        ])->all());
    }

    public function payouts(Request $request)
    {
        $names = Store::pluck('name', 'id')->all();
        $query = Payout::orderByDesc('created_at')->orderByDesc('id')->limit($this->limit($request));
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->json($query->get()->map(fn ($p) => [...Json::payout($p), 'storeName' => $names[$p->store_id] ?? null])->all());
    }

    public function retryPayout(int $payoutId)
    {
        $payout = Payout::find($payoutId) ?? throw ApiError::notFound('Payout not found.');
        if ($payout->status === 'paid') {
            throw ApiError::invalid('This payout was already sent.');
        }

        return $this->json(Json::payout(Payouts::retry($payout, Store::find($payout->store_id))));
    }

    public function plans()
    {
        return $this->json(Plan::orderBy('sort_order')->get()->map(fn ($p) => Json::plan($p))->all());
    }

    public function updateSite(Request $request)
    {
        return $this->json(Site::update($this->body($request)));
    }

    public function incidents(Request $request)
    {
        $query = Incident::orderByDesc('last_seen')->limit($this->limit($request));
        if ($request->query('show', 'open') === 'open') {
            $query->where('resolved', false);
        }

        return $this->json($query->get()->map(fn (Incident $i) => [
            'id' => $i->id, 'kind' => $i->kind, 'title' => $i->title, 'detail' => $i->detail ?? '', 'path' => $i->path, 'count' => $i->count,
            'firstSeen' => Text::iso($i->first_seen), 'lastSeen' => Text::iso($i->last_seen), 'resolved' => (bool) $i->resolved,
        ])->all());
    }

    public function resolveIncident(Request $request, int $incidentId)
    {
        $incident = Incident::find($incidentId) ?? throw ApiError::notFound('Incident not found.');
        $incident->resolved = (bool) ($this->body($request)['resolved'] ?? true);
        $incident->save();

        return $this->json(['id' => $incident->id, 'resolved' => $incident->resolved]);
    }
}
