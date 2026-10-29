"""Demo data: plans, admins, seven hardware shops with products, customers,
two weeks of sales and a month of visits. Every phone logs in with code 123456
while SMS_MODE=simulation (see DEMO_PHONES in services/otp.py).

The full list of logins and links is printed by `flask seed` and in the README.
"""
import random
from datetime import date, timedelta

from .extensions import db
from .models import Customer, PageViews, Payout, Plan, Product, Sale, SaleItem, Store, StoreMember, User
from .models.base import utcnow
from .models.store import ACTIVE, DRAFT, PAST_DUE, SUSPENDED
from .models.user import OWNER, PLATFORM_ADMIN, VENDOR
from .utils.text import slugify

PLANS = [
    dict(id="starter", name="Starter", price_monthly=1500, price_yearly=15000, sort_order=1,
         blurb="One shop, your own store link, and a till for the counter.",
         limits={"products": 150, "staff": 2, "branches": 1},
         features=["Online store at yourshop.toplinehub.co.ke", "Counter sales in bags, lengths and kg", "M-PESA and cash payments", "Stock levels and low-stock alerts", "WhatsApp receipts"]),
    dict(id="business", name="Business", price_monthly=3500, price_yearly=35000, sort_order=2, popular=True,
         blurb="For busy shops that sell on credit and give quotations.",
         limits={"products": 1500, "staff": 8, "branches": 2},
         features=["Everything in Starter", "Customer credit and statements", "Quotations that turn into sales", "Supplier balances", "Staff roles that hide buying prices", "Sales and profit reports"]),
    dict(id="pro", name="Pro", price_monthly=7500, price_yearly=75000, sort_order=3,
         blurb="Several branches, your own domain, and priority support.",
         limits={"products": None, "staff": 25, "branches": 5},
         features=["Everything in Business", "Your own domain (yourshop.co.ke)", "Up to 5 branches", "eTIMS invoices (coming soon)", "Priority WhatsApp support"]),
]

ADMINS = [("Topline Admin", "254700000000"), ("Felix", "254712000000")]

