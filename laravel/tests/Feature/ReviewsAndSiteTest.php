<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreReview;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Shop reviews (admin-only), editable plans, and the full set of site settings. */
class ReviewsAndSiteTest extends TestCase
{
    private function review(array $over = []): array
    {
        return [...['store' => 'mjengo', 'rating' => 4, 'body' => 'Delivered 40 bags of cement on time, fair price.', 'name' => 'Otieno',
            'phone' => '', 'visitedOn' => Carbon::now('Africa/Nairobi')->subDays(3)->format('Y-m-d')], ...$over];
    }

    public function test_anyone_can_review_a_shop_and_only_topline_admins_see_it(): void
    {
        $stores = $this->getJson('/api/review-stores')->assertOk()->json();
        $this->assertContains('mjengo', array_column($stores, 'slug'));
        $this->assertSame(['slug', 'name', 'county'], array_keys($stores[0])); // nothing private

        $this->postJson('/api/reviews', $this->review(['store' => 'no-such-shop']))->assertStatus(422)->assertJson(['field' => 'store']);
        $this->postJson('/api/reviews', $this->review(['rating' => 6]))->assertStatus(422)->assertJson(['field' => 'rating']);
        $this->postJson('/api/reviews', $this->review(['body' => 'ok']))->assertStatus(422)->assertJson(['field' => 'body']);
        $this->postJson('/api/reviews', $this->review(['visitedOn' => Carbon::now()->addDays(3)->format('Y-m-d')]))->assertStatus(422)->assertJson(['field' => 'visitedOn']);
        $this->postJson('/api/reviews', $this->review(['visitedOn' => '2026-02-30']))->assertStatus(422)->assertJson(['field' => 'visitedOn']);
        $this->travelTo(Carbon::parse('2026-11-04 08:00', 'Africa/Nairobi')); // 05:00 UTC: "today" must be Nairobi's
        $this->postJson('/api/reviews', $this->review(['visitedOn' => '2026-11-04', 'body' => 'Bought today, all good here.']))->assertStatus(201);
        $this->postJson('/api/reviews', $this->review(['visitedOn' => '2026-11-05']))->assertStatus(422);
        StoreReview::query()->delete();
        $this->travelBack();
        $this->postJson('/api/reviews', $this->review(['phone' => '12']))->assertStatus(422)->assertJson(['field' => 'phone']);
        $this->postJson('/api/reviews', $this->review(['phone' => '0722 000 111', 'status' => 'done']))->assertStatus(201)->assertExactJson(['received' => true]);
        $this->postJson('/api/reviews', $this->review(['store' => 'mwamba', 'rating' => 1, 'body' => 'Was charged for 10 lengths, got 8.']))->assertStatus(201);

        $this->getJson('/api/admin/reviews', $this->owner()['headers'])->assertStatus(403); // shops never see reviews
        $admin = $this->admin();
        $list = $this->getJson('/api/admin/reviews', $admin['headers'])->assertOk()->json();
        $this->assertSame(2, $list['newCount']);
        $mine = collect($list['reviews'])->firstWhere('store.slug', 'mjengo');
        $this->assertSame(['new', 4, '0722 000 111', 'Otieno'], [$mine['status'], $mine['rating'], $mine['phone'], $mine['name']]); // extra "status" ignored
        $this->assertSame(1.0, (float) collect($list['stores'])->firstWhere('name', 'Mwamba Hardware')['average']);

        $mjengo = Store::where('slug', 'mjengo')->value('id');
        $this->assertCount(1, $this->getJson("/api/admin/reviews?storeId={$mjengo}", $admin['headers'])->json('reviews'));
        $this->patchJson("/api/admin/reviews/{$mine['id']}", ['status' => 'done'], $admin['headers'])->assertOk()->assertJson(['status' => 'done']);
        $this->patchJson("/api/admin/reviews/{$mine['id']}", ['status' => 'x'], $admin['headers'])->assertStatus(422);
        $this->assertSame(1, $this->getJson('/api/admin/badges', $admin['headers'])->json('newReviews'));
        $this->deleteJson("/api/admin/reviews/{$mine['id']}", [], $admin['headers'])->assertOk();
        $this->assertSame(1, StoreReview::count());
    }

