"""Shopify stores publish their catalogue as JSON at /products.json and
/collections/<handle>/products.json. No HTML scraping needed."""
from __future__ import annotations

import re

from ..fetch import BlockedByRobots, Fetcher
from ..normalise import html_to_text, money

PAGE_SIZE = 250


def fetch(dealer: dict, fetcher: Fetcher) -> dict:
    base = dealer["website"].rstrip("/")
    paths = (["/products.json"] if dealer.get("whole_store")
             else [f"/collections/{h}/products.json" for h in dealer.get("collections", [])])
    products, warnings = {}, []
    for path in paths:
        page = 1
        while True:
            # Page 1 without a page= parameter: some stores' robots.txt blocks "?page=".
            url = f"{base}{path}?limit={PAGE_SIZE}" + (f"&page={page}" if page > 1 else "")
            try:
                data = fetcher.get_json(url)
            except BlockedByRobots:
                warnings.append(f"robots.txt blocks {url} - only the first {PAGE_SIZE} items were read; "
                                "ask the dealer for a feed")
                break
            batch = data.get("products", [])
            for p in batch:
                products[p["id"]] = p
            if len(batch) < PAGE_SIZE or page >= 20:
                break
            page += 1
    return {"products": list(products.values()), "warnings": warnings}


def from_fixture(fixture: dict) -> dict:
    products = {}
    for items in fixture.get("collections", {}).values():
        for p in items:
            products[p["id"]] = p
    return {"products": list(products.values()), "warnings": []}


def _included(dealer: dict, p: dict) -> bool:
    rule = dealer.get("include") or {}
    if "product_type" in rule and p.get("product_type") not in rule["product_type"]:
        return False
    if "text_matches" in rule:
        hay = " ".join([p.get("title", ""), " ".join(p.get("tags", [])), p.get("product_type", "")])
        if not re.search(rule["text_matches"], hay, re.I):
            return False
    return True


def parse(dealer: dict, raw: dict) -> list[dict]:
    base = dealer["website"].rstrip("/")
    out = []
    for p in raw["products"]:
        if not _included(dealer, p):
            continue
        variants = p.get("variants") or []
        live = [v for v in variants if v.get("available")]
        if not live:
            continue  # sold or hidden
        cheapest = min(live, key=lambda v: money(v.get("price")) or 1e9)
        price = money(cheapest.get("price"))
        was = money(cheapest.get("compare_at_price"))
        tags = p.get("tags") or []
        if isinstance(tags, str):
            tags = [t.strip() for t in tags.split(",") if t.strip()]
        out.append({
            "source_id": str(p["id"]),
            "title": (p.get("title") or "").strip(),
            "url": f"{base}/products/{p['handle']}",
            "price": price,
            "was_price": was if (was and price and was > price * 1.02) else None,
            "text": p.get("body_text") or html_to_text(p.get("body_html")),
            "tags": tags,
            "product_type": p.get("product_type") or "",
            "vendor": p.get("vendor") or "",
            "variant_titles": [v.get("title", "") for v in variants if v.get("title") != "Default Title"],
            "images": [i["src"] for i in (p.get("images") or []) if i.get("src")][:6],
            "source_updated_at": p.get("updated_at"),
            "extra_category": "",
        })
    return out
