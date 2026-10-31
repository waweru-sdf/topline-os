<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Incident;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\Sale;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Replays the same calls as the reference backend's tools/contract_examples.py and compares every
 * response with docs/api-examples.json: same status codes, same keys, same value types.
 * Writes what Laravel answered to storage/framework/testing/laravel-examples.json for inspection.
 */
class ContractExamplesTest extends TestCase
{
    private array $examples = [];

    private function record(string $method, string $path, ?array $body = null, array $headers = [], bool $form = false): ?array
    {
        $res = $form
            ? $this->call_multipart($path, $headers)
            : $this->json($method, $path, $body ?? [], $headers);
        $data = $res->status() === 204 ? null : json_decode($res->getContent(), true);
        $this->examples[] = ['request' => "{$method} {$path}", 'status' => $res->status(), 'response' => $data];

        return $data;
    }

    private function call_multipart(string $path, array $headers)
    {
        $file = UploadedFile::fake()->createWithContent('a.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64));

        return $this->post($path, ['file' => $file, 'storeId' => $this->store()->id], [...$headers, 'Accept' => 'application/json']);
    }

    public function test_responses_match_the_reference_examples(): void
    {
        $this->record('GET', '/api/health');
        $this->record('GET', '/api/ping');
        $this->record('GET', '/api/plans');
        $this->record('GET', '/api/site');
        $this->record('GET', '/api/stores/check-slug?slug=Baraka Hardware');
        $this->record('POST', '/api/auth/login', ['login' => '0712 345 678', 'password' => 'topline123']);
        $this->record('POST', '/api/auth/login', ['login' => '0712 345 678', 'password' => 'wrong']);
        $owner = $this->owner()['headers'];
        $admin = $this->admin()['headers'];
        $this->record('GET', '/api/auth/me', null, $owner);
        $sid = $this->store()->id;

        $this->record('GET', '/api/storefront/mjengo');
        $hammer = $this->product('claw-hammer-16-oz');
        $this->record('GET', '/api/storefront/mjengo/products/d12-deformed-bar-y12');
        $order = $this->record('POST', '/api/storefront/mjengo/orders', ['customer' => ['name' => 'Daniel K', 'phone' => '0711 222 333'],
            'items' => [['productId' => $hammer->id, 'qty' => 2]], 'delivery' => ['type' => 'delivery', 'zoneId' => 'z2', 'address' => 'EPZ gate B'],
            'paymentMode' => 'prompt']);
        $this->record('GET', "/api/storefront/mjengo/orders/{$order['id']}/payment");
        $this->record('POST', '/api/storefront/mjengo/orders', ['customer' => ['name' => 'Kevin', 'phone' => '0766 888 999'], 'items' => [['productId' => $hammer->id, 'qty' => 999]]]);
        $this->record('POST', '/api/storefront/mjengo/orders', ['customer' => ['name' => 'Kevin', 'phone' => '0766 888 999'],
            'items' => [['productId' => $hammer->id, 'qty' => 1]], 'paymentMode' => 'direct', 'mpesaCode' => 'TJK4H2PQ9X']);
        $this->record('POST', '/api/storefront/mjengo/quotes', ['name' => 'Site manager', 'phone' => '0711 000 111', 'items' => '200 bags cement', 'site' => 'Isinya']);

        $pay = $this->record('POST', '/api/billing/subscription/pay', ['phone' => '0722 111 222', 'planId' => 'business', 'period' => 'monthly', 'signup' => true]);
        $this->record('GET', "/api/billing/payments/{$pay['reference']}");
        config(['topline.simulation_approval' => 'prompt']);
        $pending = $this->postJson('/api/billing/subscription/pay', ['phone' => '0722 111 999', 'planId' => 'starter', 'period' => 'monthly', 'signup' => true])->json();
        $this->record('POST', "/api/simulator/payments/{$pending['reference']}", ['action' => 'approve', 'pin' => '1234']);
        config(['topline.simulation_approval' => 'auto']);
        $this->record('POST', '/api/stores', ['owner' => ['name' => 'Grace Akinyi', 'phone' => '0722 111 222', 'email' => 'grace@example.com', 'password' => 'shop-pass-1'],
            'business' => ['name' => 'Baraka Hardware', 'county' => 'Kajiado', 'town' => 'Kitengela', 'payments' => ['method' => 'till', 'number' => '5566778']],
            'planId' => 'business', 'slug' => 'baraka-hardware', 'billing' => ['period' => 'monthly', 'paymentReference' => $pay['reference']]]);

        $this->record('GET', '/api/vendor/stores', null, $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}", null, $owner);
        $this->record('PATCH', "/api/vendor/stores/{$sid}", ['tagline' => 'Building materials for Kitengela',
            'payments' => ['method' => 'paybill', 'number' => '247247', 'account' => 'MJENGO']], $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/dashboard", null, $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/products", null, $owner);
        $prod = $this->record('POST', "/api/vendor/stores/{$sid}/products", ['name' => 'Deformed bar Y20', 'categoryId' => 'steel', 'unit' => 'length (12 m)',
            'sellPrice' => 2650, 'buyPrice' => 2300, 'stock' => 80, 'size' => '20 mm × 12 m',
            'specs' => [['label' => 'Grade', 'value' => 'Y20']], 'image' => 'rebar', 'gallery' => [], 'description' => 'Heavy bar.', 'published' => true], $owner);
        $this->record('PUT', "/api/vendor/stores/{$sid}/products/{$prod['id']}", [...$prod, 'sellPrice' => 2700], $owner);
        $this->record('POST', "/api/vendor/stores/{$sid}/products/{$prod['id']}/stock", ['delta' => -5], $owner);
        $this->record('DELETE', "/api/vendor/stores/{$sid}/products/{$prod['id']}", null, $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/sales?channel=online", null, $owner);
        $this->record('POST', "/api/vendor/stores/{$sid}/sales", ['items' => [['productId' => $hammer->id, 'qty' => 1]], 'method' => 'cash'], $owner);
        $direct = Sale::where('store_id', $sid)->where('status', 'awaiting_confirmation')->first();
        $this->record('PATCH', "/api/vendor/stores/{$sid}/sales/{$direct->code}", ['status' => 'new'], $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/customers", null, $owner);
        $cust = Customer::where('store_id', $sid)->first();
        $this->record('GET', "/api/vendor/stores/{$sid}/customers/{$cust->id}", null, $owner);
        $this->record('POST', "/api/vendor/stores/{$sid}/customers", ['name' => 'Fundi Otieno', 'phone' => '0700 123 456', 'creditLimit' => 50000], $owner);
        $this->record('POST', "/api/vendor/stores/{$sid}/customers/{$cust->id}/payments", ['amount' => 1000], $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/quotes", null, $owner);
        $q = Quote::where('store_id', $sid)->first();
        $this->record('PATCH', "/api/vendor/stores/{$sid}/quotes/{$q->id}", ['status' => 'replied'], $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/payouts", null, $owner);
        $this->record('GET', "/api/vendor/stores/{$sid}/activity", null, $owner);
        $this->record('POST', '/api/uploads', null, $owner, form: true);
        $this->record('POST', '/api/auth/password', ['currentPassword' => 'topline123', 'newPassword' => 'new-pass-123'], $owner);

        foreach (['overview', 'stores', 'plans', 'payments', 'payouts', 'incidents?show=all', 'activity?limit=3'] as $path) {
            $this->record('GET', "/api/admin/{$path}", null, $admin);
        }
        $rift = $this->store('rift-timber');
        $this->record('PATCH', "/api/admin/stores/{$rift->id}", ['status' => 'active'], $admin);
        $this->record('POST', "/api/admin/stores/{$sid}/reset-password", null, $admin);
        $this->record('PUT', '/api/admin/site', ['announcement' => 'First month free'], $admin);
        $po = Payout::first();
        $po->status = 'failed';
        $po->save();
        $this->record('POST', "/api/admin/payouts/{$po->id}/retry", null, $admin);
        $this->postJson('/api/telemetry/error', ['message' => 'x is undefined', 'path' => '/s/mjengo']);
        $inc = Incident::first();
        $this->record('PATCH', "/api/admin/incidents/{$inc->id}", ['resolved' => true], $admin);
        $this->record('POST', '/api/telemetry/error', ['message' => 'TypeError: x is undefined', 'stack' => '...', 'path' => '/s/mjengo']);
        config(['topline.cron_secret' => 'k']);
        $this->record('GET', '/api/cron/run?key=k');
        $this->record('GET', "/api/vendor/stores/{$sid}", null, ['Authorization' => 'Bearer bad']);

        $dir = storage_path('framework/testing');
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/laravel-examples.json", json_encode($this->examples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $reference = json_decode(file_get_contents(base_path('../docs/api-examples.json')), true);
        $this->assertCount(count($reference), $this->examples);
        $problems = [];
        foreach ($reference as $i => $ref) {
            $got = $this->examples[$i];
            $where = "#{$i} {$ref['request']}";
            if ($ref['status'] !== $got['status']) {
                $problems[] = "{$where}: status {$got['status']}, expected {$ref['status']}";
            }
            $this->compare($ref['response'], $got['response'], $where, $problems);
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /** Same keys and value types. Lists: compare the first item. Nulls match anything (optional values). */
    private function compare(mixed $ref, mixed $got, string $path, array &$problems): void
    {
        if ($ref === null || $got === null) {
            return;
        }
        if (is_array($ref) && array_is_list($ref) && $ref !== []) {
            if (! is_array($got) || ! array_is_list($got)) {
                $problems[] = "{$path}: expected a list, got ".gettype($got);

                return;
            }
            if ($got !== [] && ! (is_string($ref[0]) && str_starts_with($ref[0], '... '))) {
                $this->compare($ref[0], $got[0], "{$path}[0]", $problems);
            }

            return;
        }
        if (is_array($ref) && ! array_is_list($ref)) {
            if (! is_array($got) || (array_is_list($got) && $got !== [])) {
                $problems[] = "{$path}: expected an object, got ".json_encode($got);

                return;
            }
            $missing = array_diff(array_keys($ref), array_keys($got));
            $extra = array_diff(array_keys($got), array_keys($ref));
            if ($missing) {
                $problems[] = "{$path}: missing keys ".implode(', ', $missing);
            }
            if ($extra) {
                $problems[] = "{$path}: extra keys ".implode(', ', $extra);
            }
            foreach ($ref as $k => $v) {
                if (array_key_exists($k, $got)) {
                    $this->compare($v, $got[$k], "{$path}.{$k}", $problems);
                }
            }

            return;
        }
        $type = fn ($v) => match (true) { is_bool($v) => 'bool', is_int($v), is_float($v) => 'number', is_string($v) => 'string', is_array($v) => 'array', default => gettype($v) };
        if ($type($ref) !== $type($got)) {
            $problems[] = "{$path}: expected {$type($ref)} like ".json_encode($ref).', got '.json_encode($got);
        }
    }
}
