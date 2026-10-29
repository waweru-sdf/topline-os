"""Numbers for the Topline admin overview: stores, money, customers, traffic, health."""
from collections import defaultdict
from datetime import UTC, date, datetime, timedelta

from ..extensions import db
from ..models import Customer, Incident, PageViews, Payment, Payout, Plan, RequestStats, Sale, Store
from ..models.base import utcnow
from ..models.payment import FAILED, PAID, PENDING
from ..models.sale import AWAITING_CONFIRMATION, AWAITING_PAYMENT, CANCELLED, ONLINE
from ..models.store import ACTIVE, STORE_STATUSES

DAYS = 30


def overview():
    now = utcnow()
    since = now - timedelta(days=DAYS)
    stores = db.session.scalars(db.select(Store)).all()
    plans = {p.id: p for p in db.session.scalars(db.select(Plan))}
    done_sales = db.select(Sale).where(Sale.created_at >= since, Sale.status.notin_([AWAITING_PAYMENT, AWAITING_CONFIRMATION, CANCELLED]))
    sales = db.session.scalars(done_sales).all()

    return {
        "stores": _stores_block(stores, plans, now),
        "money": _money_block(sales, since),
        "customers": _customers_block(since),
        "traffic": _traffic_block(stores),
        "health": _health_block(now),
    }


def _stores_block(stores, plans, now):
    by_status = {s: 0 for s in STORE_STATUSES}
    signups = defaultdict(int)
    mrr = 0
    for s in stores:
        by_status[s.status] = by_status.get(s.status, 0) + 1
        signups[s.created_at.date().isoformat()] += 1
        plan = plans.get(s.plan_id)
        if s.status == ACTIVE and plan:
            mrr += plan.price_monthly if s.billing_period == "monthly" else plan.price_yearly // 12
    return {
        "total": len(stores),
        "byStatus": by_status,
        "monthlyRevenue": mrr,
        "signupsByDay": _series(signups, now),
    }


def _money_block(sales, since):
    online = [s for s in sales if s.channel == ONLINE]
    subs = db.session.scalar(
        db.select(db.func.coalesce(db.func.sum(Payment.amount), 0)).where(
            Payment.kind == "subscription", Payment.status == PAID, Payment.paid_at >= since
        )
    )
    payouts = db.session.execute(
        db.select(Payout.status, db.func.count(Payout.id), db.func.coalesce(db.func.sum(Payout.amount), 0))
        .where(Payout.created_at >= since).group_by(Payout.status)
    ).all()
    by_status = {status: {"count": count, "amount": int(amount)} for status, count, amount in payouts}
    return {
        "salesValue": sum(s.total for s in sales),
        "onlineOrders": len(online),
        "onlineValue": sum(s.total for s in online),
        "counterSales": len(sales) - len(online),
        "subscriptionRevenue": int(subs or 0),
        "payouts": {k: by_status.get(k, {"count": 0, "amount": 0}) for k in (PAID, PENDING, FAILED)},
    }


def _customers_block(since):
    """Tally of people buying across all shops: online buyers by phone, plus shops' credit customers."""
    rows = db.session.execute(
        db.select(Sale.customer_phone, db.func.count(Sale.number), db.func.min(Sale.created_at))
        .where(Sale.channel == ONLINE, Sale.customer_phone.is_not(None), Sale.status.notin_([AWAITING_PAYMENT, CANCELLED]))
        .group_by(Sale.customer_phone)
    ).all()
    return {
        "onlineBuyers": len(rows),
        "newBuyers30": sum(1 for _, _, first in rows if _aware(first) and _aware(first) >= since),
        "repeatBuyers": sum(1 for _, n, _ in rows if n > 1),
        "creditCustomers": db.session.scalar(db.select(db.func.count(Customer.id))),
        "owedToShops": int(db.session.scalar(db.select(db.func.coalesce(db.func.sum(Customer.balance), 0))) or 0),
    }


def _traffic_block(stores):
    start = date.today() - timedelta(days=DAYS - 1)
    rows = db.session.scalars(db.select(PageViews).where(PageViews.day >= start)).all()
    site, shop = defaultdict(int), defaultdict(int)
    per_store = defaultdict(int)
    for r in rows:
        (site if r.store_id is None else shop)[r.day.isoformat()] += r.views
        if r.store_id:
            per_store[r.store_id] += r.views
    names = {s.id: (s.name, s.slug) for s in stores}
    top = sorted(per_store.items(), key=lambda kv: -kv[1])[:8]
    days = [(start + timedelta(days=i)).isoformat() for i in range(DAYS)]
    return {
        "byDay": [{"date": d, "site": site.get(d, 0), "stores": shop.get(d, 0)} for d in days],
        "topStores": [{"storeId": sid, "name": names.get(sid, ("?", ""))[0], "slug": names.get(sid, ("", "?"))[1], "views": v} for sid, v in top],
        "total30": sum(site.values()) + sum(shop.values()),
    }


def _health_block(now):
    since = now - timedelta(hours=24)
    rows = db.session.scalars(db.select(RequestStats).where(RequestStats.hour >= since).order_by(RequestStats.hour)).all()
    requests = sum(r.requests for r in rows)
    return {
        "requests24": requests,
        "serverErrors24": sum(r.server_errors for r in rows),
        "clientErrors24": sum(r.client_errors for r in rows),
        "avgMs": round(sum(r.total_ms for r in rows) / requests) if requests else 0,
        "slowestMs": max((r.slowest_ms for r in rows), default=0),
        "openIncidents": db.session.scalar(db.select(db.func.count(Incident.id)).filter_by(resolved=False)),
        "byHour": [{"hour": r.hour.isoformat(), "requests": r.requests, "errors": r.server_errors} for r in rows],
    }


def _aware(value):
    """Aggregates come back as datetime or ISO text depending on the database."""
    if value is None:
        return None
    if isinstance(value, str):
        value = datetime.fromisoformat(value)
    return value if value.tzinfo else value.replace(tzinfo=UTC)


def _series(counts, now):
    start = (now - timedelta(days=DAYS - 1)).date()
    return [{"date": (start + timedelta(days=i)).isoformat(), "count": counts.get((start + timedelta(days=i)).isoformat(), 0)} for i in range(DAYS)]
