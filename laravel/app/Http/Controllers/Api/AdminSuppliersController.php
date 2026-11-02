<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\SupplierJson;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\Access;
use App\Services\Passwords;
use App\Services\Suppliers;
use App\Support\ApiError;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Http\Request;

/** /api/admin/suppliers...: Topline admins approve or reject supplier sign-ups, hide suppliers, and see orders. */
class AdminSuppliersController extends ApiController
{
    private function find(string $supplierId): Supplier
    {
        return Supplier::find($supplierId) ?? throw ApiError::notFound('That supplier doesn’t exist.');
    }

    private static function login(Supplier $s): ?array
    {
        return $s->user ? ['name' => $s->user->name, 'phone' => Phone::display($s->user->phone), 'email' => $s->user->email,
            'lastLoginAt' => Text::iso($s->user->last_login_at)] : null;
    }

    private function detail(Supplier $s): array
    {
        $orders = SupplierOrder::with('store')->where('supplier_id', $s->id)->orderByDesc('created_at')->limit(100)->get();

        return SupplierJson::own($s, Suppliers::connectedCounts()[$s->id] ?? 0, [
            'login' => self::login($s),
            'products' => $s->products()->get()->map(fn ($p) => SupplierJson::productForShop($p))->values(),
            'orders' => $orders->map(fn ($o) => SupplierJson::order($o, 'admin'))->values(),
        ]);
    }

    public function index()
    {
        $counts = Suppliers::connectedCounts();
        $products = SupplierProduct::selectRaw('supplier_id, COUNT(*) as n')->groupBy('supplier_id')->pluck('n', 'supplier_id');
        $orders = SupplierOrder::selectRaw('supplier_id, COUNT(*) as n')->groupBy('supplier_id')->pluck('n', 'supplier_id');

        return $this->json([
            'pendingSignUps' => Suppliers::pendingSignUps(),
            'suppliers' => Supplier::with('user')->orderByDesc('created_at')->get()->map(fn (Supplier $s) => [
                'id' => $s->id, 'slug' => $s->slug, 'name' => $s->name, 'logo' => $s->logo ?: null, 'status' => $s->status,
                'description' => $s->description, 'categories' => $s->categories, 'location' => $s->location, 'login' => self::login($s),
                'products' => (int) ($products[$s->id] ?? 0), 'orders' => (int) ($orders[$s->id] ?? 0),
                'connectedShops' => $counts[$s->id] ?? 0, 'createdAt' => Text::iso($s->created_at),
            ])->values(),
        ]);
    }

    public function show(string $supplierId)
    {
        return $this->json($this->detail($this->find($supplierId)));
    }

    /** {listed: bool}: hide an approved supplier from the home page and shops, or list them again. */
    public function update(Request $request, string $supplierId)
    {
        $s = Suppliers::setListed($this->find($supplierId), (bool) ($this->body($request)['listed'] ?? true), Access::currentUser());

        return $this->json($this->detail($s));
    }

    /** {action: approve|reject, note}: decide on a sign-up. */
    public function review(Request $request, string $supplierId)
    {
        $data = $this->body($request);
        $s = Suppliers::review($this->find($supplierId), (string) ($data['action'] ?? ''), (string) ($data['note'] ?? ''), Access::currentUser());

        return $this->json($this->detail($s));
    }

    public function resetPassword(string $supplierId)
    {
        $s = $this->find($supplierId);
        $user = $s->user ?? throw ApiError::notFound('This supplier has no login.');
        if (! $user->isSupplier()) {
            throw ApiError::forbidden('This login isn’t a supplier account.');
        }
        $temp = Passwords::adminReset($user, Access::currentUser());

        return $this->json(['temporaryPassword' => $temp, 'name' => $user->name, 'login' => Phone::display($user->phone)]);
    }

    public function orders(Request $request)
    {
        $orders = SupplierOrder::with(['store', 'supplier'])->orderByDesc('created_at')->limit($this->limit($request, 200))->get();

        return $this->json($orders->map(fn ($o) => SupplierJson::order($o, 'admin'))->values());
    }
}
