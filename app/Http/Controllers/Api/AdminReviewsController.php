<?php

namespace App\Http\Controllers\Api;

use App\Models\StoreReview;
use App\Services\Reviews;
use App\Support\ApiError;
use Illuminate\Http\Request;

/** /api/admin/reviews...: reviews of shops, for Topline admins only. */
class AdminReviewsController extends ApiController
{
    private function find(string $reviewId): StoreReview
    {
        return StoreReview::with('store')->find($reviewId) ?? throw ApiError::notFound('That review doesn’t exist.');
    }

    /** ?storeId= narrows to one shop. Also returns each matched shop's count and average rating. */
    public function index(Request $request)
    {
        $query = StoreReview::with('store')->orderByDesc('created_at')->limit($this->limit($request, 300));
        if ($storeId = $request->query('storeId')) {
            $query->where('store_id', $storeId);
        }
        $stores = StoreReview::with('store:id,name')->whereNotNull('store_id')->selectRaw('store_id, COUNT(*) as n, AVG(rating) as avg')->groupBy('store_id')->get()
            ->map(fn ($row) => ['id' => $row->store_id, 'name' => $row->store?->name, 'count' => (int) $row->n, 'average' => round((float) $row->avg, 1)])
            ->sortBy('name')->values();

        return $this->json([
            'newCount' => Reviews::newCount(),
            'stores' => $stores,
            'reviews' => $query->get()->map(fn ($r) => Reviews::json($r))->values(),
        ]);
    }

    /** {status: new|done} */
    public function update(Request $request, string $reviewId)
    {
        return $this->json(Reviews::json(Reviews::setStatus($this->find($reviewId), (string) ($this->body($request)['status'] ?? ''))));
    }

    public function destroy(string $reviewId)
    {
        $this->find($reviewId)->delete();

        return $this->json(['deleted' => true]);
    }
}
