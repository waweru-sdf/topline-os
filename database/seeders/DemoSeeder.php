<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PageView;
use App\Models\Payout;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\Text;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data (same shops as the reference backend): 2 admins, 7 hardware shops with products,
 * measurements, payout methods and delivery zones, Mjengo's credit customers, two weeks of sales
 * with their activity, and a month of visits. Every demo account's password is "topline123".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/demo-data.json'), true);
        mt_srand(42); // the same demo every time
        $now = now();
        $hash = Hash::make($data['demoPassword']); // hashed once: every demo account shares it

        DB::transaction(function () use ($data, $now, $hash) {
            foreach ($data['admins'] as $a) {
                User::updateOrCreate(['phone' => $a['phone']], ['name' => $a['name'], 'email' => $a['email'],
                    'role' => User::PLATFORM_ADMIN, 'password' => $hash, 'is_active' => true]);
            }
            foreach ($data['stores'] as $spec) {
                if (Store::where('slug', $spec['slug'])->exists()) {
                    continue; // already seeded
                }
                [$store, $products] = $this->createStore($spec, $data, $now, $hash);
                if ($spec['slug'] === 'mjengo') {
                    foreach ($data['mjengoCustomers'] as $c) {
                        Customer::create(['store_id' => $store->id, 'name' => $c['name'], 'phone' => $c['phone'], 'credit_limit' => $c['limit'],
                            'balance' => $c['balance'], 'due_at' => $c['dueDays'] !== null ? $now->copy()->addDays($c['dueDays']) : null]);
                    }
                }
                if (in_array($store->status, [Store::ACTIVE, Store::PAST_DUE, Store::SUSPENDED], true)) {
                    $this->sales($store, $products, $data['buyers'], $now, $spec['slug'] === 'mjengo' ? 64 : mt_rand(25, 45));
                    $this->views($store->id, $store->status === Store::ACTIVE ? 60 : 15);
                }
            }
            if (! PageView::whereNull('store_id')->exists()) {
                $this->views(null, 180); // main site
            }
        });
    }

    private function createStore(array $spec, array $data, $now, string $hash): array
    {
        $owner = $spec['owner'];
        $phone = $owner['phone'];
        $local = '0'.substr($phone, 3, 3).' '.substr($phone, 6, 3).' '.substr($phone, 9);
        $quote = $spec['delivery']['mode'] === 'quote';
        $store = Store::create([
            'slug' => $spec['slug'], 'name' => $spec['name'], 'tagline' => $spec['tagline'], 'county' => $spec['county'], 'town' => $spec['town'],
            'address' => $spec['address'], 'phone' => $local, 'whatsapp' => $phone, 'hours' => $spec['hours'], 'accent' => $spec['accent'],
            'cover' => $spec['cover'], 'payout_method' => $spec['payout']['method'], 'payout_number' => $spec['payout']['number'],
            'payout_account' => $spec['payout']['account'], 'payout_name' => $spec['name'],
            'delivery_enabled' => (bool) $spec['delivery']['zones'] || $quote, 'delivery_mode' => $spec['delivery']['mode'],
            'delivery_zones' => $spec['delivery']['zones'], 'delivery_free_over' => $spec['delivery']['freeOver'],
            'delivery_note' => $quote ? 'Delivery cost agreed by phone after you order (lorry or pickup depending on size).'
                : 'Same-day delivery for orders placed before 2 pm.',
            'plan_id' => $spec['plan'], 'status' => $spec['status'], 'created_at' => $now->copy()->subDays($spec['age']),
            'next_billing_at' => $spec['due'] !== null ? $now->copy()->addDays($spec['due']) : null,
        ]);
        $first = strtolower(explode(' ', $owner['name'])[0]);
        $user = User::create(['name' => $owner['name'], 'phone' => $phone, 'email' => "{$first}@{$spec['slug']}.example.com",
            'role' => User::VENDOR, 'password' => $hash]);
        StoreMember::create(['store_id' => $store->id, 'user_id' => $user->id, 'role' => StoreMember::OWNER]);
        ActivityLog::create(['store_id' => $store->id, 'actor_id' => $user->id, 'actor_name' => $owner['name'], 'kind' => 'store_created',
            'summary' => "{$owner['name']} opened {$spec['name']}", 'data' => ['plan' => $spec['plan']], 'created_at' => $store->created_at]);
        if ($spec['status'] === Store::SUSPENDED) {
            ActivityLog::create(['store_id' => $store->id, 'actor_name' => 'System', 'kind' => 'store_status',
                'summary' => "{$spec['name']} was suspended: plan not paid after the grace period", 'data' => [], 'created_at' => $now->copy()->subDays(2)]);
        }

        $products = [];
        $used = [];
        foreach ($spec['products'] as $p) {
            $base = Text::slugify($p['name']);
            $slug = in_array($base, $used, true) ? Text::slugify("{$p['name']} {$p['unit']}") : $base;
            $used[] = $base;
            $m = $data['measurements'][$p['name']] ?? ['size' => '', 'specs' => []];
            $products[] = Product::create(['store_id' => $store->id, 'slug' => $slug, 'name' => $p['name'], 'category_id' => $p['category'],
                'unit' => $p['unit'], 'sell_price' => $p['sell'], 'buy_price' => $p['buy'], 'stock' => $p['stock'], 'image' => $p['photo'],
                'description' => $p['description'], 'reorder_at' => $p['reorderAt'], 'size' => $m['size'], 'specs' => $m['specs'],
                'gallery' => [], 'alt_units' => []]);
        }

        return [$store, $products];
    }

    private function sales(Store $store, array $products, array $buyers, $now, int $count): void
    {
        $staff = ['Peter K.', 'Esther W.', 'Brian O.'];
        // Oldest first, so ids follow time like real activity does.
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $times[] = $now->copy()->subDays(mt_rand(0, 13))->subHours(mt_rand(0, 9));
        }
        usort($times, fn ($a, $b) => $a <=> $b);
        foreach ($times as $i => $at) {
            $online = mt_rand() / mt_getrandmax() < 0.35;
            $items = [];
            for ($k = 0, $n = 1 + mt_rand(0, 2); $k < $n; $k++) {
                $prod = $products[mt_rand(0, count($products) - 1)];
                $qty = max(1, (int) round(mt_rand() / mt_getrandmax() * ($prod->sell_price < 200 ? 60 : 12)));
                $items[] = ['product_id' => $prod->id, 'name' => $prod->name, 'unit' => $prod->unit, 'qty' => $qty, 'price' => $prod->sell_price, 'cost' => $prod->buy_price];
            }
            $subtotal = array_sum(array_map(fn ($it) => $it['qty'] * $it['price'], $items));
            [$fee, $zone, $type] = [0, null, null];
            if ($online) {
                $type = $store->delivery_enabled && $i % 2 ? 'delivery' : 'pickup';
                if ($type === 'delivery' && $store->delivery_zones) {
                    $z = $store->delivery_zones[mt_rand(0, count($store->delivery_zones) - 1)];
                    $zone = $z['name'];
                    $fee = $store->delivery_free_over && $subtotal >= $store->delivery_free_over ? 0 : $z['fee'];
                }
            }
            [$buyerName, $buyerPhone] = $buyers[$i % count($buyers)];
            $sale = Sale::create([
                'store_id' => $store->id, 'channel' => $online ? 'online' : 'pos',
                'method' => $online ? 'mpesa' : ['mpesa', 'mpesa', 'mpesa', 'cash', 'cash', 'bank'][mt_rand(0, 5)],
                'payment_mode' => $online ? 'prompt' : null,
                'status' => $online ? ['new', 'confirmed', 'dispatched', 'completed', 'completed', 'completed'][$i % 6] : 'completed',
                'staff_name' => $online ? null : $staff[$i % 3],
                'customer_name' => $online ? $buyerName : null, 'customer_phone' => $online ? $buyerPhone : null,
                'delivery_type' => $type, 'delivery_zone' => $zone,
                'delivery_address' => $type === 'delivery' ? "{$store->town}, near the main road" : null,
                'subtotal' => $subtotal, 'delivery_fee' => $fee, 'total' => $subtotal + $fee,
                'created_at' => $at, 'paid_at' => $at, 'mpesa_ref' => $online ? sprintf('TSD%06d', mt_rand(0, 999999)) : null,
            ]);
            foreach ($items as $it) {
                $sale->items()->create($it);
            }
            $first = "{$items[0]['qty']} × {$items[0]['name']}".(count($items) > 1 ? ' and '.(count($items) - 1).' more' : '');
            ActivityLog::create($online
                ? ['store_id' => $store->id, 'actor_name' => "Customer {$buyerName}", 'kind' => 'order_placed', 'created_at' => $at,
                    'summary' => "{$buyerName} ordered {$first} — ".Text::kes($sale->total), 'data' => ['order' => $sale->code]]
                : ['store_id' => $store->id, 'actor_name' => $sale->staff_name, 'kind' => 'counter_sale', 'created_at' => $at,
                    'summary' => "Counter sale {$sale->code}: {$first} — ".Text::kes($sale->total)." ({$sale->method})", 'data' => ['sale' => $sale->code]]);
            if ($online) {
                Payout::create(['reference' => "PO_SEED_{$sale->number}", 'store_id' => $store->id, 'sale_number' => $sale->number,
                    'gross' => $sale->total, 'commission' => 0, 'amount' => $sale->total, 'method' => $store->payout_method,
                    'destination' => ucfirst(str_replace('_', ' ', $store->payout_method))." {$store->payout_number}", 'status' => 'paid',
                    'provider_ref' => "TRF_SEED{$sale->number}", 'created_at' => $at, 'paid_at' => $at]);
            }
        }
    }

    private function views(?string $storeId, int $base): void
    {
        $today = now(config('topline.timezone'))->startOfDay();
        for ($d = 0; $d < 30; $d++) {
            $day = $today->copy()->subDays($d);
            $weekend = $day->isWeekend();
            $views = (int) ($base * ($weekend ? 0.6 : 1) * (0.7 + mt_rand() / mt_getrandmax() * 0.6) * (1 + (30 - $d) / 60));
            PageView::create(['day' => $day->toDateString(), 'store_id' => $storeId, 'page' => $storeId ? 'store' : 'landing', 'views' => $views]);
        }
    }
}
