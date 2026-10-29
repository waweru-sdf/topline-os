"""Phone-number login with 6-digit one-time codes."""
import hashlib
import hmac
import secrets
from datetime import timedelta

from flask import current_app

from ..errors import ApiError, Invalid, NotFound
from ..extensions import db
from ..models import OtpCode, User
from ..models.base import utcnow
from ..utils.phone import normalize_phone
from .sms import SmsError, get_sms


def _hash(phone, code):
    key = current_app.config["SECRET_KEY"].encode()
    return hmac.new(key, f"{phone}:{code}".encode(), hashlib.sha256).hexdigest()


def request_code(raw_phone):
    phone = normalize_phone(raw_phone)
    if not phone:
        raise Invalid("Enter a Safaricom or Airtel number like 0712 345 678.", "phone")

    window_start = utcnow() - timedelta(minutes=15)
    recent = db.session.scalar(
        db.select(db.func.count(OtpCode.id)).where(OtpCode.phone == phone, OtpCode.created_at >= window_start)
    )
    if recent >= current_app.config["OTP_MAX_PER_WINDOW"]:
        raise ApiError("Too many codes requested. Wait 15 minutes and try again.", 429, "phone")

    simulated = current_app.config["SMS_MODE"] == "simulation"
    dev = simulated and current_app.config["EXPOSE_DEV_OTP"]
    from ..seed import demo_phones  # seeded demo accounts use 123456, in development only

    code = "123456" if dev and phone in demo_phones() else f"{secrets.randbelow(1_000_000):06d}"
    ttl = current_app.config["OTP_TTL_MINUTES"]
    try:
        get_sms().send(phone, f"Your Topline code is {code}. It expires in {ttl} minutes. Don't share it.")
    except SmsError as e:
        from .monitoring import record_incident

        record_incident("sms", "Login code SMS failed", str(e), "/api/auth/otp")
        raise ApiError("We couldn't send the code right now. Try again in a minute.", 503, "phone") from e
    db.session.add(OtpCode(phone=phone, code_hash=_hash(phone, code), expires_at=utcnow() + timedelta(minutes=ttl)))
    db.session.commit()

    response = {"phone": phone, "sentTo": raw_phone}
    if dev:
        response["devCode"] = code
    return response


def verify_code(raw_phone, code):
    """Returns the User for a correct, unexpired code."""
    phone = normalize_phone(raw_phone)
    if not phone:
        raise Invalid("Enter your phone number again.", "phone")
    otp = db.session.scalar(
        db.select(OtpCode).where(OtpCode.phone == phone, OtpCode.used.is_(False)).order_by(OtpCode.created_at.desc())
    )
    wrong = ApiError("That code is wrong or has expired. Request a new one.", 401, "code")
    if otp is None or otp.expires_at < utcnow() or otp.attempts >= current_app.config["OTP_MAX_ATTEMPTS"]:
        raise wrong
    if not hmac.compare_digest(otp.code_hash, _hash(phone, str(code or "").strip())):
        otp.attempts += 1
        db.session.commit()
        raise wrong

    otp.used = True
    db.session.commit()
    user = db.session.scalar(db.select(User).filter_by(phone=phone))
    if user is None or not user.is_active:
        raise NotFound("No account for this number yet. Open a store to get started.")
    return user
