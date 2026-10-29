"""Owners reach only their own store; Topline admins reach every store and the admin area."""
from app.extensions import db
from app.models import Store


def test_owner_sees_own_store_with_costs(client, owner):
    res = client.get(f"/api/vendor/stores/{owner['store_id']}/products", headers=owner["headers"])
    assert res.status_code == 200
    assert "buyPrice" in res.json["products"][0]


def test_owner_cannot_open_another_store(client, owner):
    other = db.session.scalar(db.select(Store).filter_by(slug="coast-steel"))
    res = client.get(f"/api/vendor/stores/{other.id}/dashboard", headers=owner["headers"])
    assert res.status_code == 404  # looks like "doesn't exist", so ids can't be probed


def test_owner_cannot_use_platform_admin(client, owner):
    assert client.get("/api/admin/stores", headers=owner["headers"]).status_code == 403


def test_admin_can_open_any_store_admin(client, admin, mjengo):
    res = client.get(f"/api/vendor/stores/{mjengo.id}/dashboard", headers=admin["headers"])
    assert res.status_code == 200
    assert res.json["revenue7"] > 0
    store = client.get(f"/api/vendor/stores/{mjengo.id}", headers=admin["headers"]).json
    assert store["myRole"] == "platform_admin"


def test_admin_lists_every_store(client, admin):
    stores = client.get("/api/vendor/stores", headers=admin["headers"]).json
    assert len(stores) == 7
    assert client.get("/api/admin/overview", headers=admin["headers"]).json["stores"]["total"] == 7


def test_cashier_sells_but_never_sees_buying_prices(client, cashier):
    base = f"/api/vendor/stores/{cashier['store_id']}"
    products = client.get(f"{base}/products", headers=cashier["headers"]).json["products"]
    assert "buyPrice" not in products[0]
    assert client.get(f"{base}/dashboard", headers=cashier["headers"]).status_code == 403
    assert client.patch(base, json={"name": "Hacked"}, headers=cashier["headers"]).status_code == 403
    sale = client.post(f"{base}/sales", json={"items": [{"productId": products[0]["id"], "qty": 1}], "method": "cash"}, headers=cashier["headers"])
    assert sale.status_code == 201
    assert "cost" not in sale.json["items"][0]


def test_owner_cannot_change_plan_or_status_through_settings(client, owner):
    base = f"/api/vendor/stores/{owner['store_id']}"
    res = client.patch(base, json={"name": "Mjengo Hardware Ltd", "planId": "pro", "status": "active"}, headers=owner["headers"])
    assert res.status_code == 200
    assert res.json["name"] == "Mjengo Hardware Ltd"
    assert res.json["planId"] == "business"


def test_browser_preflight_is_allowed_on_protected_routes(client, mjengo):
    """Browsers send OPTIONS without a token before every cross-origin API call."""
    headers = {"Origin": "http://localhost:5173", "Access-Control-Request-Method": "GET", "Access-Control-Request-Headers": "authorization,content-type"}
    for path in (f"/api/vendor/stores/{mjengo.id}", f"/api/vendor/stores/{mjengo.id}/dashboard", "/api/admin/stores"):
        res = client.options(path, headers=headers)
        assert res.status_code == 200, path
        assert res.headers["Access-Control-Allow-Origin"] == "http://localhost:5173"


def test_unknown_origins_get_no_cors_headers(client):
    res = client.get("/api/plans", headers={"Origin": "https://evil.example"})
    assert "Access-Control-Allow-Origin" not in res.headers
