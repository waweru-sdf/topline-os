<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreReview;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Carbon;

/**
 * Reviews of hardware shops from the public Reviews page. Only Topline admins see them,
 * to keep an eye on how shops on Topline treat their customers.
 */
class Reviews
{
    public static function create(array $data): StoreReview
    {
        $name = Input::text($data, 'storeName', true, 120, 'Enter the shop’s name.');
        if (mb_strlen($name) < 2) {
            throw ApiError::invalid('Enter the shop’s name.', 'storeName');
        }
        $store = self::matchStore((string) ($data['store'] ?? ''), $name);
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
            'store_id' => $store?->id,
            'store_name' => $name,
            'rating' => $rating,
            'body' => $body,
            'visited_on' => self::visitDate($data['visitedOn'] ?? null),
            'reviewer_name' => Input::text($data, 'name', true, 120, 'Enter your name.'),
            'reviewer_phone' => $phone,
            'status' => StoreReview::NEW,
        ]);
    }

    /**
     * Which Topline shop the review is about, if we can tell: the store's own link ("Review this shop"
     * sends its slug), or a typed name that matches exactly one opened shop. Otherwise none; admins see the typed name.
     */
    private static function matchStore(string $slug, string $name): ?Store
    {
        $opened = fn () => Store::where('status', '!=', Store::DRAFT);
        if ($slug !== '' && ($store = $opened()->where('slug', $slug)->first())) {
            return $store;
        }
        $matches = $opened()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
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
            'storeName' => $r->store_name,  // as the reviewer typed it
            'store' => $r->store ? ['id' => $r->store->id, 'name' => $r->store->name, 'slug' => $r->store->slug] : null, // null: not matched to a shop
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
