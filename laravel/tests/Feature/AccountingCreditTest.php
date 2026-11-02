<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Accounting for shops and for Topline, and stock credit requests. */
class AccountingCreditTest extends TestCase
{
    private function month(): string
    {
        return now(config('topline.timezone'))->format('Y-m');
    }

    private function monthStartUtc()
    {
        return now(config('topline.timezone'))->startOfMonth()->utc();
    }

    public function test_shop_profit_and_loss_matches_the_sales_and_expenses(): void
    {
        $o = $this->owner();
        $base = "/api/vendor/stores/{$o['storeId']}";
        $from = $this->monthStartUtc();
        $counted = Sale::where('store_id', $o['storeId'])->whereNotIn('status', ['awaiting_payment', 'awaiting_confirmation', 'cancelled'])
            ->where('created_at', '>=', $from);
        $revenue = (int) (clone $counted)->sum('total');
        $cogs = (int) DB::table('sale_items')->whereIn('sale_number', (clone $counted)->pluck('number'))->sum(DB::raw('cost * qty'));
        $expenses = (int) Expense::where('store_id', $o['storeId'])->where('spent_on', '>=', $this->month().'-01')->sum('amount');

        $d = $this->getJson("{$base}/accounting", $o['headers'])->assertOk()->json();
        $this->assertSame([$this->month(), $revenue, $cogs, $expenses], [$d['month'], $d['revenue'], $d['costOfGoods'], $d['expenses']]);
        $this->assertSame($revenue - $cogs - $expenses, $d['netProfit']);
        $this->assertSame($revenue, array_sum(array_column($d['byMethod'], 'total')));
        $this->assertCount(6, $d['history']);
        $this->assertSame($d['netProfit'], end($d['history'])['netProfit']);
        $this->getJson("{$base}/accounting?month=2026-13", $o['headers'])->assertStatus(422)->assertJson(['field' => 'month']);
    }

