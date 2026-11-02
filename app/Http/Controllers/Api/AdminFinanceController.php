<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\SupplierJson;
use App\Models\CreditRequest;
use App\Services\Access;
use App\Services\Accounting;
use App\Services\CreditRequests;
use App\Support\ApiError;
use Illuminate\Http\Request;

/** /api/admin/accounting and /api/admin/credit-requests. */
class AdminFinanceController extends ApiController
{
    public function accounting(Request $request)
    {
        return $this->json(Accounting::platformSummary($request->query('month')));
    }

    public function export(Request $request)
    {
        [$name, $csv] = Accounting::platformCsv((string) $request->query('type', 'payments'), $request->query('month'));

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Cache-Control' => 'no-store']);
    }

    public function creditRequests(Request $request)
    {
        $status = $request->query('status');
        $rows = CreditRequest::with('store')->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit($this->limit($request, 200))->get();

        return $this->json([
            'open' => CreditRequest::whereIn('status', CreditRequest::OPEN)->count(),
            'requests' => $rows->map(fn ($c) => SupplierJson::creditRequest($c, true))->values(),
        ]);
    }

    public function updateCreditRequest(Request $request, string $requestId)
    {
        $c = CreditRequest::find($requestId) ?? throw ApiError::notFound('That request doesn’t exist.');

        return $this->json(SupplierJson::creditRequest(CreditRequests::update($c, $this->body($request), Access::currentUser())->load('store'), true));
    }
}
