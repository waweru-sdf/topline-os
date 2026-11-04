<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreReview;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Reviews of hardware shops from the public Reviews page. Only Topline admins see them,
 * to keep an eye on how shops on Topline treat their customers.
 */
class Reviews
{
    private const STORES_CACHE_KEY = 'reviews:stores';

    /** Shops a review can be about (every shop that has opened). Cached: it's on a public page. */
    public static function reviewableStores(): array
    {
        return Cache::remember(self::STORES_CACHE_KEY, 600, fn () => Store::where('status', '!=', Store::DRAFT)->orderBy('name')
            ->get(['slug', 'name', 'county'])->map(fn (Store $s) => ['slug' => $s->slug, 'name' => $s->name, 'county' => $s->county ?? ''])->all());
    }

    public static function create(array $data): StoreReview
    {
        $store = Store::where('slug', (string) ($data['store'] ?? ''))->where('status', '!=', Store::DRAFT)->first()
            ?? throw ApiError::invalid('Choose the shop from the list.', 'store');
        $rating = Input::integer($data, 'rating', true, 1, 'Choose from 1 to 5 stars.');
        if ($rating > 5) {
            throw ApiError::invalid('Choose from 1 to 5 stars.', 'rating');
        }
        $body = Input::text($data, 'body', true, 2000, 'Write your review.');
        if (mb_strlen($body) < 10) {
            throw ApiError::invalid('Tell us a little more (at least 10 characters).', 'body');
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        $phone = $phone === '' ? '' : (Phone::normalize($phone) ?? throw ApiError::invalid('Enter a mobile number like 0712 345 678, or leave it empty.', 'phone'));

        return StoreReview::create([
            'store_id' => $store->id,
            'rating' => $rating,
            'body' => $body,
            'visited_on' => self::visitDate($data['visitedOn'] ?? null),
            'reviewer_name' => Input::text($data, 'name', true, 120, 'Enter your name.'),
            'reviewer_phone' => $phone,
            'status' => StoreReview::NEW,
        ]);
    }

    /** "When did you deal with the shop?": a real date, not in the future, within the last 2 years. */
    private static function visitDate(mixed $value): string
    {
        $tz = config('topline.timezone');
        $today = Carbon::now($tz)->startOfDay();
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::createFromFormat('!Y-m-d', $value, $tz) : false;
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ApiError::invalid('Choose the date you dealt with the shop.', 'visitedOn');
        }
        if ($date->gt($today) || $date->lt($today->copy()->subYears(2))) {
            throw ApiError::invalid('Choose a date in the last two years, not in the future.', 'visitedOn');
        }

        return $value;
    }

    public static function setStatus(StoreReview $review, string $status): StoreReview
    {
        if (! in_array($status, [StoreReview::NEW, StoreReview::DONE], true)) {
            throw ApiError::invalid('Choose new or done.', 'status');
        }
        $review->update(['status' => $status]);

        return $review;
    }

    public static function newCount(): int
    {
        return StoreReview::where('status', StoreReview::NEW)->count();
    }

    public static function json(StoreReview $r): array
    {
        return [
            'id' => $r->id,
            'store' => $r->store ? ['id' => $r->store->id, 'name' => $r->store->name, 'slug' => $r->store->slug] : null,
            'rating' => $r->rating,
            'body' => $r->body,
            'visitedOn' => $r->visited_on->format('Y-m-d'),
            'name' => $r->reviewer_name,
            'phone' => $r->reviewer_phone ? Phone::display($r->reviewer_phone) : null,
            'status' => $r->status,
            'createdAt' => Text::iso($r->created_at),
        ];
    }
}
