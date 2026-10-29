"""Topline admin: overview numbers, site content, uploads, monitoring and alerts."""
import io

import pytest

from app.extensions import db
from app.models import Incident

PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64


def test_overview_tallies(client, admin):
    data = client.get("/api/admin/overview", headers=admin["headers"]).json
    assert data["stores"]["byStatus"]["active"] == 4 and data["stores"]["monthlyRevenue"] > 0
    assert data["customers"]["onlineBuyers"] == 6 and data["customers"]["repeatBuyers"] == 6
    assert data["customers"]["creditCustomers"] == 5
    assert len(data["traffic"]["byDay"]) == 30 and data["traffic"]["topStores"][0]["views"] > 0
    assert data["money"]["payouts"]["paid"]["count"] > 0


def test_storefront_visits_are_counted(client, admin):
    before = client.get("/api/admin/overview", headers=admin["headers"]).json["traffic"]["total30"]
    client.get("/api/storefront/mwamba")
    client.get("/api/site?view=landing")
    after = client.get("/api/admin/overview", headers=admin["headers"]).json["traffic"]["total30"]
    assert after == before + 2


def test_site_content_edit(client, admin, owner):
    assert client.get("/api/site").json["heroTitle"].startswith("Your hardware shop")
    assert client.put("/api/admin/site", json={"heroTitle": "Hi"}, headers=owner["headers"]).status_code == 403
    res = client.put("/api/admin/site", json={"heroTitle": "Sell online today", "heroImage": "https://x/y.jpg"}, headers=admin["headers"])
    assert res.json["heroTitle"] == "Sell online today"
    assert client.get("/api/site").json["heroImage"] == "https://x/y.jpg"
    assert client.put("/api/admin/site", json={"nope": 1}, headers=admin["headers"]).status_code == 422


def test_vendor_uploads_product_photo(client, owner):
    data = {"file": (io.BytesIO(PNG), "bags.png"), "storeId": owner["store_id"]}
    res = client.post("/api/uploads", data=data, headers=owner["headers"], content_type="multipart/form-data")
    assert res.status_code == 201, res.json
    url = res.json["url"]
    assert url.endswith(".png")
    served = client.get(url.replace("http://localhost", ""))
    assert served.status_code == 200 and served.data.startswith(b"\x89PNG")


def test_upload_rejects_non_images_and_other_stores(client, owner):
    fake = {"file": (io.BytesIO(b"<script>alert(1)</script>"), "x.png"), "storeId": owner["store_id"]}
    assert client.post("/api/uploads", data=fake, headers=owner["headers"], content_type="multipart/form-data").status_code == 422
    other = {"file": (io.BytesIO(PNG), "x.png")}  # no store: only Topline admins may upload site images
    assert client.post("/api/uploads", data=other, headers=owner["headers"], content_type="multipart/form-data").status_code == 404


def test_server_errors_become_incidents_and_alert(app, client, admin, monkeypatch):
    sent = []
    monkeypatch.setattr("app.services.alerts.send", lambda title, text: sent.append(title))

    def boom(*a, **k):
        raise RuntimeError("database on fire")

    monkeypatch.setattr("app.api.public.check_slug", boom)
    for _ in range(3):
        assert client.get("/api/stores/check-slug?slug=abc").status_code == 500
    incident = db.session.scalar(db.select(Incident).filter_by(kind="server_error"))
    assert incident.count == 3 and "database on fire" in incident.title
    assert len(sent) == 1  # alerted on the first occurrence only (then at 10, 100...)

    listed = client.get("/api/admin/incidents", headers=admin["headers"]).json
    assert listed[0]["count"] == 3
    client.patch(f"/api/admin/incidents/{incident.id}", json={"resolved": True}, headers=admin["headers"])
    assert client.get("/api/admin/incidents", headers=admin["headers"]).json == []
    health = client.get("/api/admin/overview", headers=admin["headers"]).json["health"]
    assert health["serverErrors24"] >= 3 and health["requests24"] > 0


def test_browser_errors_are_reported(client, admin):
    client.post("/api/telemetry/error", json={"message": "TypeError: x is undefined", "path": "/dashboard"})
    kinds = [i["kind"] for i in client.get("/api/admin/incidents", headers=admin["headers"]).json]
    assert "browser" in kinds


@pytest.mark.parametrize("path", ["/api/admin/payments", "/api/admin/payouts", "/api/admin/plans"])
def test_admin_lists(client, admin, path):
    assert client.get(path, headers=admin["headers"]).status_code == 200
