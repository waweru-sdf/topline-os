"""Going live: real SMS through Africa's Talking (HTTP faked), and the start-up safety checks."""
import pytest

from app import create_app
from app.config import TestConfig
from app.models import Incident
from app.services import sms as sms_module
from app.services.sms import AfricasTalkingSms, SmsError


class FakeResponse:
    def __init__(self, payload, status=201):
        self._payload, self.status_code, self.text = payload, status, str(payload)

    def json(self):
        return self._payload


def ok_reply(phone="+254712345678"):
    return FakeResponse({"SMSMessageData": {"Message": "Sent to 1/1", "Recipients": [
        {"number": phone, "status": "Success", "statusCode": 101, "messageId": "ATXid_1", "cost": "KES 0.8000"}]}})


@pytest.fixture
def at_calls(monkeypatch):
    calls, replies = [], []

    def fake_post(url, data=None, headers=None, timeout=None):
        calls.append({"url": url, "data": data, "headers": headers})
        return replies.pop(0)

    monkeypatch.setattr(sms_module.requests, "post", fake_post)
    return calls, replies


def test_africastalking_sends_the_right_request(at_calls):
    calls, replies = at_calls
    replies.append(ok_reply())
    AfricasTalkingSms("topline", "key123", "TOPLINE").send("254712345678", "Hello")
    call = calls[0]
    assert call["url"] == AfricasTalkingSms.LIVE
    assert call["headers"]["apiKey"] == "key123"
    assert call["data"] == {"username": "topline", "to": "+254712345678", "message": "Hello", "from": "TOPLINE"}


def test_sandbox_username_uses_sandbox_and_no_sender(at_calls):
    calls, replies = at_calls
    replies.append(ok_reply())
    AfricasTalkingSms("sandbox", "key").send("254712345678", "Hi")
    assert calls[0]["url"] == AfricasTalkingSms.SANDBOX
    assert "from" not in calls[0]["data"]


@pytest.mark.parametrize("reply", [
    FakeResponse({"SMSMessageData": {"Recipients": [{"status": "InsufficientBalance", "statusCode": 405}]}}),
    FakeResponse({"SMSMessageData": {"Recipients": []}}),
    FakeResponse("The supplied authentication is invalid", status=401),
])
def test_africastalking_failures_raise(at_calls, reply):
    calls, replies = at_calls
    replies.append(reply)
    with pytest.raises(SmsError):
        AfricasTalkingSms("topline", "key").send("254712345678", "Hi")


def test_live_login_code_is_random_hidden_and_sent_by_sms(app, client, at_calls):
    calls, replies = at_calls
    replies.append(ok_reply())
    app.config.update(SMS_MODE="africastalking", AT_USERNAME="topline", AT_API_KEY="key", EXPOSE_DEV_OTP=False)
    res = client.post("/api/auth/otp", json={"phone": "0712 345 678"})  # a demo phone: still no 123456
    assert res.status_code == 200 and "devCode" not in res.json
    message = calls[0]["data"]["message"]
    code = message.split("code is ")[1][:6]
    assert code.isdigit()
    assert client.post("/api/auth/otp/verify", json={"phone": "0712 345 678", "code": code}).status_code == 200


def test_failed_login_sms_is_a_clear_error_and_an_incident(app, client, at_calls):
    calls, replies = at_calls
    replies.append(FakeResponse({"SMSMessageData": {"Recipients": [{"status": "InsufficientBalance"}]}}))
    app.config.update(SMS_MODE="africastalking", AT_USERNAME="topline", AT_API_KEY="key", EXPOSE_DEV_OTP=False)
    res = client.post("/api/auth/otp", json={"phone": "0712 345 678"})
    assert res.status_code == 503
    assert "couldn't send the code" in res.json["message"]
    from app.extensions import db
    assert db.session.scalar(db.select(Incident).filter_by(kind="sms")).count == 1


class LiveConfig(TestConfig):
    APP_ENV = "production"
    SECRET_KEY = "x" * 40
    JWT_SECRET = "y" * 40
    EXPOSE_DEV_OTP = False
    SQLALCHEMY_DATABASE_URI = "postgresql+psycopg://u:p@localhost/topline"


def test_safe_production_settings_start():
    create_app(LiveConfig)


@pytest.mark.parametrize("change, complaint", [
    ({"SECRET_KEY": "dev-only-insecure-key-change-me-before-going-live"}, "SECRET_KEY"),
    ({"JWT_SECRET": "short"}, "JWT_SECRET"),
    ({"EXPOSE_DEV_OTP": True}, "EXPOSE_DEV_OTP"),
    ({"SQLALCHEMY_DATABASE_URI": "sqlite:///x.db"}, "PostgreSQL"),
    ({"SMS_MODE": "africastalking"}, "AT_API_KEY"),
    ({"PAYMENTS_MODE": "paystack", "PAYSTACK_SECRET_KEY": ""}, "PAYSTACK_SECRET_KEY"),
])
def test_unsafe_production_settings_refuse_to_start(change, complaint):
    config = type("Unsafe", (LiveConfig,), change)
    with pytest.raises(RuntimeError, match=complaint):
        create_app(config)


def test_behind_nginx_links_use_https_and_the_real_host():
    from flask import request

    app = create_app(type("Proxied", (TestConfig,), {"TRUST_PROXY": True}))
    headers = {"X-Forwarded-Proto": "https", "X-Forwarded-Host": "mjengo.toplinehub.co.ke"}
    with app.test_client() as c:
        c.get("/api/uploads/missing.jpg", headers=headers)
        assert request.host_url == "https://mjengo.toplinehub.co.ke/"


@pytest.mark.parametrize("domain, status", [
    ("toplinehub.co.ke", 200),
    ("www.toplinehub.co.ke", 200),
    ("mjengo.toplinehub.co.ke", 200),
    ("MWAMBA.toplinehub.co.ke.", 200),
    ("rift-timber.toplinehub.co.ke", 200),  # suspended stores still show their "closed" page
    ("nosuchshop.toplinehub.co.ke", 404),
    ("a.mjengo.toplinehub.co.ke", 404),
    ("mjengo.example.com", 404),
    ("", 404),
])
def test_https_certificates_only_for_real_stores(client, domain, status):
    assert client.get("/api/tls/check", query_string={"domain": domain}).status_code == status
