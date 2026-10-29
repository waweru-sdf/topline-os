"""Photo uploads (product pictures, shop logos/covers, Topline site images).

Files are checked by their actual bytes (not the name), limited in size, and
saved under a random name, either on disk (UPLOAD_FOLDER) or in the database
(UPLOAD_STORAGE=database, for hosts whose disk is wiped on restart). The URL is the
same either way: /api/uploads/<id>. The website shrinks photos before uploading.
"""
import os
import secrets

from flask import current_app, request

from ..errors import Invalid
from ..extensions import db
from ..models import Upload

# First bytes of each allowed image type
_SIGNATURES = {
    b"\xff\xd8\xff": ("jpg", "image/jpeg"),
    b"\x89PNG\r\n\x1a\n": ("png", "image/png"),
}


def _detect(head):
    for magic, kind in _SIGNATURES.items():
        if head.startswith(magic):
            return kind
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "webp", "image/webp"
    return None


def save(file_storage, user, store_id=None):
    if file_storage is None or not file_storage.filename:
        raise Invalid("Choose a photo to upload.", "file")
    data = file_storage.read()
    limit = current_app.config["MAX_UPLOAD_MB"] * 1024 * 1024
    if len(data) > limit:
        raise Invalid(f"That photo is too big. Use one under {current_app.config['MAX_UPLOAD_MB']} MB.", "file")
    kind = _detect(data[:16])
    if kind is None:
        raise Invalid("Upload a JPG, PNG or WebP photo.", "file")
    ext, content_type = kind

    upload_id = f"{secrets.token_hex(12)}.{ext}"
    in_database = current_app.config["UPLOAD_STORAGE"] == "database"
    if not in_database:
        folder = current_app.config["UPLOAD_FOLDER"]
        os.makedirs(folder, exist_ok=True)
        with open(os.path.join(folder, upload_id), "wb") as fh:
            fh.write(data)

    db.session.add(Upload(id=upload_id, store_id=store_id, user_id=user.id, content_type=content_type,
                          size=len(data), data=data if in_database else None))
    db.session.commit()
    return {"id": upload_id, "url": url_for_upload(upload_id)}


def url_for_upload(upload_id):
    return f"{request.host_url.rstrip('/')}/api/uploads/{upload_id}"


def load(upload_id):
    """(bytes, content_type) for a picture kept in the database, or None if it's on disk / unknown."""
    row = db.session.execute(db.select(Upload.data, Upload.content_type).where(Upload.id == upload_id)).first()
    if row is None or row.data is None:
        return None
    return row.data, row.content_type
