"""Editable content for the main Topline site, managed from the Topline admin.

Image values are either a stock-photo key the frontend knows (e.g. "heroShelves")
or a full URL from an upload.
"""
from ..errors import Invalid
from ..extensions import db
from ..models import SiteSetting

DEFAULTS = {
    "heroTitle": "Your hardware shop, online, with its own link.",
    "heroText": "Customers browse your cement, steel and mabati and pay by M-PESA. You run the counter, stock and credit from the same place.",
    "heroImage": "heroShelves",
    "featureImageStore": "storefront",
    "featureImageTill": "toolWall",
    "featureImageStock": "aisle",
    "signupImage": "ownerPhone",
    "announcement": "",  # optional banner across the top of the main site
    "supportPhone": "0700 000 000",
    "supportWhatsapp": "254700000000",
    "supportEmail": "hello@toplinehub.co.ke",
}
MAX_LENGTH = {"heroTitle": 120, "heroText": 300, "announcement": 200}


def get_all():
    saved = {row.key: row.value for row in db.session.scalars(db.select(SiteSetting))}
    return {**DEFAULTS, **{k: v for k, v in saved.items() if k in DEFAULTS}}


def update(data):
    unknown = set(data) - set(DEFAULTS)
    if unknown:
        raise Invalid(f"Unknown setting: {', '.join(sorted(unknown))}.")
    for key, value in data.items():
        value = "" if value is None else str(value).strip()
        if len(value) > MAX_LENGTH.get(key, 500):
            raise Invalid(f"Keep this under {MAX_LENGTH.get(key, 500)} characters.", key)
        row = db.session.get(SiteSetting, key)
        if row is None:
            db.session.add(SiteSetting(key=key, value=value))
        else:
            row.value = value
    db.session.commit()
    return get_all()
