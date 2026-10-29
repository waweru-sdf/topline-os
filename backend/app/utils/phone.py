import re

_KE_MOBILE = re.compile(r"^254(7|1)\d{8}$")


def normalize_phone(value):
    """'0712 345 678' / '+254712345678' / '712345678' -> '254712345678', or None if not a Kenyan mobile."""
    digits = re.sub(r"\D", "", str(value or ""))
    if digits.startswith("0") and len(digits) == 10:
        digits = "254" + digits[1:]
    elif len(digits) == 9 and digits[0] in "17":
        digits = "254" + digits
    return digits if _KE_MOBILE.match(digits) else None


def display_phone(normalized):
    """'254712345678' -> '0712 345 678' for messages and the UI."""
    if not normalized or len(normalized) != 12:
        return normalized or ""
    local = "0" + normalized[3:]
    return f"{local[:4]} {local[4:7]} {local[7:]}"