# Product rows: (name, category, unit, sell, buy, stock, photo, description, reorder_at)
CEMENT = [
    ("Bamburi Tembo cement 32.5R", "cement", "bag (50 kg)", 780, 690, 420, "cement", "General purpose cement for blockwork, plastering and mortar.", 80),
    ("Simba cement 42.5N", "cement", "bag (50 kg)", 820, 735, 64, "cementStack", "Higher strength cement for slabs, columns and beams.", 80),
    ("Savannah cement 42.5R", "cement", "bag (50 kg)", 800, 715, 300, "cementStack", "Fast-setting cement for structural concrete.", 60),
    ("Tile adhesive", "cement", "bag (20 kg)", 950, 780, 38, "cement", "Cement-based adhesive for floor and wall tiles.", 10),
    ("Machine-cut stone 9×9", "cement", "piece", 62, 48, 5200, "cementStack", "Nyeri machine-cut building stone, 9 by 9 inches.", 1000),
    ("Ballast (crushed stone)", "cement", "tonne", 2600, 2100, 80, "cementStack", "Clean 3/4 inch ballast for concrete.", 20),
    ("River sand", "cement", "tonne", 2200, 1700, 120, "cement", "Sieved sand for plastering and concrete.", 20),
]
STEEL = [
    ("D12 deformed bar (Y12)", "steel", "length (12 m)", 1540, 1360, 210, "rebar", "High-yield 12 mm ribbed bar for columns, beams and footings.", 50),
    ("D10 deformed bar (Y10)", "steel", "length (12 m)", 1080, 950, 9, "rebarStack", "10 mm ribbed bar for ring beams and lintels.", 40),
    ("D16 deformed bar (Y16)", "steel", "length (12 m)", 2750, 2440, 140, "rebar", "16 mm bar for heavy beams and suspended slabs.", 30),
    ("R8 round bar", "steel", "length (12 m)", 480, 410, 260, "rebarStack", "Mild steel 8 mm for stirrups and rings.", 60),
    ("BRC mesh A142", "steel", "sheet (2.4 × 4.8 m)", 9800, 8700, 22, "rebarStack", "Welded mesh for ground slabs and driveways.", 10),
    ("Binding wire", "steel", "roll (20 kg)", 3400, 2900, 45, "rebar", "Annealed tie wire for rebar work.", 10),
]
ROOFING = [
    ("Box profile mabati, gauge 30", "roofing", "metre", 890, 760, 640, "roofing", "Pre-painted charcoal grey sheet, cut to your length.", 150),
    ("Corrugated iron sheet, gauge 28", "roofing", "sheet (3 m)", 1450, 1220, 300, "roofingSky", "Galvanised corrugated sheet for roofs and stores.", 50),
    ("Ridge cap, gauge 30", "roofing", "piece (2 m)", 650, 540, 75, "roofingSky", "Matching ridge cap for box profile roofs.", 10),
    ("Roofing nails", "roofing", "kg", 320, 250, 90, "roofing", "Umbrella-head nails for iron sheets.", 20),
]
TIMBER = [
    ("Cypress timber 2×4", "timber", "foot", 75, 58, 1800, "timber", "Treated cypress for rafters and trusses.", 400),
    ("Cypress timber 3×2", "timber", "foot", 55, 42, 2300, "planks", "For purlins, battens and formwork.", 400),
    ("Pine timber 4×2", "timber", "foot", 68, 52, 1500, "planks", "Kiln-dried pine for trusses and frames.", 300),
    ("Marine plywood 18 mm", "timber", "sheet (8 × 4 ft)", 4800, 4100, 40, "planks", "Water-resistant ply for formwork and furniture.", 10),
    ("Blockboard 18 mm", "timber", "sheet (8 × 4 ft)", 3900, 3300, 35, "timber", "For doors, shelving and cabinets.", 8),
]
PLUMBING = [
    ("PPR pipe 20 mm", "plumbing", "length (4 m)", 450, 360, 140, "pipes", "Hot and cold water pipe, PN20.", 10),
    ("PVC pipe 4\" heavy", "plumbing", "length (6 m)", 1650, 1380, 6, "pipesWall", "Class B drainage pipe for soil and waste.", 15),
    ("PVC pipe 2\"", "plumbing", "length (6 m)", 720, 590, 90, "pipes", "Waste and vent pipe.", 15),
    ("Water tank 1,000 L", "plumbing", "piece", 9500, 8200, 12, "pipesWall", "UV-stabilised plastic tank.", 3),
    ("Gate valve 1\"", "plumbing", "piece", 1100, 850, 40, "pipes", "Brass gate valve for main supply.", 8),
]
PAINT = [
    ("Vinyl silk emulsion, white", "paint", "tin (4 L)", 2350, 1950, 56, "paint", "Washable interior wall paint.", 10),
    ("Vinyl silk emulsion, white", "paint", "drum (20 L)", 10800, 9100, 14, "paintShelf", "Washable interior wall paint, contractor size.", 4),
    ("Gloss enamel, brilliant white", "paint", "tin (1 L)", 980, 790, 34, "paintShelf", "For doors, windows and metalwork.", 10),
    ("Weatherguard exterior paint", "paint", "tin (4 L)", 3200, 2650, 28, "paint", "For outside walls; resists rain and sun.", 6),
    ("Paint roller 9\"", "paint", "piece", 450, 300, 60, "rollers", "Medium-pile roller for emulsion.", 10),
    ("Undercoat, white", "paint", "tin (4 L)", 1900, 1550, 20, "paintShelf", "Primer before gloss or emulsion.", 5),
]
ELECTRICAL = [
    ("Twin & earth cable 2.5 mm", "electrical", "metre", 120, 92, 900, "wires", "Socket circuits. Also sold as a 100 m roll.", 10),
    ("Single cable 1.5 mm", "electrical", "roll (100 m)", 4200, 3500, 25, "cable", "Lighting circuits.", 5),
    ("Double socket 13 A", "electrical", "piece", 450, 310, 80, "wires", "Switched double socket with neon.", 15),
]
TOOLS = [
    ("Claw hammer, 16 oz", "tools", "piece", 650, 470, 18, "hammer", "Fibreglass handle, forged head.", 10),
    ("Cordless drill 18 V", "tools", "piece", 9800, 8200, 4, "drill", "With two batteries and charger.", 3),
    ("Mason trowel", "tools", "piece", 380, 260, 0, "toolsTable", "Carbon steel blade, wooden handle.", 5),
    ("Wheelbarrow, heavy duty", "tools", "piece", 6500, 5300, 9, "toolRack", "Steel tray, puncture-proof wheel.", 3),
    ("Spirit level 600 mm", "tools", "piece", 900, 640, 25, "toolsTable", "Aluminium level with three vials.", 5),
]

