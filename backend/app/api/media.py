"""Uploading and serving photos, and the public site content."""
import os

from flask import Blueprint, Response, current_app, request, send_from_directory

from ..auth.guards import current_user, load_store_for_request, require
from ..auth.permissions import MANAGE_PRODUCTS
from ..errors import NotFound
from ..services import monitoring, site, uploads

bp = Blueprint("media", __name__)


@bp.post("/uploads")
def upload_photo():
    """Shop staff upload into their store (form field `storeId`); Topline admins may upload site images."""
    user = current_user()
    store_id = request.form.get("storeId") or None
    if store_id:
        load_store_for_request(store_id)
        require(MANAGE_PRODUCTS)
    elif not user.is_platform_admin:
        raise NotFound("That store doesn't exist.")
    return uploads.save(request.files.get("file"), user, store_id), 201


@bp.get("/uploads/<path:name>")
def serve_upload(name):
    if "/" in name:
        raise NotFound("Photo not found.")
    stored = uploads.load(name)
    if stored is not None:
        data, content_type = stored
        response = Response(data, mimetype=content_type)
    else:
        folder = current_app.config["UPLOAD_FOLDER"]
        if not os.path.isfile(os.path.join(folder, name)):
            raise NotFound("Photo not found.")
        response = send_from_directory(folder, name, max_age=60 * 60 * 24 * 365)
    # Names are random and never reused, so browsers and CDNs may keep them forever.
    response.headers["Cache-Control"] = "public, max-age=31536000, immutable"
    return response


@bp.get("/site")
def site_content():
    """Main-site content: headline, pictures, contacts. Counts as a landing-page visit."""
    if request.args.get("view") == "landing":
        monitoring.count_view("landing")
    return site.get_all()


@bp.post("/telemetry/error")
def browser_error():
    """The frontend reports crashes here so they show up in System health."""
    data = request.get_json(silent=True) or {}
    message = str(data.get("message") or "Unknown browser error")[:200]
    monitoring.record_incident("browser", message, str(data.get("stack") or "")[:3000], str(data.get("path") or "")[:200])
    return {"ok": True}
