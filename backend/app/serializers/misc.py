"""Serializers for the smaller resources."""
from ..utils.text import iso


def plan(p):
    limits = dict(p.limits or {})
    return {
        "id": p.id,
        "name": p.name,
        "priceMonthly": p.price_monthly,
        "priceYearly": p.price_yearly,
        "blurb": p.blurb,
        "popular": p.popular,
        "limits": limits,
        "features": p.features or [],
    }


def customer(c):
    return {
        "id": c.id,
        "name": c.name,
        "phone": c.phone,
        "creditLimit": c.credit_limit,
        "balance": c.balance,
        "dueAt": iso(c.due_at),
    }


def user(u, membership=None):
    """The session user. `storeId` and `storeRole` are set for shop staff."""
    return {
        "id": u.id,
        "name": u.name,
        "phone": u.phone,
        "role": u.role,
        "storeId": membership.store_id if membership else None,
        "storeRole": membership.role if membership else None,
    }


def quote(q):
    return {
        "id": q.id,
        "reference": q.reference,
        "name": q.name,
        "phone": q.phone,
        "items": q.items,
        "site": q.site,
        "status": q.status,
        "createdAt": iso(q.created_at),
    }
