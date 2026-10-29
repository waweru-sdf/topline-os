"""Entry point: `flask --app wsgi run` in development, `gunicorn wsgi:app` in production."""
from app import create_app

app = create_app()
