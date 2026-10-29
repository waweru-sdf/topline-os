"""Telling Topline admins that something broke.

Channels (any combination, set in .env):
- ALERT_WEBHOOK_URL: Slack / Discord / Microsoft Teams incoming webhook (works today)
- ALERT_SMS_PHONES:  SMS to these numbers (simulated until SMS is live)
- Always: the server log and the System health page in the Topline admin.
"""
import logging
import threading

import requests
from flask import current_app

from ..utils.phone import normalize_phone
from .sms import get_sms

log = logging.getLogger(__name__)


def send(title, text):
    log.warning("ALERT %s | %s", title, text.replace("\n", " | "))
    app = current_app._get_current_object()
    url = app.config["ALERT_WEBHOOK_URL"]
    if url:
        # Background thread so a slow chat service never slows down a customer's request.
        threading.Thread(target=_post_webhook, args=(url, title, text), daemon=True).start()
    for phone in app.config["ALERT_SMS_PHONES"]:
        try:
            number = normalize_phone(phone)
            if number:
                get_sms().send(number, f"Topline alert. {title}: {text[:120]}")
        except Exception:  # noqa: BLE001  alerts must never raise
            log.exception("Alert SMS failed")


def _post_webhook(url, title, text):
    # "text" works for Slack and Teams; "content" for Discord.
    try:
        requests.post(url, json={"text": f"*{title}*\n{text}", "content": f"**{title}**\n{text}"}, timeout=5)
    except requests.RequestException:
        log.exception("Alert webhook failed")
