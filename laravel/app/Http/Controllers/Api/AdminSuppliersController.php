<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\SupplierJson;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Access;
use App\Services\Passwords;
use App\Services\Suppliers;
use App\Support\ApiError;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Http\Request;

/** /api/admin/suppliers...: Topline admins add suppliers, review their profiles and products, and see orders. */
class AdminSuppliersController extends ApiController
{
    private function find(string $supplierId): Supplier
    {
        return Supplier::find($supplierId) ?? throw ApiError::notFound('That supplier doesn’t exist.');
    }

    private function detail(Supplier $s): array
    {
        $orders = SupplierOrder::with('store')->where('supplier_id', $s->id)->orderByDesc('created_at')->limit(100)->get();

        return SupplierJson::own($s, Suppliers::connectedCounts()[$s->id] ?? 0, [
            'login' => $s->user ? ['name' => $s->user->name, 'phone' => Phone::display($s->user->phone), 'email' => $s->user->email,
                'lastLoginAt' => Text::iso($s->user->last_login_at), 'mustChangePassword' => (bool) $s->user->must_change_password] : null,
            'products' => $s->products()->get()->map(fn ($p) => SupplierJson::productOwn($p))->values(),
            'orders' => $orders->map(fn ($o) => SupplierJson::order($o, 'admin'))->values(),
            'paymentOptionChoices' => SupplierJson::paymentOptions(array_keys(Suppliers::PAYMENT_OPTIONS)),
        ]);
    }

    public function index()
    {
        $counts = Suppliers::connectedCounts();
        $products = SupplierProduct::selectRaw("supplier_id, COUNT(*) as total, SUM(CASE WHEN status = 'pending' OR draft IS NOT NULL THEN 1 ELSE 0 END) as waiting")
            ->groupBy('supplier_id')->get()->keyBy('supplier_id');
        $orders = SupplierOrder::selectRaw('supplier_id, COUNT(*) as n')->groupBy('supplier_id')->pluck('n', 'supplier_id');

        return $this->json([
            'pendingReviews' => Suppliers::pendingReviews(),
            'suppliers' => Supplier::orderBy('name')->get()->map(fn (Supplier $s) => [
                'id' => $s->id, 'slug' => $s->slug, 'name' => $s->name, 'logo' => $s->logo ?: null, 'status' => $s->status,
                'location' => $s->location, 'reviewStatus' => $s->review_status ?: null,
                'products' => (int) ($products[$s->id]->total ?? 0), 'productsWaiting' => (int) ($products[$s->id]->waiting ?? 0),
                'orders' => (int) ($orders[$s->id] ?? 0), 'connectedShops' => $counts[$s->id] ?? 0,
            ])->values(),
        ]);
    }

    public function create(Request $request)
    {
        [$supplier, $temp] = Suppliers::create($this->body($request), Access::currentUser());

        return $this->json(['supplier' => $this->detail($supplier), 'temporaryPassword' => $temp, 'login' => Phone::display($supplier->user->phone)], 201);
    }

    public function show(string $supplierId)
    {
        return $this->json($this->detail($this->find($supplierId)));
    }

    public function update(Request $request, string $supplierId)
    {
        $s = $this->find($supplierId);
        $data = $this->body($request);
        if (array_key_exists('listed', $data)) {
            Suppliers::setListed($s, (bool) $data['listed'], Access::currentUser());
        }
        if (array_key_exists('profile', $data)) {
            Suppliers::adminUpdateProfile($s, is_array($data['profile']) ? $data['profile'] : [], Access::currentUser());
        }

        return $this->json($this->detail($s->refresh()));
    }

    public function review(Request $request, string $supplierId)
    {
        $data = $this->body($request);
        $s = Suppliers::reviewProfile($this->find($supplierId), (string) ($data['action'] ?? ''), (string) ($data['note'] ?? ''), Access::currentUser());

        return $this->json($this->detail($s));
    }

    public function reviewProduct(Request $request, string $productId)
    {
        $data = $this->body($request);
        $p = SupplierProduct::find($productId) ?? throw ApiError::notFound('That product doesn’t exist.');
        $p = Suppliers::reviewProduct($p, (string) ($data['action'] ?? ''), (string) ($data['note'] ?? ''), Access::currentUser());

        return $this->json(SupplierJson::productOwn($p));
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
