"""The Paystack adapter, with Paystack's HTTP API faked: checks we send the right requests
and read the answers correctly, so going live is only a matter of adding keys."""
import hashlib
import hmac
import json

import pytest

from app.services.payments.base import ChargeRequest, TransferRequest
from app.services.payments.paystack import PaystackError, PaystackGateway


class FakeResponse:
    def __init__(self, payload, status=200):
        self._payload, self.status_code = payload, status

    def json(self):
        return self._payload


@pytest.fixture
def paystack(app, monkeypatch):
    app.config.update(PAYMENTS_MODE="paystack", PAYSTACK_SECRET_KEY="sk_test_abc")
    calls = []
    replies = []

    def fake_request(method, url, headers=None, json=None, timeout=None):
        calls.append({"method": method, "url": url, "headers": headers, "json": json})
        return replies.pop(0)

    monkeypatch.setattr("app.services.payments.paystack.requests.request", fake_request)
    return PaystackGateway(), calls, replies


def test_charge_sends_mpesa_prompt_for_exact_amount(paystack):
    gateway, calls, replies = paystack
    replies.append(FakeResponse({"status": True, "data": {"status": "pay_offline", "reference": "ORD_1"}}))
    result = gateway.charge_mpesa(ChargeRequest(reference="ORD_1", amount=1900, phone="254711222333", email="a@b.c"))
    assert result.status == "pending"
    body = calls[0]["json"]
    assert calls[0]["url"].endswith("/charge") and calls[0]["headers"]["Authorization"] == "Bearer sk_test_abc"
    assert body["amount"] == 190000 and body["currency"] == "KES"
    assert body["mobile_money"] == {"phone": "+254711222333", "provider": "mpesa"}


def test_verify_success_and_failure(paystack):
    gateway, _, replies = paystack

    class P:
        reference = "ORD_1"

    replies.append(FakeResponse({"status": True, "data": {"status": "success", "receipt_number": "TJK4H2PQ9X"}}))
    ok = gateway.verify(P())
    assert ok.status == "paid" and ok.provider_ref == "TJK4H2PQ9X"
    replies.append(FakeResponse({"status": True, "data": {"status": "failed", "gateway_response": "Insufficient funds"}}))
    bad = gateway.verify(P())
    assert bad.status == "failed" and bad.message == "Insufficient funds"


@pytest.mark.parametrize(
    ("method", "number", "account", "expected"),
    [
        ("till", "5123456", "", ("mobile_money_business", "MPTILL", "5123456")),
        ("paybill", "247247", "0733444555", ("mobile_money_business", "MPPAYBILL", "247247")),
        ("pochi", "254722118404", "", ("mobile_money", "MPESA", "0722118404")),
        ("send_money", "254733902117", "", ("mobile_money", "MPESA", "0733902117")),
    ],
)
def test_transfer_to_each_payout_method(paystack, method, number, account, expected):
    gateway, calls, replies = paystack
    replies.append(FakeResponse({"status": True, "data": {"recipient_code": "RCP_1"}}))
    replies.append(FakeResponse({"status": True, "data": {"status": "success", "transfer_code": "TRF_1"}}))
    result = gateway.transfer(TransferRequest(reference="PO_1", amount=650, method=method, number=number, account=account, name="Shop"))
    recipient, transfer = calls[0]["json"], calls[1]["json"]
    assert (recipient["type"], recipient["bank_code"], recipient["account_number"]) == expected
    assert recipient.get("account_reference") == (account or None)
    assert transfer["amount"] == 65000 and transfer["recipient"] == "RCP_1"
    assert result.status == "paid"


def test_paystack_errors_are_raised_cleanly(paystack):
    gateway, _, replies = paystack
    replies.append(FakeResponse({"status": False, "message": "Invalid key"}, 401))
    with pytest.raises(PaystackError, match="Invalid key"):
        gateway.charge_mpesa(ChargeRequest(reference="X", amount=1, phone="254711222333"))


def test_webhook_signature_and_events(paystack):
    gateway, _, _ = paystack
    raw = json.dumps({"event": "charge.success", "data": {"reference": "ORD_1", "status": "success"}}).encode()
    good = hmac.new(b"sk_test_abc", raw, hashlib.sha512).hexdigest()
    assert gateway.verify_webhook_signature(raw, good)
    assert not gateway.verify_webhook_signature(raw, "forged")
    kind, ref, result = gateway.parse_webhook(json.loads(raw))
    assert (kind, ref, result.status) == ("charge", "ORD_1", "paid")
    assert gateway.parse_webhook({"event": "transfer.failed", "data": {"reference": "PO_1"}})[2].status == "failed"


def test_missing_keys_stop_payments_politely(app, client):
    app.config.update(PAYMENTS_MODE="paystack", PAYSTACK_SECRET_KEY="")
    res = client.post("/api/billing/subscription/pay", json={"phone": "0711222333", "planId": "starter", "signup": True})
    assert res.status_code == 503
