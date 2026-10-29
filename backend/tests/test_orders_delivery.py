"""Delivery fees, paying the shop directly, overselling, prompt answers and payouts."""
from app.extensions import db
from app.models import Payout, Product, Store


def _product(store, slug):
    return db.session.scalar(db.select(Product).filter_by(store_id=store.id, slug=slug))


def _order(client, slug, items, **extra):
    payload = {"customer": {"name": "Daniel K", "phone": "0711 222 333"}, "items": items, **extra}
    return client.post(f"/api/storefront/{slug}/orders", json=payload)


def test_delivery_zone_fee_is_added_to_the_prompt(client, mjengo):
    hammer = _product(mjengo, "claw-hammer-16-oz")
    res = _order(client, "mjengo", [{"productId": hammer.id, "qty": 2}],
                 delivery={"type": "delivery", "zoneId": "z2", "address": "EPZ gate B"})
    assert res.status_code == 201, res.json
    assert (res.json["subtotal"], res.json["deliveryFee"], res.json["total"]) == (1300, 600, 1900)
    assert res.json["delivery"]["zone"] == "EPZ / Athi River"
    assert res.json["payment"]["amount"] == 1900


def test_free_delivery_over_threshold(client, mjengo):
    cement = _product(mjengo, "savannah-cement-42-5r")  # 800 a bag; Mjengo delivers free over 50,000
    res = _order(client, "mjengo", [{"productId": cement.id, "qty": 70}], delivery={"type": "delivery", "zoneId": "z3", "address": "Isinya"})
    assert res.json["deliveryFee"] == 0 and res.json["total"] == 56000


def test_delivery_needs_a_zone(client, mjengo):
    hammer = _product(mjengo, "claw-hammer-16-oz")
    res = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}], delivery={"type": "delivery", "address": "Somewhere"})
    assert res.status_code == 422 and res.json["field"] == "zoneId"


def test_quote_delivery_is_agreed_later(client):
    baraka = db.session.scalar(db.select(Store).filter_by(slug="baraka-builders"))
    bar = _product(baraka, "d12-deformed-bar-y12")
    res = _order(client, "baraka-builders", [{"productId": bar.id, "qty": 10}], delivery={"type": "delivery", "address": "Lanet"})
    assert res.json["deliveryFee"] == 0 and res.json["delivery"]["zone"] == "Agreed with the shop"


def test_cannot_buy_more_than_in_stock(client, mjengo):
    drill = _product(mjengo, "cordless-drill-18-v")  # 4 in stock
    res = _order(client, "mjengo", [{"productId": drill.id, "qty": 5}])
    assert res.status_code == 409 and "Only 4 left" in res.json["message"]
    assert _order(client, "mjengo", [{"productId": drill.id, "qty": 4}]).status_code == 201
    res = _order(client, "mjengo", [{"productId": drill.id, "qty": 1}])
    assert res.status_code == 409 and "sold out" in res.json["message"]


def test_paid_order_sends_money_to_the_shop(client, mjengo):
    hammer = _product(mjengo, "claw-hammer-16-oz")
    order = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}]).json
    client.get(f"/api/storefront/mjengo/orders/{order['id']}/payment")
    payout = db.session.scalar(db.select(Payout).filter_by(sale_number=int(order["id"][2:])))
    assert payout.status == "paid" and payout.amount == 650 and payout.destination == "Till 5123456"


def test_pay_shop_directly_then_shop_confirms(client, mjengo, owner):
    hammer = _product(mjengo, "claw-hammer-16-oz")
    bad = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}], paymentMode="direct", mpesaCode="nope")
    assert bad.status_code == 422 and bad.json["field"] == "mpesaCode"

    res = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}], paymentMode="direct", mpesaCode="tjk4h2pq9x")
    assert res.status_code == 201
    assert res.json["status"] == "awaiting_confirmation" and res.json["payment"] is None and res.json["mpesaRef"] == "TJK4H2PQ9X"

    base = f"/api/vendor/stores/{owner['store_id']}/sales/{res.json['id']}"
    confirmed = client.patch(base, json={"status": "new"}, headers=owner["headers"])
    assert confirmed.json["status"] == "new" and confirmed.json["paidAt"]


