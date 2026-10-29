"""Numbers for the shop owner's overview page."""
from collections import defaultdict
from datetime import timedelta
from zoneinfo import ZoneInfo

from flask import current_app

from ..extensions import db
from ..models import Customer, Product, Sale
from ..models.base import utcnow
from ..models.sale import AWAITING_CONFIRMATION, AWAITING_PAYMENT, CANCELLED, NEW, ONLINE
from ..serializers import product as product_json
from ..serializers import sale as sale_json

DAYS = 14


def build(store, *, show_cost=True):
    tz = ZoneInfo(current_app.config["TIMEZONE"])
    now = utcnow()
    today = now.astimezone(tz)
    since = (today - timedelta(days=DAYS - 1)).replace(hour=0, minute=0, second=0, microsecond=0)
    sales = db.session.scalars(
        db.select(Sale)
        .where(Sale.store_id == store.id, Sale.created_at >= since, Sale.status.notin_([AWAITING_PAYMENT, AWAITING_CONFIRMATION, CANCELLED]))
        .order_by(Sale.created_at.desc())
    ).all()

    per_day = defaultdict(lambda: {"revenue": 0, "profit": 0, "count": 0})
    by_product = defaultdict(int)
    for s in sales:
        day = per_day[s.created_at.astimezone(tz).date().isoformat()]
        day["revenue"] += s.total
        day["profit"] += sum(i.qty * (i.price - i.cost) for i in s.items)
        day["count"] += 1
        for i in s.items:
            by_product[i.name] += i.qty * i.price

    by_day = []
    for offset in range(DAYS - 1, -1, -1):
        key = (today - timedelta(days=offset)).date().isoformat()
        day = per_day.get(key, {"revenue": 0, "profit": 0, "count": 0})
        by_day.append({"date": key, **day, "profit": day["profit"] if show_cost else 0})
    last7, prev7 = by_day[-7:], by_day[:7]

    customers = db.session.scalars(db.select(Customer).filter_by(store_id=store.id)).all()
    products = db.session.scalars(db.select(Product).filter_by(store_id=store.id)).all()
    new_orders = db.session.scalar(
        db.select(db.func.count(Sale.number)).where(Sale.store_id == store.id, Sale.channel == ONLINE, Sale.status.in_([NEW, AWAITING_CONFIRMATION]))
    )

    return {
        "byDay": by_day,
        "revenue7": sum(d["revenue"] for d in last7),
        "revenuePrev7": sum(d["revenue"] for d in prev7),
        "profit7": sum(d["profit"] for d in last7),
        "sales7": sum(d["count"] for d in last7),
        "owedToYou": sum(c.balance for c in customers),
        "overdueCustomers": sum(1 for c in customers if c.balance > 0 and c.due_at and c.due_at < now),
        "lowStock": [product_json(p, show_cost=show_cost) for p in products if p.stock <= p.reorder_at],
        "newOrders": new_orders,
        "topProducts": [{"name": n, "revenue": r} for n, r in sorted(by_product.items(), key=lambda x: -x[1])[:5]],
        "recent": [sale_json(s, show_cost=show_cost) for s in sales[:6]],
    }
