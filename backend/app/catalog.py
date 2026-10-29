"""Product categories shared by every store. `image` is a photo key the frontend knows."""

CATEGORIES = [
    {"id": "cement", "name": "Cement & binding", "image": "cement"},
    {"id": "steel", "name": "Steel & rebar", "image": "rebar"},
    {"id": "roofing", "name": "Roofing", "image": "roofing"},
    {"id": "timber", "name": "Timber", "image": "timber"},
    {"id": "plumbing", "name": "Plumbing", "image": "pipes"},
    {"id": "paint", "name": "Paint", "image": "paint"},
    {"id": "electrical", "name": "Electrical", "image": "wires"},
    {"id": "tools", "name": "Tools", "image": "hammer"},
]

CATEGORY_IDS = {c["id"] for c in CATEGORIES}
