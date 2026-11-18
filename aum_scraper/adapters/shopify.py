"""Shopify stores publish their catalogue as JSON at /products.json and
/collections/<handle>/products.json. No HTML scraping needed."""
from __future__ import annotations

import re
from urllib.parse import quote

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
        tags = p.get("tags", [])
        hay = " ".join([p.get("title", ""), " ".join(tags) if isinstance(tags, list) else str(tags),
                        p.get("product_type", "")])
        if not re.search(rule["text_matches"], hay, re.I):
            return False
    if "title_matches" in rule and not re.search(rule["title_matches"], p.get("title", ""), re.I):
        return False
    if "title_excludes" in rule and re.search(rule["title_excludes"], p.get("title", ""), re.I):
        return False
    return True


def _variant_text(v: dict) -> str:
    return " ".join(str(v.get(k) or "") for k in ("title", "option1", "option2", "option3"))


def _used_variants(dealer: dict, p: dict, live: list[dict]) -> list[dict] | None:
    """Some dealers sell one product page "reconditioned or new" with a variant for each.
    With used_variants set, keep only the variants that match it. A product with no such
    variant counts only if its own title says it is used; otherwise it is skipped (None)."""
    pattern = dealer.get("used_variants")
    if not pattern:
        return live
    matching = [v for v in live if re.search(pattern, _variant_text(v), re.I)]
    if matching:
        return matching
    return live if re.search(pattern, p.get("title", ""), re.I) else None


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
        all_live = live
        live = _used_variants(dealer, p, live)
        if not live:
            continue  # only sold new
        # Some shops sell each item twice, with and without VAT relief: price by the option named.
        price_basis = None
        if dealer.get("price_variants"):
            chosen = [v for v in live if re.search(dealer["price_variants"], _variant_text(v), re.I)]
            if chosen:
                live = chosen
            else:
                price_basis = "check"   # only the full-price option is left
        prices = {money(v.get("price")) for v in live if money(v.get("price"))}
        cheapest = min(live, key=lambda v: money(v.get("price")) or 1e9)
        price = money(cheapest.get("price"))
        was = money(cheapest.get("compare_at_price"))
        tags = p.get("tags") or []
        if isinstance(tags, str):
            tags = [t.strip() for t in tags.split(",") if t.strip()]
        out.append({
            "source_id": str(p["id"]),
            "title": (p.get("title") or "").strip(),
            # Some handles hold emoji (GMS starts many with "✅"), so encode them for a valid address.
            "url": f"{base}/products/{quote(p['handle'], safe='-_.~')}",
            "price": price,
            "was_price": was if (was and price and was > price * 1.02) else None,
            "text": p.get("body_text") or html_to_text(p.get("body_html")),
            "tags": tags,
            "product_type": p.get("product_type") or "",
            "vendor": p.get("vendor") or "",
            "variant_titles": [v.get("title", "") for v in live if v.get("title") != "Default Title"],
            "price_varies": len(prices) > 1,
            "sold_new_too": len(live) < len(all_live) and bool(dealer.get("used_variants")),
            "price_basis": price_basis,
            "images": [i["src"] for i in (p.get("images") or []) if i.get("src")][:6],
            "source_updated_at": p.get("updated_at"),
            "extra_category": "",
        })
    return out
