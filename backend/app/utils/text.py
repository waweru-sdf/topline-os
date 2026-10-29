import re
import unicodedata
from datetime import UTC

RESERVED_SLUGS = {"www", "app", "admin", "api", "mail", "static", "dashboard", "login", "start", "s", "help", "support"}


def slugify(text, max_length=40):
    """'Mjengo Hardware & Supplies' -> 'mjengo-hardware-supplies' (same rules as the frontend)."""
    text = unicodedata.normalize("NFKD", str(text or "")).encode("ascii", "ignore").decode()
    text = re.sub(r"[^a-z0-9]+", "-", text.lower().replace("&", " "))
    return text.strip("-")[:max_length].strip("-")


def iso(dt):
    """Datetime -> '2026-10-29T08:00:00.000Z' (UTC, the format the frontend parses)."""
    if dt is None:
        return None
    dt = dt.astimezone(UTC) if dt.tzinfo else dt
    return dt.strftime("%Y-%m-%dT%H:%M:%S.") + f"{dt.microsecond // 1000:03d}Z"