def test_rejected_direct_payment_returns_stock(client, mjengo, owner):
    hammer = _product(mjengo, "claw-hammer-16-oz")
    before = hammer.stock
    res = _order(client, "mjengo", [{"productId": hammer.id, "qty": 3}], paymentMode="direct", mpesaCode="TJK4H2PQ9X")
    client.patch(f"/api/vendor/stores/{owner['store_id']}/sales/{res.json['id']}", json={"status": "cancelled"}, headers=owner["headers"])
    db.session.refresh(hammer)
    assert hammer.stock == before


def test_simulated_phone_prompt_approve_and_decline(app, client, mjengo):
    app.config["SIMULATION_APPROVAL"] = "prompt"
    hammer = _product(mjengo, "claw-hammer-16-oz")
    order = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}]).json
    ref = order["payment"]["reference"]
    assert client.get(f"/api/storefront/mjengo/orders/{order['id']}/payment").json["status"] == "pending"
    assert client.post(f"/api/simulator/payments/{ref}", json={"action": "approve", "pin": "12"}).status_code == 422
    assert client.post(f"/api/simulator/payments/{ref}", json={"action": "approve", "pin": "1234"}).json["status"] == "paid"

    order2 = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}]).json
    declined = client.post(f"/api/simulator/payments/{order2['payment']['reference']}", json={"action": "decline"}).json
    assert declined["status"] == "failed"
    assert client.get(f"/api/storefront/mjengo/orders/{order2['id']}/payment").json["order"]["status"] == "cancelled"


def test_owner_sets_payout_and_delivery(client, owner):
    base = f"/api/vendor/stores/{owner['store_id']}"
    res = client.patch(base, headers=owner["headers"], json={
        "payments": {"method": "paybill", "number": "247247", "account": ""},
    })
    assert res.status_code == 422 and res.json["field"] == "payoutAccount"
    res = client.patch(base, headers=owner["headers"], json={
        "payments": {"method": "pochi", "number": "0712 345 678"},
        "delivery": {"enabled": True, "mode": "zones", "zones": [{"name": "Town", "fee": 250}, {"name": "", "fee": 9}], "freeOver": 30000},
    })
    assert res.json["payments"]["method"] == "pochi" and res.json["payments"]["number"] == "254712345678"
    assert [z["name"] for z in res.json["delivery"]["zones"]] == ["Town"] and res.json["delivery"]["freeOver"] == 30000


def test_shop_sees_its_payouts(client, owner, cashier):
    rows = client.get(f"/api/vendor/stores/{owner['store_id']}/payouts", headers=owner["headers"]).json
    assert rows and rows[0]["status"] == "paid"
    assert client.get(f"/api/vendor/stores/{owner['store_id']}/payouts", headers=cashier["headers"]).status_code == 403


def test_abandoned_prompt_is_settled_by_the_sweep(app, client, mjengo):
    """Customer closes the page during the prompt: the background sweep cancels it and frees the stock."""
    from datetime import timedelta

    from app.models import Payment, Sale
    from app.services.payment_records import sweep_pending

    app.config["SIMULATION_APPROVAL"] = "prompt"
    hammer = _product(mjengo, "claw-hammer-16-oz")
    before = hammer.stock
    order = _order(client, "mjengo", [{"productId": hammer.id, "qty": 2}]).json
    payment = db.session.get(Payment, order["payment"]["reference"])
    db.session.refresh(hammer)
    assert hammer.stock == before - 2

    assert sweep_pending() == 0  # too new to touch
    payment.created_at -= timedelta(minutes=app.config["PAYMENT_TIMEOUT_MINUTES"] + 1)
    db.session.commit()
    assert sweep_pending() == 1
    db.session.refresh(hammer)
    sale = db.session.scalar(db.select(Sale).filter_by(number=int(order["id"][2:])))
    assert payment.status == "failed" and sale.status == "cancelled" and hammer.stock == before


def test_sweep_completes_orders_paid_while_nobody_watched(app, client, mjengo):
    from datetime import timedelta

    from app.models import Payment
    from app.services.payment_records import sweep_pending

    app.config["SIMULATION_APPROVAL"] = "prompt"
    hammer = _product(mjengo, "claw-hammer-16-oz")
    order = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}]).json
    payment = db.session.get(Payment, order["payment"]["reference"])
    payment.simulated_answer = "approve"  # customer approved on the phone, then closed the page
    payment.created_at -= timedelta(minutes=3)
    db.session.commit()
    assert sweep_pending() == 1
    payout = db.session.scalar(db.select(Payout).filter_by(sale_number=int(order["id"][2:])))
    assert payment.status == "paid" and payout.status == "paid"
