"""Every route lives under /api."""
from . import admin, auth, billing, cron, media, public, signup, simulator, storefront, webhooks
from .vendor import bp as vendor_bp
from .vendor import index_bp as vendor_index_bp

BLUEPRINTS = [
    public.bp, auth.bp, signup.bp, billing.bp, storefront.bp, media.bp, simulator.bp,
    vendor_index_bp, vendor_bp, admin.bp, webhooks.bp, cron.bp,
]


def register_blueprints(app):
    for bp in BLUEPRINTS:
        app.register_blueprint(bp, url_prefix=f"/api{bp.url_prefix or ''}")
