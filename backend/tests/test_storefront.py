from app.extensions import db
from app.models import Product, Store


def test_storefront_hides_buying_prices(client):
    res = client.get("/api/storefront/mjengo")
    assert res.status_code == 200
    assert res.json["store"]["name"] == "Mjengo Hardware"
    assert all("buyPrice" not in p for p in res.json["products"])


def test_unknown_store_is_404(client):
    assert client.get("/api/storefront/no-such-shop").status_code == 404


def test_suspended_store_shows_closed(client):
    res = client.get("/api/storefront/rift-timber")
    assert res.json["store"]["status"] == "suspended"
    assert res.json["products"] == []
    order = client.post("/api/storefront/rift-timber/orders", json={"customer": {"name": "A", "phone": "0711222333"}, "items": [{"productId": "x", "qty": 1}]})
    assert order.status_code == 409


def test_order_is_paid_by_simulated_mpesa_and_reduces_stock(client, mjengo):
    product = db.session.scalar(db.select(Product).filter_by(store_id=mjengo.id, slug="d12-deformed-bar-y12"))
    before = product.stock
    res = client.post(
        "/api/storefront/mjengo/orders",
        json={
            "customer": {"name": "Daniel K", "phone": "0711 222 333"},
            "items": [{"productId": product.id, "qty": 6}],
            "delivery": {"type": "pickup"},
        },
    )
    assert res.status_code == 201
    assert res.json["status"] == "awaiting_payment"
    assert res.json["subtotal"] == 6 * 1540 and res.json["deliveryFee"] == 0  # free delivery doesn't apply; zone needed
    assert res.json["payment"]["amount"] == res.json["total"]  # prompt is for the exact total
    assert res.json["payment"]["payee"] == "MJENGO HARDWARE"
    assert "cost" not in res.json["items"][0]
    db.session.refresh(product)
    assert product.stock == before - 6  # reserved as soon as the order is placed

    paid = client.get(f"/api/storefront/mjengo/orders/{res.json['id']}/payment").json
    assert paid["status"] == "paid"
    assert paid["mpesaRef"]


def test_failed_payment_releases_stock(client, mjengo):
    product = db.session.scalar(db.select(Product).filter_by(store_id=mjengo.id, slug="claw-hammer-16-oz"))
    before = product.stock
    order = client.post("/api/storefront/mjengo/orders", json={"customer": {"name": "B", "phone": "0711 222 999"}, "items": [{"productId": product.id, "qty": 1}]}).json
    res = client.get(f"/api/storefront/mjengo/orders/{order['id']}/payment").json
    assert res["status"] == "failed" and "balance" in res["failureReason"]
    db.session.refresh(product)
    assert product.stock == before


def test_products_from_another_store_cannot_be_ordered(client):
    other = db.session.scalar(db.select(Store).filter_by(slug="coast-steel"))
    stray = Product(store_id=other.id, slug="x", name="X", category_id="tools", unit="piece", sell_price=10)
    db.session.add(stray)
    db.session.commit()
    res = client.post("/api/storefront/mjengo/orders", json={"customer": {"name": "C", "phone": "0711222333"}, "items": [{"productId": stray.id, "qty": 1}]})
    assert res.status_code == 409


def test_quote_request(client):
    res = client.post("/api/storefront/mjengo/quotes", json={"name": "Site A", "phone": "0711222333", "items": "60 bags cement"})
    assert res.status_code == 201
    assert res.json["reference"].startswith("Q-")
