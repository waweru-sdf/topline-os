<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\User;
use App\Services\Access;
use App\Services\Passwords;
use App\Services\Stores;
use Illuminate\Http\Request;

/** Log in with phone or email + password; change password; sign up (open a store). */
class AuthController extends ApiController
{
    private function session(User $user, int $status = 200, array $extra = [])
    {
        $token = $user->createToken('web', ['*'], now()->addHours((int) config('sanctum.expiration_hours', 72)))->plainTextToken;

        return $this->json([...$extra, 'user' => Json::user($user, $user->primaryMembership()), 'token' => $token], $status);
    }

    public function login(Request $request)
    {
        $data = $this->body($request);

        return $this->session(Passwords::logIn($data['login'] ?? null, $data['password'] ?? null));
    }

    public function changePassword(Request $request)
    {
        $data = $this->body($request);
        $user = Passwords::change(Access::currentUser(), $data['currentPassword'] ?? null, $data['newPassword'] ?? null);

        return $this->session($user); // a new token: the change signed out every older one
    }

    public function me()
    {
        $user = Access::currentUser();

        return $this->json(['user' => Json::user($user, $user->primaryMembership())]);
    }

    /** Sign-up: needs a paid, unclaimed plan payment (see BillingController). */
    public function openStore(Request $request)
    {
        [$store, $user] = Stores::create($this->body($request));

        return $this->session($user, 201, ['store' => Json::storePrivate($store)]);
    }
}
