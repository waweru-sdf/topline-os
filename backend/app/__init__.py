"""Topline API — application factory."""
import logging
import os
import re
import time

from flask import Flask, g, request

from .config import Config
from .errors import register_error_handlers
from .extensions import cors, db, migrate


def create_app(config_class=Config):
    app = Flask(__name__, instance_relative_config=True)
    app.config.from_object(config_class)
    os.makedirs(app.instance_path, exist_ok=True)
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")

    db.init_app(app)
    migrate.init_app(app, db)
    _init_cors(app)

    from . import models  # noqa: F401  (registers tables with SQLAlchemy)
    from .api import register_blueprints
    from .cli import register_cli

    register_blueprints(app)
    register_error_handlers(app)
    register_cli(app)
    _init_monitoring(app)

    if app.config["TRUST_PROXY"]:
        from werkzeug.middleware.proxy_fix import ProxyFix

        app.wsgi_app = ProxyFix(app.wsgi_app, x_for=1, x_proto=1, x_host=1)

    if app.config["APP_ENV"] == "production":
        _check_production_settings(app.config)
    elif not app.testing and app.config["SECRET_KEY"].startswith("dev-only"):
        app.logger.warning("Using the development SECRET_KEY. Set SECRET_KEY and JWT_SECRET in .env before going live.")
    return app


def _check_production_settings(cfg):
    """Refuse to start a live server with settings that would let strangers in."""
    problems = []
    for key in ("SECRET_KEY", "JWT_SECRET"):
        if cfg[key].startswith("dev-only") or len(cfg[key]) < 32:
            problems.append(f"{key} must be a long random value (32+ characters).")
    if cfg["EXPOSE_DEV_OTP"]:
        problems.append("EXPOSE_DEV_OTP must be false: it shows login codes on the page.")
    if cfg["SQLALCHEMY_DATABASE_URI"].startswith("sqlite"):
        problems.append("DATABASE_URL must point at PostgreSQL.")
    if cfg["SMS_MODE"] == "africastalking" and not (cfg["AT_USERNAME"] and cfg["AT_API_KEY"]):
        problems.append("SMS_MODE=africastalking needs AT_USERNAME and AT_API_KEY.")
    if cfg["PAYMENTS_MODE"] == "paystack" and not cfg["PAYSTACK_SECRET_KEY"]:
        problems.append("PAYMENTS_MODE=paystack needs PAYSTACK_SECRET_KEY.")
    if problems:
        raise RuntimeError("Not safe to start in production:\n- " + "\n- ".join(problems))
    if cfg["SMS_MODE"] == "simulation":
        logging.getLogger(__name__).warning(
            "SMS is simulated: login codes only appear in the server log (journalctl -u topline-api). "
            "Set SMS_MODE=africastalking so shop owners receive them."
        )


def _init_cors(app):
    """Allow the main site, local dev servers and every store subdomain."""
    root = re.escape(app.config["ROOT_DOMAIN"])
    origins = [
        *app.config["FRONTEND_ORIGINS"],
        re.compile(rf"^https://([a-z0-9-]+\.)?{root}$"),
        re.compile(r"^http://([a-z0-9-]+\.)?localhost(:\d+)?$"),
    ]
    cors.init_app(app, resources={r"/api/*": {"origins": origins}}, supports_credentials=False)


def _init_monitoring(app):
    """Time every API request and count it, for the System health page."""
    from .services import monitoring

    @app.url_value_preprocessor
    def _fresh_request_state(endpoint, values):
        # Runs before blueprint URL processors (which load the store) and before_request.
        # Never let one request see another's caller or store, even if an app context
        # is shared (e.g. in tests); then start timing.
        for key in ("user", "store", "store_role", "permissions"):
            g.pop(key, None)
        g._started = time.perf_counter()

    @app.after_request
    def _count(response):
        if request.method != "OPTIONS" and request.path.startswith("/api/") and not request.path.startswith(("/api/uploads/", "/api/ping")):
            ms = int((time.perf_counter() - g.get("_started", time.perf_counter())) * 1000)
            monitoring.count_request(response.status_code, ms)
        return response
