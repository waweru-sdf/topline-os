from ..utils.text import iso


def sale(s, *, show_cost=False):
    """A counter sale or online order. `cost` per line only for people allowed to see margins."""
    return {
        "id": s.code,
        "storeId": s.store_id,
        "channel": s.channel,
        "status": s.status,
        "method": s.method,
        "paymentMode": s.payment_mode,
        "subtotal": s.subtotal or s.total,
        "deliveryFee": s.delivery_fee or 0,
        "total": s.total,
        "createdAt": iso(s.created_at),
        "paidAt": iso(s.paid_at),
        "staff": s.staff_name,
        "customerId": s.customer_id,
        "customerName": s.customer_name,
        "customerPhone": s.customer_phone,
        "delivery": (
            {"type": s.delivery_type, "zone": s.delivery_zone, "address": s.delivery_address, "notes": s.delivery_notes}
            if s.delivery_type else None
        ),
        "mpesaRef": s.mpesa_ref,
        "items": [_item(i, show_cost) for i in s.items],
    }


def payout(p):
    return {
        "id": p.id,
        "reference": p.reference,
        "orderId": f"S-{p.sale_number}" if p.sale_number else None,
        "gross": p.gross,
        "commission": p.commission,
        "amount": p.amount,
        "method": p.method,
        "destination": p.destination,
        "status": p.status,
        "providerRef": p.provider_ref,
        "failureReason": p.failure_reason,
        "createdAt": iso(p.created_at),
        "paidAt": iso(p.paid_at),
    }


def _item(i, show_cost):
    data = {"productId": i.product_id, "name": i.name, "unit": i.unit, "qty": i.qty, "price": i.price}
    if show_cost:
        data["cost"] = i.cost
    return data
