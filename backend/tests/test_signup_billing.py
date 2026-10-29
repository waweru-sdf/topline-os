from datetime import timedelta

from app.extensions import db
from app.models import Store
from app.models.base import utcnow
from app.services.billing import sweep
from tests.conftest import pay_plan


def _signup(client, ref, slug="baraka-hardware", phone="0722 111 222"):
    return client.post(
        "/api/stores",
        json={
            "owner": {"name": "Grace Akinyi", "phone": phone},
            "business": {"name": "Baraka Hardware", "county": "Kajiado", "town": "Kitengela"},
            "planId": "business",
            "slug": slug,
            "billing": {"period": "monthly", "paymentReference": ref},
        },
    )


def test_signup_pays_then_opens_store(client):
    assert client.get("/api/stores/check-slug?slug=Baraka Hardware").json == {"slug": "baraka-hardware", "available": True, "reason": None}
    pay = pay_plan(client)
    assert pay["status"] == "paid" and pay["amount"] == 3500 and pay["payee"] == "TOPLINE"

    res = _signup(client, pay["reference"])
    assert res.status_code == 201
    assert res.json["store"]["status"] == "active"
    assert res.json["user"]["storeRole"] == "owner"
    assert client.get("/api/storefront/baraka-hardware").status_code == 200


def test_payment_cannot_open_two_stores(client):
    pay = pay_plan(client)
    assert _signup(client, pay["reference"]).status_code == 201
    assert _signup(client, pay["reference"], slug="second-shop", phone="0722 111 333").status_code == 402


def test_signup_without_payment_fails(client):
    assert _signup(client, "SUB_FAKE").status_code == 402


def test_taken_and_reserved_links(client):
    assert client.get("/api/stores/check-slug?slug=mjengo").json["available"] is False
    assert client.get("/api/stores/check-slug?slug=admin").json["available"] is False


def test_owner_renews_and_upgrades(client, owner, mjengo):
    before = mjengo.next_billing_at
    pay = pay_plan(client, "0712 345 678", plan="pro", headers=owner["headers"], signup=False)
    assert pay["status"] == "paid"
    db.session.refresh(mjengo)
    assert mjengo.plan_id == "pro"
    assert mjengo.next_billing_at - before == timedelta(days=30)


def test_billing_sweep_moves_overdue_stores(app, mjengo):
    mjengo.next_billing_at = utcnow() - timedelta(days=1)
    db.session.commit()
    sweep()
    assert mjengo.status == "past_due"
    sweep(now=utcnow() + timedelta(days=8))
    assert mjengo.status == "suspended"


def test_admin_suspends_and_reactivates(client, admin):
    store = db.session.scalar(db.select(Store).filter_by(slug="coast-steel"))
    res = client.patch(f"/api/admin/stores/{store.id}", json={"status": "suspended"}, headers=admin["headers"])
    assert res.json["status"] == "suspended"
    assert client.get("/api/storefront/coast-steel").json["products"] == []
    res = client.patch(f"/api/admin/stores/{store.id}", json={"status": "active", "extendDays": 14}, headers=admin["headers"])
    assert res.json["status"] == "active"


def test_simulated_webhook_confirms_payment(client):
    pay = client.post("/api/billing/subscription/pay", json={"phone": "0722 111 222", "planId": "starter", "signup": True}).json
    res = client.post("/api/webhooks/paystack", json={"event": "charge.success", "data": {"reference": pay["reference"]}})
    assert res.status_code == 200


def test_signup_period_comes_from_the_payment(client):
    pay = pay_plan(client, plan="starter")
    res = client.post(
        "/api/stores",
        json={
            "owner": {"name": "G", "phone": "0722 111 222"},
            "business": {"name": "Cheap Shop"},
            "planId": "starter",
            "slug": "cheap-shop",
            "billing": {"period": "yearly", "paymentReference": pay["reference"]},  # claims yearly, paid monthly
        },
    )
    assert res.json["store"]["billing"]["period"] == "monthly"


def test_signup_can_match_by_mpesa_receipt(client):
    pay = pay_plan(client)
    assert _signup_with(client, {"period": "monthly", "lastRef": pay["mpesaRef"]}).status_code == 201


def _signup_with(client, billing):
    return client.post("/api/stores", json={
        "owner": {"name": "Grace", "phone": "0722 111 222"}, "business": {"name": "Receipt Shop"},
        "planId": "business", "slug": "receipt-shop", "billing": billing,
    })


def test_signup_works_while_logged_in_as_another_owner(client, owner, mjengo):
    """Someone still logged in as a shop owner opens a second shop: the payment must
    go to the new shop, not renew the shop they're logged in as."""
    plan_before, due_before = mjengo.plan_id, mjengo.next_billing_at
    pay = pay_plan(client, "0724 000 000", plan="pro", headers=owner["headers"])
    res = client.post("/api/stores", headers=owner["headers"], json={
        "owner": {"name": "Jane", "phone": "0724 000 000"}, "business": {"name": "Jane's"}, "planId": "pro",
        "slug": "jane-s", "billing": {"period": "monthly", "paymentReference": pay["reference"]},
    })
    assert res.status_code == 201
    db.session.refresh(mjengo)
    assert (mjengo.plan_id, mjengo.next_billing_at) == (plan_before, due_before)