    public function test_expenses_are_checked_and_kept_per_shop(): void
    {
        $o = $this->owner();
        $base = "/api/vendor/stores/{$o['storeId']}";
        $today = now(config('topline.timezone'))->format('Y-m-d');
        $tomorrow = now(config('topline.timezone'))->addDays(2)->format('Y-m-d');
        $this->postJson("{$base}/expenses", ['spentOn' => $tomorrow, 'category' => 'rent', 'amount' => 100], $o['headers'])->assertStatus(422)->assertJson(['field' => 'spentOn']);
        $this->postJson("{$base}/expenses", ['spentOn' => $today, 'category' => 'bribes', 'amount' => 100], $o['headers'])->assertStatus(422)->assertJson(['field' => 'category']);
        $this->postJson("{$base}/expenses", ['spentOn' => '2026-02-30', 'category' => 'rent', 'amount' => 100], $o['headers'])->assertStatus(422);
        $before = $this->getJson("{$base}/accounting", $o['headers'])->json('expenses');
        $e = $this->postJson("{$base}/expenses", ['spentOn' => $today, 'category' => 'transport', 'amount' => 2500, 'method' => 'mpesa',
            'description' => '=HYPERLINK("http://evil")'], $o['headers'])->assertStatus(201)->json();
        $this->assertSame($before + 2500, $this->getJson("{$base}/accounting", $o['headers'])->json('expenses'));

        $csv = $this->get("{$base}/accounting/export?type=expenses", $o['headers'])->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $csv->getContent()); // never runs as a formula
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Date\"", $csv->getContent());
        $this->assertStringContainsString('"Sale"', $this->get("{$base}/accounting/export?type=sales", $o['headers'])->getContent());

        $other = $this->store('coast-steel');
        $this->deleteJson("/api/vendor/stores/{$other->id}/expenses/{$e['id']}", [], $o['headers'])->assertStatus(404);
        $admin = $this->admin();
        $this->deleteJson("/api/vendor/stores/{$other->id}/expenses/{$e['id']}", [], $admin['headers'])->assertStatus(404); // wrong shop
        $this->deleteJson("{$base}/expenses/{$e['id']}", [], $o['headers'])->assertStatus(204);

        $cashier = $this->cashier();
        $this->getJson("/api/vendor/stores/{$cashier['storeId']}/accounting", $cashier['headers'])->assertStatus(403);
        $this->getJson("/api/vendor/stores/{$cashier['storeId']}/expenses", $cashier['headers'])->assertStatus(403);
    }

    public function test_topline_accounting(): void
    {
        $admin = $this->admin();
        $planIncome = (int) Payment::where('kind', 'subscription')->where('status', 'paid')->where('paid_at', '>=', $this->monthStartUtc())->sum('amount');
        $d = $this->getJson('/api/admin/accounting', $admin['headers'])->assertOk()->json();
        $this->assertSame($planIncome, $d['planIncome']);
        $this->assertSame($d['planIncome'] + $d['commissionIncome'], $d['income']);
        $this->assertGreaterThan(0, $d['mrr']);
        $this->assertCount(6, $d['history']);
        $this->assertSame(['Kilimani Paints & Tools'], array_column($d['pastDue'], 'name'));
        $csv = $this->get('/api/admin/accounting/export?type=payouts', $admin['headers'])->assertOk()->getContent();
        $this->assertStringContainsString('"Commission"', $csv);
        $this->getJson('/api/admin/accounting', $this->owner()['headers'])->assertStatus(403);
    }

    public function test_owner_requests_stock_credit_and_topline_follows_up(): void
    {
        $o = $this->owner();
        $url = "/api/vendor/stores/{$o['storeId']}/credit-requests";
        $form = ['amount' => 250000, 'purpose' => 'stock', 'periodMonths' => 3, 'monthlySales' => 400000,
            'contactName' => 'Peter Kamau', 'contactPhone' => '0712 345 678', 'notes' => 'Cement before the rains', 'consent' => true];
        $this->postJson($url, [...$form, 'consent' => false], $o['headers'])->assertStatus(422)->assertJson(['field' => 'consent']);
        $this->postJson($url, [...$form, 'amount' => 100], $o['headers'])->assertStatus(422)->assertJson(['field' => 'amount']);
        $this->postJson($url, [...$form, 'periodMonths' => 7], $o['headers'])->assertStatus(422)->assertJson(['field' => 'periodMonths']);
        $this->postJson($url, [...$form, 'supplierOrderId' => 'so_notmine'], $o['headers'])->assertStatus(422)->assertJson(['field' => 'supplierOrderId']);
        $made = $this->postJson($url, $form, $o['headers'])->assertStatus(201)->assertJson(['status' => 'new', 'amount' => 250000])->json();
        $this->assertArrayNotHasKey('adminNote', $made);
        $this->postJson($url, $form, $o['headers'])->assertStatus(409);

        $admin = $this->admin();
        $list = $this->getJson('/api/admin/credit-requests', $admin['headers'])->assertOk()->json();
        $this->assertSame(1, $list['open']);
        $this->assertSame('Mjengo Hardware', $list['requests'][0]['store']['name']);
        $this->patchJson("/api/admin/credit-requests/{$made['id']}", ['status' => 'paid'], $admin['headers'])->assertStatus(422);
        $this->patchJson("/api/admin/credit-requests/{$made['id']}", ['status' => 'declined', 'adminNote' => 'Partner needs 6 months of sales'], $admin['headers'])
            ->assertOk()->assertJson(['status' => 'declined']);
        $this->assertSame(0, $this->getJson('/api/admin/credit-requests', $admin['headers'])->json('open'));
        $this->postJson($url, $form, $o['headers'])->assertStatus(201); // can ask again once the last one is closed

        $cashier = $this->cashier();
        $this->postJson("/api/vendor/stores/{$cashier['storeId']}/credit-requests", $form, $cashier['headers'])->assertStatus(403);
        $this->getJson('/api/admin/credit-requests', $o['headers'])->assertStatus(403);
    }
}
