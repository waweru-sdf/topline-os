<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Tests\TestCase;

/** Logging in with phone or email + password, lockout, password changes and admin resets. */
class AuthTest extends TestCase
{
    public function test_owner_logs_in_with_phone(): void
    {
        $data = $this->login('0712 345 678');
        $this->assertSame('vendor', $data['user']['role']);
        $this->assertSame('owner', $data['user']['storeRole']);
        $this->assertFalse($data['user']['mustChangePassword']);
        $this->getJson('/api/auth/me', $data['headers'])->assertOk();
    }

    public function test_owner_logs_in_with_email_any_case(): void
    {
        $this->assertSame('Peter Kamau', $this->login('  Peter@Mjengo.example.com ')['user']['name']);
    }

    public function test_phone_in_any_kenyan_format(): void
    {
        foreach (['0712345678', '+254 712 345 678', '254712345678'] as $phone) {
            $this->assertSame('Peter Kamau', $this->login($phone)['user']['name']);
        }
    }

    public function test_wrong_password_and_unknown_account_look_the_same(): void
    {
        $wrong = $this->postJson('/api/auth/login', ['login' => '0712 345 678', 'password' => 'nope-nope']);
        $unknown = $this->postJson('/api/auth/login', ['login' => '0799 888 777', 'password' => 'nope-nope']);
        $wrong->assertStatus(401);
        $unknown->assertStatus(401);
        $this->assertSame('Wrong phone/email or password.', $wrong->json('message'));
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_missing_fields_are_field_errors(): void
    {
        $this->assertSame('login', $this->postJson('/api/auth/login', ['login' => '', 'password' => 'x'])->json('field'));
        $this->assertSame('password', $this->postJson('/api/auth/login', ['login' => '0712345678', 'password' => ''])->json('field'));
    }

    public function test_five_wrong_passwords_lock_the_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['login' => '0712 345 678', 'password' => 'wrong-pass']);
        }
        $locked = $this->postJson('/api/auth/login', ['login' => '0712 345 678', 'password' => 'topline123']);
        $locked->assertStatus(429);
        $this->assertStringContainsString('Try again in', $locked->json('message'));
    }

    public function test_login_is_recorded_in_activity(): void
    {
        $this->login('0712 345 678');
        $entry = ActivityLog::where('kind', 'login')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame($this->store()->id, $entry->store_id);
        $this->assertStringContainsString('Peter Kamau logged in', $entry->summary);
    }

    public function test_change_password_signs_out_other_devices(): void
    {
        $first = $this->login('0712 345 678');
        $second = $this->login('0712 345 678');
        $bad = $this->postJson('/api/auth/password', ['currentPassword' => 'nope', 'newPassword' => 'new-pass-123'], $first['headers']);
        $bad->assertStatus(422)->assertJson(['field' => 'currentPassword']);
        $short = $this->postJson('/api/auth/password', ['currentPassword' => 'topline123', 'newPassword' => 'short'], $first['headers']);
        $short->assertJson(['field' => 'newPassword']);

        $res = $this->postJson('/api/auth/password', ['currentPassword' => 'topline123', 'newPassword' => 'new-pass-123'], $first['headers']);
        $res->assertOk();
        $this->assertNotEmpty($res->json('token'));
        $this->getJson('/api/auth/me', $second['headers'])->assertStatus(401); // old session ended
        $this->getJson('/api/auth/me', $this->auth($res->json('token')))->assertOk();
        $this->login('0712 345 678', 'new-pass-123');
    }

    public function test_admin_reset_forces_a_new_password(): void
    {
        $mjengo = $this->store();
        $admin = $this->admin();
        $oldOwner = $this->owner();
        $res = $this->postJson("/api/admin/stores/{$mjengo->id}/reset-password", [], $admin['headers']);
        $res->assertOk();
        $temp = $res->json('temporaryPassword');
        $this->assertSame(10, strlen($temp));
        $this->assertSame('Peter Kamau', $res->json('owner'));
        $this->getJson('/api/auth/me', $oldOwner['headers'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['login' => '0712 345 678', 'password' => 'topline123'])->assertStatus(401);

        $owner = $this->login('0712 345 678', $temp);
        $this->assertTrue($owner['user']['mustChangePassword']);
        $blocked = $this->getJson("/api/vendor/stores/{$mjengo->id}/dashboard", $owner['headers']);
        $blocked->assertStatus(403)->assertJson(['message' => 'Choose a new password first.']);
        $this->getJson('/api/auth/me', $owner['headers'])->assertOk();

        $changed = $this->postJson('/api/auth/password', ['newPassword' => 'brand-new-99'], $owner['headers']);
        $changed->assertOk();
        $this->assertFalse($changed->json('user.mustChangePassword'));
        $this->getJson("/api/vendor/stores/{$mjengo->id}/dashboard", $this->auth($changed->json('token')))->assertOk();
        $kinds = ActivityLog::where('store_id', $mjengo->id)->pluck('kind')->all();
        $this->assertContains('password_reset', $kinds);
        $this->assertContains('password_changed', $kinds);
    }

    public function test_only_admins_reset_passwords(): void
    {
        $this->postJson("/api/admin/stores/{$this->store()->id}/reset-password", [], $this->owner()['headers'])->assertStatus(403);
    }

    public function test_switched_off_account_cannot_log_in(): void
    {
        User::where('phone', '254712345678')->update(['is_active' => false]);
        $this->postJson('/api/auth/login', ['login' => '0712 345 678', 'password' => 'topline123'])->assertStatus(403);
    }

    public function test_requests_without_token_are_refused(): void
    {
        $this->getJson("/api/vendor/stores/{$this->store()->id}")->assertStatus(401);
        $this->getJson('/api/admin/stores')->assertStatus(401);
    }
}