Z = lambda *zones: [{"id": f"z{i + 1}", "name": n, "fee": f} for i, (n, f) in enumerate(zones)]  # noqa: E731

STORES = [
    dict(slug="mjengo", name="Mjengo Hardware", tagline="Building materials for Kitengela and beyond", county="Kajiado", town="Kitengela",
         address="Namanga Road, opposite Total petrol station", owner=("Peter Kamau", "254712345678"), plan="business", status=ACTIVE,
         age=76, due=16, accent="#F5A623", cover="heroShelves", hours="Mon–Sat 7:00–18:30, Sun 9:00–14:00",
         payout=("till", "5123456", ""), delivery=("zones", Z(("Kitengela town", 300), ("EPZ / Athi River", 600), ("Isinya", 900)), 50000),
         products=CEMENT[:4] + STEEL[:3] + ROOFING[:3] + TIMBER[:2] + PLUMBING[:2] + PAINT[:1] + PAINT[2:3] + ELECTRICAL[:1] + TOOLS[:3]),
    dict(slug="mwamba", name="Mwamba Hardware", tagline="Strong materials for strong homes", county="Kajiado", town="Ongata Rongai",
         address="Magadi Road, next to Rongai stage", owner=("Juma Mwamba", "254733444555"), plan="pro", status=ACTIVE,
         age=41, due=19, accent="#2E9E5B", cover="toolWall", hours="Mon–Sat 7:30–19:00",
         payout=("paybill", "247247", "0733444555"), delivery=("zones", Z(("Rongai town", 250), ("Kiserian", 500), ("Karen / Langata", 800)), None),
         products=CEMENT + STEEL[:4] + ROOFING[:2] + TOOLS[3:]),
    dict(slug="baraka-builders", name="Baraka Builders Centre", tagline="Nakuru's one-stop building yard", county="Nakuru", town="Nakuru",
         address="Kenyatta Avenue, near Nakumatt Mega", owner=("Grace Wanjiru", "254722118404"), plan="pro", status=ACTIVE,
         age=120, due=4, accent="#1E88E5", cover="storefront", hours="Mon–Sat 8:00–18:00",
         payout=("pochi", "254722118404", ""), delivery=("quote", [], None),
         products=STEEL + TIMBER + ROOFING),
    dict(slug="coast-steel", name="Coast Steel Mart", tagline="Steel, roofing and tools for the coast", county="Mombasa", town="Changamwe",
         address="Magongo Road, Changamwe roundabout", owner=("Fatma Said", "254711450330"), plan="business", status=ACTIVE,
         age=140, due=13, accent="#E4572E", cover="aisle", hours="Mon–Sat 8:00–17:30",
         payout=("till", "8899001", ""), delivery=("zones", Z(("Changamwe / Port Reitz", 300), ("Mombasa Island", 600), ("Nyali / Bamburi", 900)), 80000),
         products=STEEL + ROOFING + TOOLS[:2] + TOOLS[3:]),
    dict(slug="kilimani-paints", name="Kilimani Paints & Tools", tagline="Paint, colour mixing and tools", county="Nairobi", town="Kilimani",
         address="Argwings Kodhek Road", owner=("Ali Hassan", "254733902117"), plan="starter", status=PAST_DUE,
         age=40, due=-3, accent="#8E44AD", cover="paintShelf", hours="Mon–Sat 8:30–18:00",
         payout=("send_money", "254733902117", ""), delivery=("zones", Z(("Kilimani / Hurlingham", 200), ("Westlands / Lavington", 400)), 20000),
         products=PAINT + TOOLS[:1] + TOOLS[4:] + ELECTRICAL),
    dict(slug="rift-timber", name="Rift Timber Yard", tagline="Timber and boards, cut to size", county="Uasin Gishu", town="Eldoret",
         address="Uganda Road industrial area", owner=("Kiprono Rotich", "254720664018"), plan="starter", status=SUSPENDED,
         age=150, due=-29, accent="#0B3A6E", cover="timber", hours="Mon–Sat 7:00–17:00",
         payout=("till", "4455667", ""), delivery=("quote", [], None), products=TIMBER),
    dict(slug="nyeri-plumb", name="Nyeri Plumb Centre", tagline="Pipes, tanks and fittings", county="Nyeri", town="Nyeri",
         address="Kimathi Way", owner=("Mary Muthoni", "254798221560"), plan="business", status=DRAFT,
         age=2, due=None, accent="#1E88E5", cover="pipes", hours="Mon–Sat 8:00–17:00",
         payout=("till", "", ""), delivery=("zones", [], None), products=PLUMBING),
]

