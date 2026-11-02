<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo supplier partners (made-up companies, not real brands), their products, a few orders
 * from the demo shops, one sign-up waiting for approval, and Mjengo's expenses.
 * Supplier logins use the demo password "topline123".
 */
class DemoSuppliersSeeder extends Seeder
{
    private const SUPPLIERS = [
        [
            'login' => ['Wanjiku Mwangi', '254700111222', 'sales@rvcement.example.com'],
            'slug' => 'rift-valley-cement', 'name' => 'Rift Valley Cement Co.', 'status' => 'approved',
            'description' => 'Cement maker in the Rift Valley supplying 32.5 and 42.5 grade cement by the lorry-load to hardware shops across Kenya.',
            'categories' => 'Cement, Ready-mix', 'location' => 'Nakuru', 'min_order' => '100 bags', 'delivery_info' => 'Own lorries; delivery in 2–4 days within 300 km.',
            'payment_options' => ['mpesa', 'bank', 'deposit', 'supplier_credit', 'topline_credit'],
            'payment_instructions' => 'We confirm your order by phone, then send an invoice. Pay to our Paybill or bank account on the invoice.',
            'products' => [
                ['Cement 32.5N (50 kg)', 'bag', 690, 100, 'General building: blocks, plaster, foundations.'],
                ['Cement 42.5R (50 kg)', 'bag', 760, 100, 'High early strength for columns, slabs and beams.'],
                ['Ready-mix C25 concrete', 'm³', null, 6, 'Delivered by mixer truck. Price depends on distance.'],
            ],
        ],
        [
            'login' => ['Hassan Omar', '254700111333', 'orders@pwanisteel.example.com'],
            'slug' => 'pwani-steel-mills', 'name' => 'Pwani Steel Mills', 'status' => 'approved',
            'description' => 'Steel bars, mesh and roofing sheets rolled in Mombasa. Stock for shops in the coast and up-country.',
            'categories' => 'Steel, Roofing', 'location' => 'Mombasa', 'min_order' => 'KSh 50,000 per order', 'delivery_info' => 'Delivery to any town on the Mombasa–Nairobi road.',
            'payment_options' => ['mpesa', 'bank', 'on_delivery'],
            'payment_instructions' => 'Pay on delivery for orders under KSh 200,000. Larger orders: bank transfer before loading.',
            'products' => [
                ['Deformed bar Y12 (12 m)', 'length', 1380, 50, 'High-tensile, KEBS marked.'],
                ['Deformed bar Y16 (12 m)', 'length', 2350, 30, 'For columns and beams.'],
                ['BRC mesh A142', 'sheet', 4900, 10, '2.4 m × 4.8 m.'],
                ['Box-profile roofing sheet 30G', 'm', 610, 100, 'Pre-painted, several colours.'],
            ],
        ],
        [
            'login' => ['Li Wei', '254700111444', 'kenya@buildpro.example.com'],
            'slug' => 'buildpro-trading', 'name' => 'BuildPro Trading (Guangzhou)', 'status' => 'pending',
            'description' => 'Factory-direct tiles, sanitary ware and power tools from China, shipped to Mombasa in containers or shared loads.',
            'categories' => 'Tiles, Sanitary ware, Tools', 'location' => 'Guangzhou, China', 'min_order' => 'Shared container (LCL)', 'delivery_info' => '35–45 days to Mombasa; we clear customs.',
            'payment_options' => ['bank', 'deposit'],
            'payment_instructions' => '30% deposit by bank transfer, balance before shipping.',
            'products' => [['Porcelain floor tile 60×60', 'm²', 950, 200, 'Matt finish, grey and beige.']],
        ],
    ];

    public function run(): void
    {
        if (Supplier::exists()) {
            return;
        }
        $hash = Hash::make('topline123');
        $now = now();
        foreach (self::SUPPLIERS as $spec) {
            [$name, $phone, $email] = $spec['login'];
            $user = User::create(['name' => $name, 'phone' => $phone, 'email' => $email, 'role' => User::SUPPLIER, 'password' => $hash]);
            $approved = $spec['status'] === 'approved';
            $supplier = Supplier::create([
                'user_id' => $user->id, 'slug' => $spec['slug'], 'name' => $spec['name'], 'description' => $spec['description'],
                'categories' => $spec['categories'], 'location' => $spec['location'], 'phone' => $phone, 'email' => $email,
                'min_order' => $spec['min_order'], 'delivery_info' => $spec['delivery_info'], 'payment_options' => $spec['payment_options'],
                'payment_instructions' => $spec['payment_instructions'], 'status' => $spec['status'],
                'approved_at' => $approved ? $now->copy()->subDays(20) : null,
                'created_at' => $now->copy()->subDays(25),
            ]);
            foreach ($spec['products'] as [$pName, $unit, $price, $min, $desc]) {
                SupplierProduct::create(['supplier_id' => $supplier->id, 'name' => $pName, 'unit' => $unit, 'price' => $price, 'min_qty' => $min,
                    'description' => $desc, 'in_stock' => true]);
            }
        }

        $cement = Supplier::where('slug', 'rift-valley-cement')->first();
        $c325 = SupplierProduct::where('supplier_id', $cement->id)->where('name', 'like', 'Cement 32.5N%')->first();
        foreach (['mjengo' => 'completed', 'mwamba' => 'contacted', 'baraka-builders' => 'new'] as $slug => $status) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                continue;
            }
            SupplierOrder::create([
                'reference' => 'PO-'.strtoupper(substr(md5($slug), 0, 6)), 'supplier_id' => $cement->id, 'store_id' => $store->id,
                'contact_name' => $store->owner()?->name ?? $store->name, 'contact_phone' => $store->owner()?->phone ?? '254712345678',
                'items' => [['productId' => $c325->id, 'name' => $c325->name, 'unit' => 'bag', 'qty' => 200, 'price' => 690]],
                'estimated_total' => 138000, 'delivery_location' => "{$store->town}, {$store->county}", 'payment_option' => 'mpesa',
                'notes' => '', 'status' => $status, 'created_at' => $now->copy()->subDays(10), 'updated_at' => $now->copy()->subDays(8),
            ]);
        }

        $mjengo = Store::where('slug', 'mjengo')->first();
        if ($mjengo) {
            $month = $now->copy()->setTimezone(config('topline.timezone'));
            foreach ([['rent', 45000, 'Shop rent', 1], ['salaries', 60000, 'Two shop assistants', 2], ['transport', 8500, 'Pick-up fuel', 3],
                ['utilities', 4200, 'KPLC tokens and water', 4], ['transport', 3500, 'Lorry hire to Isinya', 6]] as [$cat, $amount, $desc, $daysAgo]) {
                $day = $month->copy()->subDays($daysAgo);
                Expense::create(['store_id' => $mjengo->id, 'spent_on' => $day->format('Y-m-d'), 'category' => $cat, 'amount' => $amount,
                    'method' => $cat === 'salaries' ? 'mpesa' : 'cash', 'description' => $desc]);
            }
        }
    }
}
