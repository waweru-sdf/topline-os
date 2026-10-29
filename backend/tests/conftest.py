import pytest

from app import create_app
from app.config import TestConfig
from app.extensions import db
from app.models import Store, StoreMember, User
from app.seed import run as seed

OWNER_PHONE = "0712 345 678"
ADMIN_PHONE = "0700 000 000"


@pytest.fixture
def app():
    app = create_app(TestConfig)
    with app.app_context():
        seed(reset=True)
        yield app
        db.session.remove()
        db.drop_all()


@pytest.fixture
def client(app):
    return app.test_client()


def login(client, phone):
    client.post("/api/auth/otp", json={"phone": phone})
    res = client.post("/api/auth/otp/verify", json={"phone": phone, "code": "123456"})
    assert res.status_code == 200, res.json
    return res.json


def auth(token):
    return {"Authorization": f"Bearer {token}"}


@pytest.fixture
def owner(client):
    data = login(client, OWNER_PHONE)
    return {"headers": auth(data["token"]), "store_id": data["user"]["storeId"], "user": data["user"]}


@pytest.fixture
def admin(client):
    data = login(client, ADMIN_PHONE)
    return {"headers": auth(data["token"]), "user": data["user"]}


@pytest.fixture
def mjengo(app):
    return db.session.scalar(db.select(Store).filter_by(slug="mjengo"))


@pytest.fixture
def cashier(client, mjengo):
    """A cashier at Mjengo, created directly in the database."""
    user = User(name="Esther Wairimu", phone="254733000111")
    db.session.add(user)
    db.session.flush()
    db.session.add(StoreMember(store_id=mjengo.id, user_id=user.id, role="cashier"))
    db.session.commit()
    res = client.post("/api/auth/otp", json={"phone": "0733 000 111"})
    code = res.json["devCode"]
    data = client.post("/api/auth/otp/verify", json={"phone": "0733 000 111", "code": code}).json
    return {"headers": auth(data["token"]), "store_id": mjengo.id}


def pay_plan(client, phone="0722 111 222", plan="business", period="monthly", headers=None, signup=True):
    """Send the plan prompt and poll it to the end (auto-approved in tests)."""
    res = client.post("/api/billing/subscription/pay", json={"phone": phone, "planId": plan, "period": period, "signup": signup}, headers=headers or {})
    assert res.status_code == 202, res.json
    return client.get(f"/api/billing/payments/{res.json['reference']}").json
