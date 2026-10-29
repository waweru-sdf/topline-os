"""What each role inside a store may do. Topline platform admins may do everything, in every store."""
from ..models.user import CASHIER, MANAGER, OWNER

VIEW_DASHBOARD = "view_dashboard"
VIEW_COSTS = "view_costs"  # buying prices, margins, profit
VIEW_PRODUCTS = "view_products"
MANAGE_PRODUCTS = "manage_products"
SELL = "sell"
MANAGE_ORDERS = "manage_orders"
VIEW_CUSTOMERS = "view_customers"
MANAGE_CUSTOMERS = "manage_customers"
MANAGE_SETTINGS = "manage_settings"
MANAGE_BILLING = "manage_billing"

_CASHIER = {VIEW_PRODUCTS, SELL, MANAGE_ORDERS, VIEW_CUSTOMERS}
_MANAGER = _CASHIER | {VIEW_DASHBOARD, VIEW_COSTS, MANAGE_PRODUCTS, MANAGE_CUSTOMERS}
_OWNER = _MANAGER | {MANAGE_SETTINGS, MANAGE_BILLING}

ROLE_PERMISSIONS = {OWNER: _OWNER, MANAGER: _MANAGER, CASHIER: _CASHIER}
ALL = _OWNER


def permissions_for(store_role, is_platform_admin=False):
    if is_platform_admin:
        return ALL
    return ROLE_PERMISSIONS.get(store_role, set())