MJENGO_CUSTOMERS = [
    ("Otieno Contractors", "0723 441 902", 250000, 184500, -4),
    ("Jane Njeri (fundi)", "0745 220 118", 40000, 12800, 7),
    ("Sunrise Apartments site", "0701 993 270", 500000, 0, None),
    ("Mutua Welding", "0716 552 804", 60000, 58200, -11),
    ("Kitengela Primary School", "0729 104 655", 150000, 36000, 32),
]
BUYERS = [("Wanjiku M.", "254711222333"), ("Daniel K.", "254722333444"), ("Achieng O.", "254733555666"),
          ("Hassan A.", "254744666777"), ("Mercy W.", "254755777888"), ("Kevin O.", "254766888999")]


def demo_phones():
    return {p for _, p in ADMINS} | {s["owner"][1] for s in STORES}


def run(reset=False):
    if reset:
        db.drop_all()
    db.create_all()
    if db.session.scalar(db.select(Plan.id).limit(1)):
        return "Database already has data. Use `flask seed --reset` to start over."

    rand = random.Random(42)  # same demo every time
    now = utcnow()
    db.session.add_all(Plan(**p) for p in PLANS)
    db.session.add_all(User(name=n, phone=p, role=PLATFORM_ADMIN) for n, p in ADMINS)

    for spec in STORES:
        store, products = _create_store(spec, now)
        if store.slug == "mjengo":
            for name, phone, limit, balance, due in MJENGO_CUSTOMERS:
                db.session.add(Customer(store_id=store.id, name=name, phone=phone, credit_limit=limit, balance=balance,
                                        due_at=now + timedelta(days=due) if due is not None else None))
        if store.status in (ACTIVE, PAST_DUE, SUSPENDED):
            _seed_sales(store, products, now, rand, count=64 if store.slug == "mjengo" else rand.randint(25, 45))
            _seed_views(store.id, rand, base=60 if store.status == ACTIVE else 15)
    _seed_views(None, rand, base=180)  # main site
    db.session.commit()
    return summary()


def summary():
    lines = ["Seeded demo data. Login code for every account: 123456", "", "Topline admins: 0700 000 000, 0712 000 000", ""]
    for spec in STORES:
        name, phone = spec["owner"]
        local = f"0{phone[3:6]} {phone[6:9]} {phone[9:]}"
        lines.append(f"{spec['name']:<26} {spec['status']:<10} owner {name:<15} {local}   http://{spec['slug']}.localhost:5173")
    return "\n".join(lines)


