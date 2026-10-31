<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Passwords;
use App\Support\ApiError;
use App\Support\Phone;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'topline:create-admin {name : e.g. "Felix Waweru"} {phone : e.g. 0712000000}
                            {--email= : optional; lets them log in with email too}
                            {--password= : at least 8 characters (asked for if left out)}';

    protected $description = 'Add (or update) a Topline admin who logs in with phone or email + password';

    public function handle(): int
    {
        $phone = Phone::normalize($this->argument('phone'));
        if (! $phone) {
            $this->error('Use a Kenyan mobile like 0712 345 678.');

            return self::FAILURE;
        }
        $password = $this->option('password') ?? $this->secret('Password (at least 8 characters)');
        try {
            $email = Passwords::normalizeEmail($this->option('email'));
            Passwords::checkNew($password);
        } catch (ApiError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $user = User::where('phone', $phone)->first() ?? new User(['phone' => $phone]);
        $user->name = $this->argument('name');
        $user->role = User::PLATFORM_ADMIN;
        $user->is_active = true;
        if ($email) {
            $user->email = $email;
        }
        Passwords::set($user, $password);
        $user->must_change_password = false;
        $user->save();
        $this->info("{$user->name} (".Phone::display($phone).') is a Topline admin and can log in now.');

        return self::SUCCESS;
    }
}
