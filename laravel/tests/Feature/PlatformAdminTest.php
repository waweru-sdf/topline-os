<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Services\Alerts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Topline admin: overview numbers, site content, uploads, monitoring, alerts and activity. */
class PlatformAdminTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n";

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bags.png', self::PNG.str_repeat("\0", 64));
    }

    public function test_overview_tallies(): void
    {
        $d = $this->getJson('/api/admin/overview', $this->admin()['headers'])->json();
        $this->assertSame(4, $d['stores']['byStatus']['active']);
        $this->assertGreaterThan(0, $d['stores']['monthlyRevenue']);
        $this->assertSame([6, 6, 5], [$d['customers']['onlineBuyers'], $d['customers']['repeatBuyers'], $d['customers']['creditCustomers']]);
        $this->assertCount(30, $d['traffic']['byDay']);
        $this->assertGreaterThan(0, $d['traffic']['topStores'][0]['views']);
        $this->assertGreaterThan(0, $d['money']['payouts']['paid']['count']);
    }

    public function test_storefront_visits_are_counted(): void
    {
        $admin = $this->admin();
        $before = $this->getJson('/api/admin/overview', $admin['headers'])->json('traffic.total30');
        $this->getJson('/api/storefront/mwamba');
        $this->getJson('/api/site?view=landing');
        $this->assertSame($before + 2, $this->getJson('/api/admin/overview', $admin['headers'])->json('traffic.total30'));
    }

    public function test_site_content_edit(): void
    {
        $admin = $this->admin();
        $this->assertStringStartsWith('Your hardware shop', $this->getJson('/api/site')->json('heroTitle'));
        $this->putJson('/api/admin/site', ['heroTitle' => 'Hi'], $this->owner()['headers'])->assertStatus(403);
        $this->putJson('/api/admin/site', ['heroTitle' => 'Sell online today', 'heroImage' => 'https://x/y.jpg'], $admin['headers'])
            ->assertJson(['heroTitle' => 'Sell online today']);
        $this->assertSame('https://x/y.jpg', $this->getJson('/api/site')->json('heroImage'));
        $this->putJson('/api/admin/site', ['nope' => 1], $admin['headers'])->assertStatus(422);
    }

    public function test_vendor_uploads_product_photo(): void
    {
        $o = $this->owner();
        $res = $this->post('/api/uploads', ['file' => $this->png(), 'storeId' => $o['storeId']], [...$o['headers'], 'Accept' => 'application/json']);
        $res->assertStatus(201);
        $url = $res->json('url');
        $this->assertStringEndsWith('.png', $url);
        $served = $this->get(parse_url($url, PHP_URL_PATH));
        $served->assertOk();
        $this->assertStringStartsWith(self::PNG, $served->streamedContent() ?: $served->getContent());
    }

    public function test_upload_rejects_non_images_and_other_stores(): void
    {
        $o = $this->owner();
        $h = [...$o['headers'], 'Accept' => 'application/json'];
        $fake = UploadedFile::fake()->createWithContent('x.png', '<script>alert(1)</script>');
        $this->post('/api/uploads', ['file' => $fake, 'storeId' => $o['storeId']], $h)->assertStatus(422);
        // no store: only Topline admins may upload site images
        $this->post('/api/uploads', ['file' => $this->png()], $h)->assertStatus(404);
    }

    public function test_server_errors_become_incidents_and_alert(): void
    {
        $admin = $this->admin();
        config(['topline.alert_webhook_url' => 'https://hooks.example/alert']);
        Http::fake(['hooks.example/*' => Http::response('ok')]);
        \Illuminate\Support\Facades\Route::get('/api/_boom', fn () => throw new \RuntimeException('database on fire'))->middleware('api');

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/_boom')->assertStatus(500)->assertJson(['message' => 'Something went wrong on our side. Try again in a moment.']);
        }
        $incident = Incident::where('kind', 'server_error')->first();
        $this->assertSame(3, (int) $incident->count);
        $this->assertStringContainsString('database on fire', $incident->title);
        $this->app->terminate();
        Http::assertSentCount(1); // alerted on the first occurrence only (then at 10, 100...)

        $this->assertSame(3, $this->getJson('/api/admin/incidents', $admin['headers'])->json('0.count'));
        $this->patchJson("/api/admin/incidents/{$incident->id}", ['resolved' => true], $admin['headers']);
        $this->assertSame([], $this->getJson('/api/admin/incidents', $admin['headers'])->json());
        $health = $this->getJson('/api/admin/overview', $admin['headers'])->json('health');
        $this->assertGreaterThanOrEqual(3, $health['serverErrors24']);
        $this->assertGreaterThan(0, $health['requests24']);
    }

    public function test_browser_errors_are_reported(): void
    {
        $this->postJson('/api/telemetry/error', ['message' => 'TypeError: x is undefined', 'path' => '/dashboard']);
        $kinds = array_column($this->getJson('/api/admin/incidents', $this->admin()['headers'])->json(), 'kind');
        $this->assertContains('browser', $kinds);
    }

    public function test_admin_lists(): void
    {
        $h = $this->admin()['headers'];
        foreach (['/api/admin/payments', '/api/admin/payouts', '/api/admin/plans', '/api/admin/stores'] as $path) {
            $this->getJson($path, $h)->assertOk();
        }
    }

    public function test_activity_across_all_shops_and_per_shop(): void
    {
        $admin = $this->admin();
        $owner = $this->owner();
        $rift = $this->store('rift-timber');
        $this->patchJson("/api/admin/stores/{$rift->id}", ['status' => 'active'], $admin['headers']);
        $this->patchJson("/api/admin/stores/{$rift->id}", ['status' => 'suspended'], $admin['headers']);

        $all = $this->getJson('/api/admin/activity?limit=200', $admin['headers'])->json();
        $kinds = array_unique(array_column($all, 'kind'));
        foreach (['counter_sale', 'order_placed', 'store_created', 'store_status', 'login'] as $k) {
            $this->assertContains($k, $kinds);
        }
        $times = array_column($all, 'createdAt');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times); // newest first
        $this->assertNotEmpty(array_filter($all, fn ($a) => $a['summary'] === 'Topline Admin suspended Rift Timber Yard' && $a['storeName'] === 'Rift Timber Yard'));

        $onlyRift = $this->getJson("/api/admin/activity?storeId={$rift->id}", $admin['headers'])->json();
        $this->assertNotEmpty($onlyRift);
        $this->assertSame([$rift->id], array_values(array_unique(array_column($onlyRift, 'storeId'))));
        $onlyStatus = $this->getJson('/api/admin/activity?kind=store_status', $admin['headers'])->json();
        $this->assertSame(['store_status'], array_values(array_unique(array_column($onlyStatus, 'kind'))));
        $page2 = $this->getJson("/api/admin/activity?before={$all[9]['id']}&limit=10", $admin['headers'])->json();
        $this->assertSame($all[10]['id'], $page2[0]['id']);

        $mine = $this->getJson("/api/vendor/stores/{$owner['storeId']}/activity", $owner['headers'])->json();
        $this->assertSame([$owner['storeId']], array_values(array_unique(array_column($mine, 'storeId'))));
        $this->getJson('/api/admin/activity', $owner['headers'])->assertStatus(403);
    }

    public function test_health_and_unknown_api_paths(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['ok' => true, 'payments' => 'simulation']);
        $this->getJson('/api/no-such-thing')->assertStatus(404)->assertJson(['message' => 'Not found.']);
    }
}
