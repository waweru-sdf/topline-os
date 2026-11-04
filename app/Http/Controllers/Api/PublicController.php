<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Plan;
use App\Models\Store;
use App\Services\Domains;
use App\Services\Monitoring;
use App\Services\Reviews;
use App\Services\Site;
use App\Services\Stores;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Open endpoints: health, plans, link availability, site content, suppliers, reviews, browser error reports. */
class PublicController extends ApiController
{
    public function health()
    {
        DB::select('SELECT 1');

        return $this->json(['ok' => true, 'payments' => config('topline.payments_mode'), 'sms' => config('topline.sms_mode')]);
    }

    /** Keep-awake / uptime check that doesn't touch the database. */
    public function ping()
    {
        return $this->json(['ok' => true]);
    }

    public function plans()
    {
        return $this->json(Plan::where('active', true)->orderBy('sort_order')->get()->map(fn ($p) => Json::plan($p))->all());
    }

    public function checkSlug(Request $request)
    {
        return $this->json(Stores::checkSlug($request->query('slug', '')));
    }

    /** Main-site content: headline, pictures, contacts. ?view=landing counts a visit. */
    /** Listed suppliers for the landing page: profile, logo and how many shops they work with. No contacts or prices. */
    public function suppliers()
    {
        return $this->json(\App\Services\Suppliers::publicList());
    }

    public function site(Request $request)
    {
        if ($request->query('view') === 'landing') {
            Monitoring::countView('landing');
        }

        return $this->json(Site::all());
    }

    /** The website reports crashes here so they show up in System health. */
    public function browserError(Request $request)
    {
        $data = json_decode($request->getContent() ?: '{}', true) ?: [];
        Monitoring::recordIncident('browser', mb_substr((string) ($data['message'] ?? 'Unknown browser error'), 0, 200),
            mb_substr((string) ($data['stack'] ?? ''), 0, 3000), mb_substr((string) ($data['path'] ?? ''), 0, 200));

        return $this->json(['ok' => true]);
    }

    /** For an HTTPS server that issues certificates per shop subdomain (VPS setups). */
    public function tlsCheck(Request $request)
    {
        $host = rtrim(strtolower(trim((string) $request->query('domain', ''))), '.');
        $root = config('topline.root_domain');
        if ($host === $root || $host === "www.{$root}") {
            return response('', 200);
        }
        $slug = str_ends_with($host, ".{$root}") ? substr($host, 0, -strlen(".{$root}")) : null;

        return response('', $slug && ! str_contains($slug, '.') && Store::where('slug', $slug)->exists() ? 200 : 404);
    }

    /** Someone reviews a shop. Only Topline admins see it. At most 5 per device per day. */
    public function createReview(Request $request)
    {
        $key = 'review:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApiError('You’ve sent several reviews today. Thank you! Try again tomorrow.', 429);
        }
        Reviews::create($this->body($request));
        RateLimiter::hit($key, 86400);

        return $this->json(['received' => true], 201);
    }

    /** ?host= → {slug} when that host is a shop's connected own domain; 404 otherwise. */
    public function resolveDomain(Request $request)
    {
        $slug = Domains::resolve((string) $request->query('host', ''));

        return $slug ? $this->json(['slug' => $slug]) : throw ApiError::notFound('No shop uses this domain.');
    }
}
