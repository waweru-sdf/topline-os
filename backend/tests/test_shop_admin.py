from app.extensions import db
from app.models import Customer


def _base(owner):
    return f"/api/vendor/stores/{owner['store_id']}"


def test_create_edit_delete_product(client, owner):
    base, h = _base(owner), owner["headers"]
    res = client.post(f"{base}/products", json={"name": "Binding wire 1kg", "sellPrice": 350, "buyPrice": 260, "stock": 40, "categoryId": "steel"}, headers=h)
    assert res.status_code == 201
    pid = res.json["id"]
    assert res.json["slug"] == "binding-wire-1kg" and res.json["stock"] == 40

    res = client.put(f"{base}/products/{pid}", json={"name": "Binding wire 1 kg roll", "sellPrice": 380, "stock": 35}, headers=h)
    assert res.json["sellPrice"] == 380 and res.json["stock"] == 35

    assert client.post(f"{base}/products/{pid}/stock", json={"delta": 5}, headers=h).json["stock"] == 40
    assert client.delete(f"{base}/products/{pid}", headers=h).status_code == 204


def test_product_needs_a_price(client, owner):
    res = client.post(f"{_base(owner)}/products", json={"name": "Nails"}, headers=owner["headers"])
    assert res.status_code == 422 and res.json["field"] == "sellPrice"


def test_credit_sale_respects_limit(client, owner):
    base, h = _base(owner), owner["headers"]
    products = client.get(f"{base}/products", headers=h).json["products"]
    drill = next(p for p in products if p["slug"] == "cordless-drill-18-v")
    mutua = db.session.scalar(db.select(Customer).filter_by(name="Mutua Welding"))  # owes 58,200 of 60,000

    res = client.post(f"{base}/sales", json={"items": [{"productId": drill["id"], "qty": 1}], "method": "credit", "customerId": mutua.id}, headers=h)
    assert res.status_code == 422 and res.json["field"] == "customer"

    sunrise = db.session.scalar(db.select(Customer).filter_by(name="Sunrise Apartments site"))
    res = client.post(f"{base}/sales", json={"items": [{"productId": drill["id"], "qty": 2}], "method": "credit", "customerId": sunrise.id}, headers=h)
    assert res.status_code == 201
    db.session.refresh(sunrise)
    assert sunrise.balance == 19600 and sunrise.due_at is not None


def test_customer_payment_reduces_balance(client, owner):
    base, h = _base(owner), owner["headers"]
    jane = db.session.scalar(db.select(Customer).filter_by(name="Jane Njeri (fundi)"))
    res = client.post(f"{base}/customers/{jane.id}/payments", json={"amount": 12800}, headers=h)
    assert res.json["balance"] == 0 and res.json["dueAt"] is None


def test_online_order_status_flow(client, owner):
    base, h = _base(owner), owner["headers"]
    new_order = next(o for o in client.get(f"{base}/sales?channel=online", headers=h).json if o["status"] == "new")
    assert client.patch(f"{base}/sales/{new_order['id']}", json={"status": "completed"}, headers=h).status_code == 422
    assert client.patch(f"{base}/sales/{new_order['id']}", json={"status": "confirmed"}, headers=h).json["status"] == "confirmed"


def test_dashboard_shape(client, owner):
    d = client.get(f"{_base(owner)}/dashboard", headers=owner["headers"]).json
    assert len(d["byDay"]) == 14
    assert {"revenue7", "profit7", "owedToYou", "lowStock", "topProducts", "recent"} <= d.keys()
    assert d["overdueCustomers"] == 2
