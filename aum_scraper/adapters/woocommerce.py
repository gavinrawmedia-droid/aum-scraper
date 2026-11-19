"""WooCommerce shops publish their catalogue through the WooCommerce Store API at
/wp-json/wc/store/v1/products. It needs no key and returns JSON, so no HTML scraping.

Dealer settings:
  categories   - product category slugs that hold the used / ex-demo stock, e.g.
                 ["used-mobility-scooters", "used-power-wheelchairs"]
  include      - optional title_matches / title_excludes rules, as for Shopify
"""
from __future__ import annotations

import re

from ..fetch import BlockedByRobots, Fetcher
from ..normalise import html_to_text

PAGE_SIZE = 100   # the Store API's largest page


def fetch(dealer: dict, fetcher: Fetcher) -> dict:
    base = dealer["website"].rstrip("/")
    products, warnings = {}, []
    for cat in dealer.get("categories", []):
        page = 1
        while True:
            url = f"{base}/wp-json/wc/store/v1/products?category={cat}&per_page={PAGE_SIZE}" + (
                f"&page={page}" if page > 1 else "")
            try:
                batch = fetcher.get_json(url)
            except BlockedByRobots:
                warnings.append(f"robots.txt blocks {url}; ask the dealer for a feed")
                break
            if not isinstance(batch, list):
                warnings.append(f"unexpected reply from {url}")
                break
            for p in batch:
                products[p["id"]] = p
            if len(batch) < PAGE_SIZE or page >= 20:
                break
            page += 1
    return {"products": list(products.values()), "warnings": warnings}


def from_fixture(fixture: dict, dealer: dict | None = None) -> dict:
    return {"products": fixture.get("products", []), "warnings": []}


def _money(prices: dict, key: str) -> float | None:
    raw = (prices or {}).get(key)
    if raw in (None, ""):
        return None
    try:
        return int(raw) / 10 ** int(prices.get("currency_minor_unit", 2))
    except (TypeError, ValueError):
        return None


def _included(dealer: dict, title: str) -> bool:
    rule = dealer.get("include") or {}
    if "title_matches" in rule and not re.search(rule["title_matches"], title, re.I):
        return False
    if "title_excludes" in rule and re.search(rule["title_excludes"], title, re.I):
        return False
    return True


def parse(dealer: dict, raw: dict) -> list[dict]:
    out = []
    for p in raw["products"]:
        title = re.sub(r"\s+", " ", html_to_text(p.get("name") or "")).strip()
        if not title or not _included(dealer, title):
            continue
        if not p.get("is_in_stock") or not p.get("is_purchasable", True):
            continue  # sold or not for sale
        prices = p.get("prices") or {}
        currency = prices.get("currency_code", "GBP")
        if currency != "GBP":
            continue  # the site shows pounds only
        price = _money(prices, "price")
        regular = _money(prices, "regular_price")
        rng = prices.get("price_range") or {}
        low, high = _money(rng, "min_amount"), _money(rng, "max_amount")
        if low:
            price = low
        cats = [c.get("name", "") for c in p.get("categories") or []]
        tags = [t.get("name", "") for t in p.get("tags") or []] + cats
        out.append({
            "source_id": str(p["id"]),
            "title": title,
            "url": p.get("permalink") or "",
            "price": price,
            "was_price": regular if (regular and price and regular > price * 1.02) else None,
            "text": html_to_text((p.get("description") or "") + "\n" + (p.get("short_description") or "")),
            "tags": tags,
            "product_type": " ".join(cats),
            "vendor": " ".join(b.get("name", "") for b in p.get("brands") or []),
            "variant_titles": [],
            "price_varies": bool(low and high and high > low),
            "sold_new_too": False,
            "price_basis": None,
            "images": [i["src"] for i in (p.get("images") or []) if i.get("src")][:6],
            "source_updated_at": None,
            "extra_category": "",
        })
    return out
