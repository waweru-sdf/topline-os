<?php

namespace App\Services;

/** What each role inside a store may do. Topline admins may do everything, in every store. */
class Permissions
{
    public const VIEW_DASHBOARD = 'view_dashboard';
    public const VIEW_COSTS = 'view_costs'; // buying prices, margins, profit
    public const VIEW_PRODUCTS = 'view_products';
    public const MANAGE_PRODUCTS = 'manage_products';
    public const SELL = 'sell';
    public const MANAGE_ORDERS = 'manage_orders';
    public const VIEW_CUSTOMERS = 'view_customers';
    public const MANAGE_CUSTOMERS = 'manage_customers';
    public const MANAGE_SETTINGS = 'manage_settings';
    public const MANAGE_BILLING = 'manage_billing';
    public const BUY_STOCK = 'buy_stock';           // order from suppliers
    public const MANAGE_ACCOUNTS = 'manage_accounts'; // record expenses, see the books

    public static function for(?string $storeRole, bool $isPlatformAdmin = false): array
    {
        $cashier = [self::VIEW_PRODUCTS, self::SELL, self::MANAGE_ORDERS, self::VIEW_CUSTOMERS];
        $manager = [...$cashier, self::VIEW_DASHBOARD, self::VIEW_COSTS, self::MANAGE_PRODUCTS, self::MANAGE_CUSTOMERS,
            self::BUY_STOCK, self::MANAGE_ACCOUNTS];
        $owner = [...$manager, self::MANAGE_SETTINGS, self::MANAGE_BILLING];
        if ($isPlatformAdmin) {
            $roles = $owner;
        } else {
            $roles = ['owner' => $owner, 'manager' => $manager, 'cashier' => $cashier][$storeRole] ?? [];
        }
        sort($roles);

        return $roles;
    }
}
