from ..errors import Invalid, NotFound
from ..extensions import db
from ..models import Customer, CustomerPayment
from ..utils import request as req
from ..utils.phone import display_phone, normalize_phone


def create_customer(store, data):
    name = req.text(data, "name", required=True, max_length=120, message="Enter the customer name.")
    raw_phone = req.text(data, "phone", max_length=20)
    phone = normalize_phone(raw_phone) if raw_phone else None
    if raw_phone and not phone:
        raise Invalid("Enter a phone number like 0712 345 678, or leave it empty.", "phone")
    customer = Customer(
        store_id=store.id,
        name=name,
        phone=display_phone(phone) if phone else "",
        credit_limit=req.integer(data, "creditLimit", minimum=0),
    )
    db.session.add(customer)
    db.session.commit()
    return customer


def record_payment(store, customer_id, data, user):
    customer = db.session.get(Customer, customer_id)
    if customer is None or customer.store_id != store.id:
        raise NotFound("Customer not found.")
    amount = req.integer(data, "amount", required=True, minimum=1, message="Enter an amount above 0.")
    method = data.get("method") if data.get("method") in ("mpesa", "cash", "bank") else "mpesa"

    db.session.add(CustomerPayment(store_id=store.id, customer_id=customer.id, amount=amount, method=method, recorded_by=user.id))
    customer.balance = max(0, customer.balance - amount)
    if customer.balance == 0:
        customer.due_at = None
    db.session.commit()
    return customer
