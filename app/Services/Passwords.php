<?php

namespace App\Services;

use App\Models\User;
use App\Support\ApiError;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;

/**
 * Logging in with phone or email + password; changing and resetting passwords.
 *  - 5 wrong passwords in a row lock the account for 15 minutes.
 *  - After a Topline admin reset, the user must choose a new password before anything else.
 *  - Changing a password signs out every other device (all other tokens are deleted).
 */
class Passwords
{
    public const MIN_LENGTH = 8;
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;
    public const WRONG = 'Wrong phone/email or password.';

    public static function normalizeEmail(mixed $raw): ?string
    {
        $email = strtolower(trim((string) ($raw ?? '')));
        if ($email === '') {
            return null;
        }
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || ! str_contains($domain, '.') || str_contains($email, ' ') || strlen($email) > 160) {
            throw ApiError::invalid('Enter a valid email address, or leave it empty.', 'email');
        }

        return $email;
    }

    /** The most-guessed passwords (lower case, after removing spaces): rejected outright. */
    private const COMMON = ['password', 'password1', 'password123', 'passw0rd', 'qwerty123', 'qwertyuiop', 'abc12345', 'abcd1234',
        'asdf1234', 'admin123', 'admin1234', 'welcome1', 'welcome123', 'iloveyou1', 'kenya254', 'kenya123', 'nairobi1', 'nairobi123',
        'mpesa123', 'topline1', 'topline12', 'toplinehub', 'letmein1', 'changeme1', 'football1', 'monkey123', 'jesus123', 'god12345',
        'a1b2c3d4', '1q2w3e4r', '1qaz2wsx', 'zaq12wsx', 'q1w2e3r4', 'hardware1', 'mabati123', 'cement123'];

    /**
     * Rules for every password a person chooses (the screens show the same checklist):
     * at least 8 characters, letters AND numbers, not a common password, not the phone number,
     * and not one character or a simple run repeated.
     */
    public static function checkNew(mixed $password, string $field = 'password', ?string $phone = null): string
    {
        $password = (string) ($password ?? '');
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw ApiError::invalid('Use at least '.self::MIN_LENGTH.' characters.', $field);
        }
        if (mb_strlen($password) > 128) {
            throw ApiError::invalid('That password is too long.', $field);
        }
        if (! preg_match('/\pL/u', $password) || ! preg_match('/\d/', $password)) {
            throw ApiError::invalid('Use both letters and numbers, e.g. duka-yangu-24.', $field);
        }
        $plain = strtolower(preg_replace('/\s+/', '', $password));
        $digits = preg_replace('/\D/', '', $password);
        $local = $phone ? substr(preg_replace('/\D/', '', $phone), -9) : '';
        if (in_array($plain, self::COMMON, true) || count(array_unique(mb_str_split($plain))) < 4
            || ($local !== '' && str_contains($digits, $local))) {
            throw ApiError::invalid('That password is too easy to guess. Mix words and numbers that aren’t your phone number.', $field);
        }

        return $password;
    }

    public static function set(User $user, string $password): void
    {
        $user->password = Hash::make($password);
        $user->failed_logins = 0;
        $user->locked_until = null;
    }

    public static function findUser(mixed $login): ?User
    {
        $login = trim((string) ($login ?? ''));
        if (str_contains($login, '@')) {
            return User::where('email', strtolower($login))->first();
        }
        $phone = Phone::normalize($login);

        return $phone ? User::where('phone', $phone)->first() : null;
    }

    public static function logIn(mixed $login, mixed $password): User
    {
        if (trim((string) ($login ?? '')) === '') {
            throw ApiError::invalid('Enter your phone number or email.', 'login');
        }
        if ((string) ($password ?? '') === '') {
            throw ApiError::invalid('Enter your password.', 'password');
        }
        $user = self::findUser($login);
        $now = now();
        if ($user && $user->locked_until && $user->locked_until->gt($now)) {
            $minutes = max(1, intdiv((int) $now->diffInSeconds($user->locked_until, true), 60) + 1);
            throw new ApiError("Too many wrong attempts. Try again in {$minutes} minute".($minutes > 1 ? 's' : '').'.', 429, 'password');
        }
        if (! $user || ! $user->password || ! Hash::check((string) $password, $user->password)) {
            if ($user) {
                $user->failed_logins += 1;
                if ($user->failed_logins >= self::MAX_FAILURES) {
                    $user->failed_logins = 0;
                    $user->locked_until = $now->copy()->addMinutes(self::LOCK_MINUTES);
                }
                $user->save();
            }
            throw new ApiError(self::WRONG, 401, 'password');
        }
        if (! $user->is_active) {
            throw ApiError::forbidden('This account is switched off. Contact Topline.');
        }
        if ($user->isSupplier()) {
            Suppliers::checkCanLogIn($user);
        }
        $user->failed_logins = 0;
        $user->locked_until = null;
        $user->last_login_at = $now;
        $user->save();
        Activity::record('login', "{$user->name} logged in", $user->primaryMembership()?->store_id, $user);

        return $user;
    }

    public static function change(User $user, mixed $current, mixed $new): User
    {
        if (! $user->must_change_password && ! Hash::check((string) ($current ?? ''), (string) $user->password)) {
            throw ApiError::invalid('Your current password is wrong.', 'currentPassword');
        }
        $new = self::checkNew($new, 'newPassword', $user->phone);
        if ($user->password && Hash::check($new, $user->password)) {
            throw ApiError::invalid('Choose a password different from the old one.', 'newPassword');
        }
        self::set($user, $new);
        $user->must_change_password = false;
        $user->save();
        $user->tokens()->delete(); // every device logged in with the old password is signed out
        Activity::record('password_changed', "{$user->name} changed their password", $user->primaryMembership()?->store_id, $user);

        return $user;
    }

    /** Easy to read out over the phone: no 0/O or 1/l/I. */
    public static function temporary(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** Sets a temporary password (shown once to the admin). The user must change it at next login. */
    public static function adminReset(User $user, User $admin, ?string $storeId = null): string
    {
        $temp = self::temporary();
        self::set($user, $temp);
        $user->must_change_password = true;
        $user->save();
        $user->tokens()->delete();
        Activity::record('password_reset', "{$admin->name} reset the password for {$user->name}", $storeId, $admin);

        return $temp;
    }
}
