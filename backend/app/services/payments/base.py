"""The contract every payment provider implements.

The rest of the app only talks to a PaymentGateway, so moving from the
simulation to Paystack (or adding Daraja later) doesn't touch routes or models.
"""
from dataclasses import dataclass, field

PENDING, PAID, FAILED = "pending", "paid", "failed"


@dataclass
class ChargeRequest:
    reference: str  # our unique reference, e.g. "SUB_8F2K..." or "ORD_..."
    amount: int  # whole KES; the customer is prompted for exactly this
    phone: str  # 2547XXXXXXXX, receives the M-PESA prompt
    email: str = ""  # Paystack requires one; we use "<phone>@<root domain>"
    metadata: dict = field(default_factory=dict)


@dataclass
class ChargeResult:
    status: str  # pending | paid | failed
    provider_ref: str | None = None  # M-PESA receipt, e.g. "TJK4H2PQ9X"
    message: str | None = None  # why it failed, in plain words


@dataclass
class TransferRequest:
    reference: str
    amount: int  # whole KES
    method: str  # till | paybill | pochi | send_money
    number: str  # till / paybill number, or 2547XXXXXXXX for Pochi / Send Money
    account: str = ""  # paybill account number
    name: str = ""  # account holder, for the recipient record
    reason: str = ""


class PaymentGateway:
    name = "base"

    def charge_mpesa(self, request: ChargeRequest) -> ChargeResult:
        """Send an M-PESA prompt (STK push) for exactly request.amount. Usually PENDING."""
        raise NotImplementedError

    def verify(self, payment) -> ChargeResult:
        """Ask the provider for the latest status of an existing payment."""
        raise NotImplementedError

    def transfer(self, request: TransferRequest) -> ChargeResult:
        """Send money to a shop's till / paybill / Pochi / phone."""
        raise NotImplementedError

    def verify_webhook_signature(self, raw_body: bytes, signature: str) -> bool:
        raise NotImplementedError

    def parse_webhook(self, payload: dict) -> tuple[str, str, ChargeResult] | None:
        """Return (kind, our reference, result) where kind is "charge" or "transfer"; None to ignore."""
        raise NotImplementedError
