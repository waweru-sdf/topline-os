"""Running on Render free + Neon: photos in the database, keep-awake ping, jobs over HTTP."""
import io
from datetime import timedelta

import pytest

from app.extensions import db
from app.models import Payment, PageViews, SiteSetting, Upload
from tests.test_orders_delivery import _order, _product

PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64


def test_database_url_from_neon_or_render_uses_psycopg(monkeypatch):
    from app import config

    monkeypatch.setenv("DATABASE_URL", "postgresql://u:p@ep-x.eu-central-1.aws.neon.tech/neondb?sslmode=require")
    assert config._database_url() == "postgresql+psycopg://u:p@ep-x.eu-central-1.aws.neon.tech/neondb?sslmode=require"
    monkeypatch.setenv("DATABASE_URL", "postgres://u:p@host/db")
    assert config._database_url() == "postgresql+psycopg://u:p@host/db"
    monkeypatch.setenv("DATABASE_POOL", "none")
    assert config._engine_options()["poolclass"].__name__ == "NullPool"


def test_photos_can_live_in_the_database(app, client, owner, tmp_path):
    app.config.update(UPLOAD_STORAGE="database", UPLOAD_FOLDER=str(tmp_path / "unused"))
    res = client.post("/api/uploads", data={"file": (io.BytesIO(PNG), "wire.png"), "storeId": owner["store_id"]},
                      headers=owner["headers"], content_type="multipart/form-data")
    assert res.status_code == 201
    upload_id = res.json["id"]
    assert not (tmp_path / "unused").exists()  # nothing written to disk
    assert db.session.get(Upload, upload_id).data == PNG

    photo = client.get(f"/api/uploads/{upload_id}")
    assert photo.status_code == 200 and photo.data == PNG and photo.mimetype == "image/png"
    assert "immutable" in photo.headers["Cache-Control"]
    assert client.get("/api/uploads/nope.png").status_code == 404


def test_ping_answers_without_touching_the_database(app, client, monkeypatch):
    def no_db(*a, **k):
        raise AssertionError("ping must not use the database")

    monkeypatch.setattr(db.session, "execute", no_db)
    monkeypatch.setattr(db.session, "scalar", no_db)
    monkeypatch.setattr(db.session, "commit", no_db)
    assert client.get("/api/ping").json == {"ok": True}


def test_cron_needs_the_secret(app, client):
    assert client.get("/api/cron/run").status_code == 404  # switched off without CRON_SECRET
    app.config["CRON_SECRET"] = "s3cret-value"
    assert client.get("/api/cron/run?key=wrong").status_code == 404
    assert client.get("/api/cron/run?key=s3cret-value").status_code == 200
    assert client.post("/api/cron/run", headers={"X-Cron-Key": "s3cret-value"}).status_code == 200


def test_cron_settles_abandoned_prompts_and_bills_once_a_day(app, client, mjengo, monkeypatch):
    app.config.update(CRON_SECRET="k", SIMULATION_APPROVAL="prompt")
    hammer = _product(mjengo, "claw-hammer-16-oz")
    before = hammer.stock
    order = _order(client, "mjengo", [{"productId": hammer.id, "qty": 1}]).json
    payment = db.session.get(Payment, order["payment"]["reference"])
    payment.created_at -= timedelta(minutes=app.config["PAYMENT_TIMEOUT_MINUTES"] + 1)
    db.session.commit()

    from app.api import cron

    class Morning:  # 07:00 in Nairobi
        @staticmethod
        def now(tz):
            from datetime import datetime
            return datetime(2026, 11, 2, 7, 0, tzinfo=tz)

    monkeypatch.setattr(cron, "datetime", Morning)
    calls = []
    monkeypatch.setattr(cron.billing, "sweep", lambda: calls.append(1) or 2)

    first = client.get("/api/cron/run?key=k").json
    assert first == {"paymentsSettled": 1, "billingSweep": 2}
    db.session.refresh(hammer)
    assert hammer.stock == before and payment.status == "failed"

    second = client.get("/api/cron/run?key=k").json
    assert second["billingSweep"] == "already ran today" and len(calls) == 1
    # the bookkeeping row never leaks into the public site content
    assert db.session.get(SiteSetting, cron.LAST_BILLING_RUN).value == "2026-11-02"
    assert cron.LAST_BILLING_RUN not in client.get("/api/site").json


@pytest.mark.parametrize("hour, runs", [(5, False), (6, True)])
def test_billing_waits_for_six_in_the_morning(app, client, monkeypatch, hour, runs):
    from datetime import datetime

    from app.api import cron

    app.config["CRON_SECRET"] = "k"

    class Clock:
        @staticmethod
        def now(tz):
            return datetime(2026, 11, 2, hour, 30, tzinfo=tz)

    monkeypatch.setattr(cron, "datetime", Clock)
    monkeypatch.setattr(cron.billing, "sweep", lambda: 0)
    result = client.get("/api/cron/run?key=k").json
    assert (result["billingSweep"] == 0) is runs


def test_ping_is_not_counted_as_traffic(client):
    before = db.session.scalar(db.select(db.func.count()).select_from(PageViews))
    client.get("/api/ping")
    assert db.session.scalar(db.select(db.func.count()).select_from(PageViews)) == before


def test_admins_from_environment(app, monkeypatch):
    from app.models import User
    from app.models.user import PLATFORM_ADMIN

    monkeypatch.setenv("ADMIN_PHONES", "Felix Waweru:0745 999 000, broken, Grace Wanjiru:0722118404")
    result = app.test_cli_runner().invoke(args=["ensure-admins"])
    assert "Skipped 'broken'" in result.output
    felix = db.session.scalar(db.select(User).filter_by(phone="254745999000"))
    grace = db.session.scalar(db.select(User).filter_by(phone="254722118404"))  # an existing shop owner
    assert felix.role == PLATFORM_ADMIN and felix.name == "Felix Waweru"
    assert grace.role == PLATFORM_ADMIN
    again = app.test_cli_runner().invoke(args=["ensure-admins"])
    assert "is a Topline admin" not in again.output  # nothing to change the second time