def _create_store(spec, now):
    method, number, account = spec["payout"]
    mode, zones, free_over = spec["delivery"]
    owner_name, owner_phone = spec["owner"]
    store = Store(
        slug=spec["slug"], name=spec["name"], tagline=spec["tagline"], county=spec["county"], town=spec["town"],
        address=spec["address"], phone=f"0{owner_phone[3:6]} {owner_phone[6:9]} {owner_phone[9:]}", whatsapp=owner_phone,
        hours=spec["hours"], accent=spec["accent"], cover=spec["cover"],
        payout_method=method, payout_number=number, payout_account=account, payout_name=spec["name"],
        delivery_enabled=bool(zones) or mode == "quote", delivery_mode=mode, delivery_zones=zones, delivery_free_over=free_over,
        delivery_note=("Delivery cost agreed by phone after you order (lorry or pickup depending on size)." if mode == "quote"
                       else "Same-day delivery for orders placed before 2 pm."),
        plan_id=spec["plan"], status=spec["status"], created_at=now - timedelta(days=spec["age"]),
        next_billing_at=now + timedelta(days=spec["due"]) if spec["due"] is not None else None,
    )
    user = User(name=owner_name, phone=owner_phone, role=VENDOR)
    db.session.add_all([store, user])
    db.session.flush()
    db.session.add(StoreMember(store_id=store.id, user_id=user.id, role=OWNER))

    products, used = [], set()
    for name, cat, unit, sell, buy, stock, photo, desc, reorder in spec["products"]:
        slug = slugify(f"{name} {unit}" if slugify(name) in used else name)
        used.add(slugify(name))
        products.append(Product(store_id=store.id, slug=slug, name=name, category_id=cat, unit=unit, sell_price=sell,
                                buy_price=buy, stock=stock, image=photo, description=desc, reorder_at=reorder))
    db.session.add_all(products)
    db.session.flush()
    return store, products


def _seed_sales(store, products, now, rand, count):
    staff = ["Peter K.", "Esther W.", "Brian O."]
    for i in range(count):
        at = now - timedelta(days=rand.randrange(14), hours=rand.randrange(10))
        online = rand.random() < 0.35
        items = []
        for _ in range(1 + rand.randrange(3)):
            prod = rand.choice(products)
            qty = max(1, round(rand.random() * (60 if prod.sell_price < 200 else 12)))
            items.append(SaleItem(product_id=prod.id, name=prod.name, unit=prod.unit, qty=qty, price=prod.sell_price, cost=prod.buy_price))
        subtotal = sum(it.qty * it.price for it in items)
        fee, zone, delivery_type = 0, None, None
        if online:
            delivery_type = "delivery" if store.delivery_enabled and i % 2 else "pickup"
            if delivery_type == "delivery" and store.delivery_zones:
                z = rand.choice(store.delivery_zones)
                zone, fee = z["name"], (0 if store.delivery_free_over and subtotal >= store.delivery_free_over else z["fee"])
        buyer = BUYERS[i % len(BUYERS)]
        sale = Sale(
            store_id=store.id, channel="online" if online else "pos",
            method="mpesa" if online else rand.choice(["mpesa", "mpesa", "mpesa", "cash", "cash", "bank"]),
            payment_mode="prompt" if online else None,
            status=["new", "confirmed", "dispatched", "completed", "completed", "completed"][i % 6] if online else "completed",
            staff_name=None if online else staff[i % 3],
            customer_name=buyer[0] if online else None, customer_phone=buyer[1] if online else None,
            delivery_type=delivery_type, delivery_zone=zone,
            delivery_address=f"{store.town}, near the main road" if delivery_type == "delivery" else None,
            items=items, subtotal=subtotal, delivery_fee=fee, total=subtotal + fee,
            created_at=at, paid_at=at, mpesa_ref=f"TSD{rand.randrange(10**6):06d}" if online else None,
        )
        db.session.add(sale)
        if online:
            db.session.flush()
            db.session.add(Payout(reference=f"PO_SEED_{sale.number}", store_id=store.id, sale_number=sale.number, gross=sale.total,
                                  commission=0, amount=sale.total, method=store.payout_method,
                                  destination=f"{store.payout_method.title()} {store.payout_number}", status="paid",
                                  provider_ref=f"TRF_SEED{sale.number}", created_at=at, paid_at=at))


def _seed_views(store_id, rand, base):
    today = date.today()
    for d in range(30):
        day = today - timedelta(days=d)
        weekend = day.weekday() >= 5
        views = int(base * (0.6 if weekend else 1) * (0.7 + rand.random() * 0.6) * (1 + (30 - d) / 60))
        page = "landing" if store_id is None else "store"
        db.session.add(PageViews(day=day, store_id=store_id, page=page, views=views))
