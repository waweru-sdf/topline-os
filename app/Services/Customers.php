<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

class Customers
{
    public static function create(Store $store, array $data): Customer
    {
        $name = Input::text($data, 'name', required: true, maxLength: 120, message: 'Enter the customer name.');
        $rawPhone = Input::text($data, 'phone', maxLength: 20);
        $phone = $rawPhone !== '' ? Phone::normalize($rawPhone) : null;
        if ($rawPhone !== '' && ! $phone) {
            throw ApiError::invalid('Enter a phone number like 0712 345 678, or leave it empty.', 'phone');
        }

        return Customer::create(['store_id' => $store->id, 'name' => $name, 'phone' => $phone ? Phone::display($phone) : '',
            'credit_limit' => Input::integer($data, 'creditLimit', minimum: 0), 'balance' => 0]);
    }

    public static function recordPayment(Store $store, mixed $customerId, array $data, User $user): Customer
    {
        $customer = is_string($customerId) ? Customer::find($customerId) : null;
        if (! $customer || $customer->store_id !== $store->id) {
            throw ApiError::notFound('Customer not found.');
        }
        $amount = Input::integer($data, 'amount', required: true, minimum: 1, message: 'Enter an amount above 0.');
        $method = in_array($data['method'] ?? null, ['mpesa', 'cash', 'bank'], true) ? $data['method'] : 'mpesa';

        return DB::transaction(function () use ($store, $customer, $amount, $method, $user) {
            CustomerPayment::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'amount' => $amount,
                'method' => $method, 'recorded_by' => $user->id]);
            $customer->balance = max(0, $customer->balance - $amount);
            if ($customer->balance === 0) {
                $customer->due_at = null;
            }
            $customer->save();

            return $customer;
        });
    }
}
