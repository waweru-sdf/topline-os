from datetime import timedelta
from app import create_app
from app.extensions import db
from app.models import Product, Store, StoreMember, User
from app.models.base import utcnow

app = create_app()
with app.app_context():
    if db.session.scalar(db.select(Store).filter_by(slug="mwamba")):
        print("Mwamba already exists: http://mwamba.localhost:5173")
    else:
        store = Store(slug="mwamba", name="Mwamba Hardware", tagline="Strong materials for strong homes",
                      county="Nairobi", town="Kitengela Road, Rongai", address="Next to Rongai stage",
                      phone="0733 444 555", whatsapp="254733444555", accent="#2E9E5B", cover="toolWall",
                      delivery_enabled=True, delivery_note="Delivery within Rongai and Kitengela, same day before 3 pm.",
                      mpesa_till="7654321", plan_id="business", status="active",
                      next_billing_at=utcnow() + timedelta(days=30))
        owner = User(name="Juma Mwamba", phone="254733444555", role="vendor")
        db.session.add_all([store, owner]); db.session.flush()
        db.session.add(StoreMember(store_id=store.id, user_id=owner.id, role="owner"))
        demo = db.session.scalar(db.select(Store).filter_by(slug="mjengo"))
        for p in db.session.scalars(db.select(Product).filter_by(store_id=demo.id)).all()[:12]:
            db.session.add(Product(store_id=store.id, slug=p.slug, name=p.name, category_id=p.category_id, unit=p.unit,
                                   sell_price=p.sell_price, buy_price=p.buy_price, stock=max(p.stock, 25),
                                   image=p.image, description=p.description, reorder_at=p.reorder_at, alt_units=p.alt_units))
        db.session.commit()
        print("Created Mwamba Hardware: http://mwamba.localhost:5173  (owner login 0733 444 555)")
