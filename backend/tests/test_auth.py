from tests.conftest import login


def test_owner_logs_in_with_code(client):
    data = login(client, "0712 345 678")
    assert data["user"]["role"] == "vendor"
    assert data["user"]["storeId"]
    assert data["user"]["storeRole"] == "owner"
    assert client.get("/api/auth/me", headers={"Authorization": f"Bearer {data['token']}"}).status_code == 200


def test_wrong_code_is_rejected(client):
    client.post("/api/auth/otp", json={"phone": "0712 345 678"})
    res = client.post("/api/auth/otp/verify", json={"phone": "0712 345 678", "code": "000000"})
    assert res.status_code == 401
    assert res.json["field"] == "code"


def test_unknown_number_has_no_account(client):
    res = client.post("/api/auth/otp", json={"phone": "0799 888 777"})
    code = res.json["devCode"]
    res = client.post("/api/auth/otp/verify", json={"phone": "0799 888 777", "code": code})
    assert res.status_code == 404


def test_bad_phone_is_a_field_error(client):
    res = client.post("/api/auth/otp", json={"phone": "12345"})
    assert res.status_code == 422
    assert res.json["field"] == "phone"


def test_requests_without_token_are_refused(client, mjengo):
    assert client.get(f"/api/vendor/stores/{mjengo.id}").status_code == 401
    assert client.get("/api/admin/stores").status_code == 401
