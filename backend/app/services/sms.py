"""Sending SMS (login codes, order alerts, problem alerts).

SMS_MODE=simulation   messages go to the server log (development)
SMS_MODE=africastalking   real SMS through Africa's Talking: set AT_USERNAME, AT_API_KEY
                          and optionally AT_SENDER_ID. AT_USERNAME=sandbox uses their test
                          environment (messages show in the sandbox simulator, not on phones).
"""
import logging

import requests
from flask import current_app

log = logging.getLogger(__name__)


class SmsError(Exception):
    pass


class SimulatedSms:
    def send(self, phone, message):
        log.info("[simulated SMS to %s] %s", phone, message)


class AfricasTalkingSms:
    LIVE = "https://api.africastalking.com/version1/messaging"
    SANDBOX = "https://api.sandbox.africastalking.com/version1/messaging"

    def __init__(self, username, api_key, sender_id="", timeout=15):
        if not username or not api_key:
            raise SmsError("SMS_MODE=africastalking needs AT_USERNAME and AT_API_KEY in .env.")
        self.username, self.api_key, self.sender_id, self.timeout = username, api_key, sender_id, timeout

    def send(self, phone, message):
        data = {"username": self.username, "to": f"+{phone.lstrip('+')}", "message": message}
        if self.sender_id:
            data["from"] = self.sender_id
        url = self.SANDBOX if self.username == "sandbox" else self.LIVE
        try:
            res = requests.post(url, data=data, timeout=self.timeout,
                                headers={"apiKey": self.api_key, "Accept": "application/json"})
        except requests.RequestException as e:
            raise SmsError(f"Couldn't reach Africa's Talking: {e}") from e
        if res.status_code >= 400:
            raise SmsError(f"Africa's Talking said {res.status_code}: {res.text[:200]}")
        try:
            recipients = res.json()["SMSMessageData"]["Recipients"]
        except (ValueError, KeyError, TypeError) as e:
            raise SmsError(f"Unexpected reply from Africa's Talking: {res.text[:200]}") from e
        failed = [r for r in recipients if r.get("status") != "Success"]
        if not recipients or failed:
            status = failed[0].get("status") if failed else "no recipients"
            raise SmsError(f"SMS to {phone} not sent: {status}")
        log.info("SMS sent to %s (%s)", phone, recipients[0].get("messageId"))


def get_sms():
    cfg = current_app.config
    if cfg["SMS_MODE"] == "africastalking":
        return AfricasTalkingSms(cfg["AT_USERNAME"], cfg["AT_API_KEY"], cfg["AT_SENDER_ID"])
    return SimulatedSms()
