"""Mobility Station (mobilitystation.co.uk) runs its own shop, not Shopify.

Its clearance page lists every used and ex-demo item as a card. Each card carries the
item's details as JSON (name, price, condition, stock), shows its grade, and links to a
product page whose structured data (schema.org Product) holds the description and photos.
We read the listing page, then the product page of each item that is in stock.
"""
from __future__ import annotations

import json
import re

from bs4 import BeautifulSoup

from ..fetch import Fetcher


def fetch(dealer: dict, fetcher: Fetcher) -> dict:
    base = dealer["website"].rstrip("/")
    pages, products = [], []
    for path in dealer.get("pages", ["/clearance"]):
        url = base + path
        html = fetcher.get_text(url)
        pages.append({"url": url, "html": html})
        for card in _cards(html):
            if card["in_stock"]:
                purl = f"{base}/products/{card['slug']}"
                try:
                    products.append({"url": purl, "html": fetcher.get_text(purl)})
                except Exception:  # a missing product page still leaves the card's own details
                    pass
    return {"pages": pages, "products": products, "warnings": []}


def from_fixture(fixture: dict, dealer: dict | None = None) -> dict:
    return {"pages": fixture.get("pages", []), "products": fixture.get("products", []), "warnings": []}


def _cards(html: str) -> list[dict]:
    soup = BeautifulSoup(html, "html.parser")
    out = []
    for card in soup.select("article.ms-catalog-card"):
        node = card.select_one("[data-quick-view]")
        if not node:
            continue
        try:
            qv = json.loads(node["data-quick-view"])
        except (ValueError, KeyError):
            continue
        text = card.get_text(" | ", strip=True)
        grade = re.search(r"\bGrade ([A-E])\b", text)
        img = next((i.get("src") for i in card.select("img")
                    if (i.get("src") or "").startswith("http")), None)
        out.append({
            "slug": qv.get("slug"),
            "name": (qv.get("name") or "").strip(),
            "category": qv.get("category") or "",
            "condition": qv.get("condition") or "",
            "price": qv.get("sale_price") or qv.get("unit_price"),
            "was": qv.get("unit_price") if qv.get("sale_price") else None,
            "grade": grade.group(1) if grade else None,
            "image": img,
            "in_stock": (qv.get("quantity") or 0) > 0 and "out of stock" not in text.lower(),
        })
    return out


def _product_details(html: str) -> dict:
    """Description and photos from the product page's schema.org data, else its page images."""
    soup = BeautifulSoup(html, "html.parser")
    desc, images = "", []
    for s in soup.select('script[type="application/ld+json"]'):
        try:
            data = json.loads(s.string or "")
        except ValueError:
            continue
        if isinstance(data, dict) and data.get("@type") == "Product":
            desc = data.get("description") or ""
            img = data.get("image") or []
            images = [img] if isinstance(img, str) else [i for i in img if isinstance(i, str)]
    if not images:
        images = [i.get("src") for i in soup.select("img") if "/stock-images/" in (i.get("src") or "")]
    seen, unique = set(), []
    for u in images:
        if u and u.startswith("http") and u not in seen:
            seen.add(u)
            unique.append(u)
    return {"text": desc, "images": unique[:6]}


def parse(dealer: dict, raw: dict) -> list[dict]:
    base = dealer["website"].rstrip("/")
    details = {p["url"].rstrip("/").rsplit("/", 1)[-1]: _product_details(p["html"]) for p in raw.get("products", [])}
    items: dict[str, dict] = {}
    clearance_pages = tuple(base + path for path in dealer.get("clearance_pages", []))
    for page in raw["pages"]:
        is_clearance = bool(clearance_pages) and page["url"].rstrip("/").endswith(clearance_pages)
        for c in _cards(page["html"]):
            if not c["slug"] or not c["in_stock"] or not c["price"]:
                continue
            d = details.get(c["slug"], {})
            images = d.get("images") or ([c["image"]] if c["image"] else [])
            tags = [c["condition"]] + ([f"Grade {c['grade']}"] if c["grade"] else [])
            items[c["slug"]] = {
                "source_id": c["slug"],
                "title": c["name"],
                "url": f"{base}/products/{c['slug']}",
                "price": float(c["price"]),
                "was_price": float(c["was"]) if c["was"] and float(c["was"]) > float(c["price"]) * 1.02 else None,
                "text": d.get("text", ""),
                "tags": tags,
                "product_type": "",
                "vendor": "",
                "variant_titles": [],
                "images": images,
                "source_updated_at": None,
                "extra_category": c["category"],
                "grade": c["grade"],
                "in_clearance_collection": is_clearance,   # a page the dealer names clearance
            }
    return list(items.values())
