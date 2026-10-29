"""Settings, read from environment variables (.env in development)."""
import os
from pathlib import Path

from dotenv import load_dotenv

load_dotenv()  # before any setting is read

BASE_DIR = Path(__file__).resolve().parent.parent


def _bool(name, default=False):
    return os.getenv(name, str(default)).lower() in {"1", "true", "yes", "on"}


def _list(name, default=""):
    return [item.strip() for item in os.getenv(name, default).split(",") if item.strip()]


def _database_url():
    """DATABASE_URL as given by Neon/Render/Heroku-style hosts, pointed at the psycopg driver."""
    url = os.getenv("DATABASE_URL", f"sqlite:///{BASE_DIR / 'instance' / 'topline.db'}")
    for prefix in ("postgres://", "postgresql://"):
        if url.startswith(prefix):
            return "postgresql+psycopg://" + url[len(prefix):]
    return url


def _engine_options():
    # DATABASE_POOL=none opens a connection per request and closes it after. Use it with
    # databases that sleep when idle (Neon free): kept-open connections would keep them awake.
    if os.getenv("DATABASE_POOL", "").lower() == "none":
        from sqlalchemy.pool import NullPool

        return {"poolclass": NullPool}
    return {"pool_pre_ping": True, "pool_recycle": 280}


class Config:
    # "production" turns on safety checks at start-up (see app/__init__.py).
    APP_ENV = os.getenv("APP_ENV", "development")
    # Behind nginx: trust its X-Forwarded-* headers so links use https and the real host.
    TRUST_PROXY = _bool("TRUST_PROXY", False)

    SECRET_KEY = os.getenv("SECRET_KEY", "dev-only-insecure-key-change-me-before-going-live")
    JSON_SORT_KEYS = False

    # Database: SQLite for local work, PostgreSQL in production.
    SQLALCHEMY_DATABASE_URI = _database_url()
    SQLALCHEMY_ENGINE_OPTIONS = _engine_options()

    # Auth
    JWT_SECRET = os.getenv("JWT_SECRET", SECRET_KEY)
    JWT_EXPIRES_HOURS = int(os.getenv("JWT_EXPIRES_HOURS", "72"))
    OTP_TTL_MINUTES = int(os.getenv("OTP_TTL_MINUTES", "10"))
    OTP_MAX_ATTEMPTS = int(os.getenv("OTP_MAX_ATTEMPTS", "5"))
    OTP_MAX_PER_WINDOW = int(os.getenv("OTP_MAX_PER_WINDOW", "5"))  # codes per phone per 15 minutes

    # Who may call the API from a browser. Store subdomains are allowed by pattern.
    ROOT_DOMAIN = os.getenv("ROOT_DOMAIN", "toplinehub.co.ke")
    FRONTEND_ORIGINS = _list("FRONTEND_ORIGINS", "http://localhost:5173,http://localhost:4173")

    # Payments: "simulation" until Paystack keys are added, then "paystack".
    PAYMENTS_MODE = os.getenv("PAYMENTS_MODE", "simulation")
    PAYSTACK_SECRET_KEY = os.getenv("PAYSTACK_SECRET_KEY", "")
    PAYSTACK_PUBLIC_KEY = os.getenv("PAYSTACK_PUBLIC_KEY", "")
    # Simulation: "prompt" waits for you to approve/decline on the on-screen phone;
    # "auto" approves by itself after SIMULATED_PAYMENT_DELAY_SECONDS (used by tests).
    SIMULATION_APPROVAL = os.getenv("SIMULATION_APPROVAL", "prompt")
    SIMULATED_PAYMENT_DELAY_SECONDS = float(os.getenv("SIMULATED_PAYMENT_DELAY_SECONDS", "2"))
    # An unanswered M-PESA prompt is treated as failed after this long (stock is released).
    PAYMENT_TIMEOUT_MINUTES = int(os.getenv("PAYMENT_TIMEOUT_MINUTES", "10"))
    # Topline's cut of online orders before paying the shop (0 = none). Decide before launch.
    PLATFORM_COMMISSION_PERCENT = float(os.getenv("PLATFORM_COMMISSION_PERCENT", "0"))

    # SMS: "simulation" prints messages to the server log; "africastalking" sends real SMS.
    SMS_MODE = os.getenv("SMS_MODE", "simulation")
    AT_USERNAME = os.getenv("AT_USERNAME", "")  # "sandbox" uses Africa's Talking's test environment
    AT_API_KEY = os.getenv("AT_API_KEY", "")
    AT_SENDER_ID = os.getenv("AT_SENDER_ID", "")  # approved sender name, e.g. TOPLINE (optional)

    # Shop days (dashboard grouping) follow Kenyan time.
    TIMEZONE = os.getenv("TIMEZONE", "Africa/Nairobi")

    # Subscriptions
    GRACE_DAYS = int(os.getenv("GRACE_DAYS", "7"))

    # Show the OTP in API responses and give demo accounts the code 123456 (never in production).
    EXPOSE_DEV_OTP = _bool("EXPOSE_DEV_OTP", True)

    # Uploaded photos (product pictures, site images). "disk" saves files in UPLOAD_FOLDER;
    # "database" keeps them in the database, for hosts whose disk is wiped on restart (Render free).
    UPLOAD_STORAGE = os.getenv("UPLOAD_STORAGE", "disk")
    UPLOAD_FOLDER = os.getenv("UPLOAD_FOLDER", str(BASE_DIR / "instance" / "uploads"))
    MAX_CONTENT_LENGTH = 6 * 1024 * 1024  # 6 MB per request
    MAX_UPLOAD_MB = 5

    # Alerts when something breaks. ALERT_WEBHOOK_URL accepts a Slack, Discord or Teams
    # incoming-webhook URL; ALERT_SMS_PHONES gets an SMS (simulated until SMS is live).
    ALERT_WEBHOOK_URL = os.getenv("ALERT_WEBHOOK_URL", "")
    ALERT_SMS_PHONES = _list("ALERT_SMS_PHONES", "")

    # Background jobs over HTTP, for hosts without timers: GET /api/cron/run?key=CRON_SECRET
    # every few minutes (e.g. from Hostinger's Cron Jobs). Empty = the endpoint is switched off.
    CRON_SECRET = os.getenv("CRON_SECRET", "")


class TestConfig(Config):
    TESTING = True
    APP_ENV = "test"
    # Set TEST_DATABASE_URL to run the tests against PostgreSQL.
    SQLALCHEMY_DATABASE_URI = os.getenv("TEST_DATABASE_URL", "sqlite://")
    SQLALCHEMY_ENGINE_OPTIONS = {}
    SMS_MODE = "simulation"
    EXPOSE_DEV_OTP = True
    PAYMENTS_MODE = "simulation"
    SIMULATED_PAYMENT_DELAY_SECONDS = 0
    SIMULATION_APPROVAL = "auto"
    UPLOAD_FOLDER = str(BASE_DIR / "instance" / "test-uploads")
    JWT_SECRET = "test-secret-that-is-long-enough-for-hs256"
    OTP_MAX_PER_WINDOW = 100
