"""Command-line tasks: seed, init-db, billing-sweep, payments-sweep, create-admin."""
import click

from .extensions import db
from .models import User
from .models.user import PLATFORM_ADMIN
from .utils.phone import normalize_phone


def register_cli(app):
    @app.cli.command("seed")
    @click.option("--reset", is_flag=True, help="Delete everything first.")
    def seed(reset):
        """Create tables and load demo data."""
        from .seed import run

        click.echo(run(reset=reset))

    @app.cli.command("init-db")
    def init_db():
        """Set up a fresh live database: tables + price plans, no demo data. Does nothing to an existing one."""
        from flask_migrate import stamp

        from .models import Plan
        from .seed import PLANS

        if db.inspect(db.engine).get_table_names():
            click.echo("Database already set up. For changes after an update use: flask --app wsgi db upgrade")
            return
        db.create_all()
        db.session.add_all(Plan(**p) for p in PLANS)
        db.session.commit()
        stamp()  # the new tables match the latest migration, so later `db upgrade` only applies new changes
        click.echo(f"Database ready with {len(PLANS)} plans. Next: flask --app wsgi create-admin \"Your Name\" 07XXXXXXXX")

    @app.cli.command("billing-sweep")
    def billing_sweep():
        """Mark overdue stores past due, then suspend after the grace period. Run daily (cron)."""
        from .services.billing import sweep

        click.echo(f"{sweep()} store(s) changed status.")

    @app.cli.command("payments-sweep")
    def payments_sweep():
        """Settle M-PESA prompts nobody is waiting on any more and release stock. Run every 5 minutes."""
        from .services.payment_records import sweep_pending

        click.echo(f"{sweep_pending()} payment(s) settled.")

    @app.cli.command("ensure-admins")
    def ensure_admins():
        """Make the people in ADMIN_PHONES ("Felix:0712000000,Jane:0711000000") Topline admins.

        For hosts without a command line (Render free): runs on every start, changes nothing
        for people who are already admins.
        """
        import os

        for entry in filter(None, (e.strip() for e in os.getenv("ADMIN_PHONES", "").split(","))):
            name, _, phone = entry.rpartition(":")
            number = normalize_phone(phone)
            if not number or not name.strip():
                click.echo(f"Skipped {entry!r}: use Name:07XXXXXXXX")
                continue
            user = db.session.scalar(db.select(User).filter_by(phone=number))
            if user is None:
                user = User(phone=number, name=name.strip())
                db.session.add(user)
            if user.role != PLATFORM_ADMIN:
                user.role = PLATFORM_ADMIN
                click.echo(f"{user.name} ({number}) is a Topline admin.")
        db.session.commit()

    @app.cli.command("create-admin")
    @click.argument("name")
    @click.argument("phone")
    def create_admin(name, phone):
        """Add a Topline platform admin who logs in with PHONE."""
        number = normalize_phone(phone)
        if not number:
            raise click.BadParameter("Use a Kenyan mobile like 0712 345 678.")
        user = db.session.scalar(db.select(User).filter_by(phone=number)) or User(phone=number, name=name)
        user.name, user.role = name, PLATFORM_ADMIN
        db.session.add(user)
        db.session.commit()
        click.echo(f"{name} ({number}) is a Topline admin.")