    public function test_reviews_are_limited_per_device(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reviews', $this->review())->assertStatus(201);
        }
        $this->postJson('/api/reviews', $this->review())->assertStatus(429);
    }

    public function test_admin_edits_a_plan_and_deploys_keep_it(): void
    {
        $admin = $this->admin();
        $body = ['name' => 'Pro', 'priceMonthly' => 6000, 'priceYearly' => 60000, 'blurb' => 'For busy shops',
            'features' => ['Everything in Business', ' ', 'Priority WhatsApp support'], 'productLimit' => '', 'popular' => true];
        $this->putJson('/api/admin/plans/pro', $body, $this->owner()['headers'])->assertStatus(403);
        $this->putJson('/api/admin/plans/pro', [...$body, 'priceMonthly' => -1], $admin['headers'])->assertStatus(422)->assertJson(['field' => 'priceMonthly']);
        $this->putJson('/api/admin/plans/pro', [...$body, 'features' => 'x'], $admin['headers'])->assertStatus(422)->assertJson(['field' => 'features']);
        $this->putJson('/api/admin/plans/nope', $body, $admin['headers'])->assertStatus(404);
        $this->putJson('/api/admin/plans/pro', $body, $admin['headers'])->assertOk()
            ->assertJson(['priceMonthly' => 6000, 'features' => ['Everything in Business', 'Priority WhatsApp support'], 'popular' => true]);
        $this->putJson('/api/admin/plans/starter', ['name' => 'Starter', 'priceMonthly' => 1500, 'priceYearly' => 15000, 'productLimit' => 50], $admin['headers'])
            ->assertOk()->assertJson(['limits' => ['products' => 50, 'staff' => 2]]);

        $this->seed(\Database\Seeders\PlansSeeder::class); // what every deploy runs
        $plans = collect($this->getJson('/api/plans')->json())->keyBy('id');
        $this->assertSame([6000, true, false], [$plans['pro']['priceMonthly'], $plans['pro']['popular'], $plans['business']['popular']]);
    }

    public function test_every_landing_text_is_editable_and_checked(): void
    {
        $admin = $this->admin();
        $site = $this->getJson('/api/site')->json();
        foreach (['featuresTitle', 'feature3Points', 'suppliersTitle', 'step2Text', 'pricingTitle', 'faqs', 'closingTitle', 'reviewsText', 'footerText', 'supportLocation'] as $key) {
            $this->assertArrayHasKey($key, $site);
        }
        $this->assertCount(5, json_decode($site['faqs'], true));

        $faqs = [['q' => 'Is there a contract?', 'a' => 'No.'], ['q' => '', 'a' => 'dropped']];
        $saved = $this->putJson('/api/admin/site', ['faqs' => $faqs, 'feature1Title' => 'A store on every phone'], $admin['headers'])->assertOk()->json();
        $this->assertSame([['q' => 'Is there a contract?', 'a' => 'No.']], json_decode($saved['faqs'], true));
        $this->assertSame('A store on every phone', $this->getJson('/api/site')->json('feature1Title')); // the cache was refreshed

        $this->putJson('/api/admin/site', ['faqs' => 'not json'], $admin['headers'])->assertStatus(422)->assertJson(['field' => 'faqs']);
        $this->putJson('/api/admin/site', ['closingTitle' => str_repeat('x', 151), 'footerText' => 'not saved'], $admin['headers'])
            ->assertStatus(422)->assertJson(['field' => 'closingTitle']);
        $this->assertNotSame('not saved', $this->getJson('/api/site')->json('footerText')); // nothing saved when one field is wrong
    }
}
