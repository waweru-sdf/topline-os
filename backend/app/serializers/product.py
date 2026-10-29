def product(p, *, show_cost=False):
    """`show_cost` adds the buying price: owners and managers only, never customers or cashiers."""
    data = {
        "id": p.id,
        "slug": p.slug,
        "name": p.name,
        "categoryId": p.category_id,
        "unit": p.unit,
        "sellPrice": p.sell_price,
        "stock": p.stock,
        "reorderAt": p.reorder_at,
        "image": p.image,
        "description": p.description,
        "altUnits": p.alt_units or [],
        "vatClass": p.vat_class,
        "published": p.published,
    }
    if show_cost:
        data["buyPrice"] = p.buy_price
    return data
