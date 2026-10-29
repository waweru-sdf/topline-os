"""Small helpers for reading and validating JSON bodies with friendly errors."""
from flask import request

from ..errors import Invalid


def body():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        raise Invalid("Send the request body as JSON.")
    return data


def text(data, key, *, required=False, max_length=200, message=None, default=""):
    value = data.get(key)
    value = "" if value is None else str(value).strip()
    if required and not value:
        raise Invalid(message or f"Enter {key}.", key)
    if len(value) > max_length:
        raise Invalid(f"Keep this under {max_length} characters.", key)
    return value or default


def integer(data, key, *, required=False, minimum=None, message=None, default=0):
    value = data.get(key)
    if value in (None, ""):
        if required:
            raise Invalid(message or f"Enter {key}.", key)
        return default
    try:
        number = int(round(float(value)))
    except (TypeError, ValueError):
        raise Invalid(message or "Enter a number.", key) from None
    if minimum is not None and number < minimum:
        raise Invalid(message or f"Enter a number of at least {minimum}.", key)
    return number


def choice(data, key, options, *, default=None):
    value = data.get(key, default)
    if value not in options:
        raise Invalid(f"Pick one of: {', '.join(options)}.", key)
    return value
