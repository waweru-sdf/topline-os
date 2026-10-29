"""One error shape for the whole API: {"message": str, "field": str | None}.

The frontend shows `message` to the user and highlights `field` on forms, so
messages are written for shop owners, not developers.
"""
import logging
import traceback

from flask import jsonify
from werkzeug.exceptions import HTTPException

log = logging.getLogger(__name__)


class ApiError(Exception):
    def __init__(self, message, status=400, field=None):
        super().__init__(message)
        self.message = message
        self.status = status
        self.field = field


class NotFound(ApiError):
    def __init__(self, message="Not found."):
        super().__init__(message, 404)


class Forbidden(ApiError):
    def __init__(self, message="You don't have access to this."):
        super().__init__(message, 403)


class Unauthorized(ApiError):
    def __init__(self, message="Log in to continue."):
        super().__init__(message, 401)


class Invalid(ApiError):
    """422 for a specific form field."""

    def __init__(self, message, field=None):
        super().__init__(message, 422, field)


def register_error_handlers(app):
    @app.errorhandler(ApiError)
    def handle_api_error(err):
        return jsonify(message=err.message, field=err.field), err.status

    @app.errorhandler(HTTPException)
    def handle_http(err):
        return jsonify(message=err.description or err.name, field=None), err.code

    @app.errorhandler(Exception)
    def handle_unexpected(err):
        log.exception("Unhandled error")
        from flask import request

        from .services import monitoring

        monitoring.record_incident(
            "server_error", f"{type(err).__name__}: {str(err)[:150]}", traceback.format_exc()[-3000:],
            f"{request.method} {request.path}", rollback=True,
        )
        return jsonify(message="Something went wrong on our side. Try again in a moment.", field=None), 500
