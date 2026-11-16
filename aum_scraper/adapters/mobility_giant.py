"""Mobility Giant (Spree/Rails) has no public product feed, so we read its
category listing pages. Each product card carries schema.org microdata."""
from __future__ import annotations

import re
from urllib.parse import urljoin

from bs4 import BeautifulSoup

from ..fetch import Fetcher
from ..normalise import money


def fetch(dealer: dict, fetcher: Fetcher) -> dict:
    base = dealer["website"].rstrip("/")
    pages, warnings = [], []
    for slug in dealer.get("categories", []):
        for page in range(1, 30):
            url = f"{base}/t/buy/{slug}" + (f"?page={page}" if page > 1 else "")
            html = fetcher.get_text(url)
            soup = BeautifulSoup(html, "html.parser")
            if not soup.select('[data-hook="products_list_item"]'):
                break
            pages.append({"url": url, "category_slug": slug, "html": html})
            if not soup.select(f'a[href*="page={page + 1}"]'):
                break
    if not pages:
        warnings.append("No product cards found - the site layout may have changed")
    return {"pages": pages, "warnings": warnings}


def from_fixture(fixture: dict) -> dict:
    return {"pages": fixture.get("pages", []), "warnings": []}


def parse(dealer: dict, raw: dict) -> list[dict]:
    base = dealer["website"].rstrip("/") + "/"
    items: dict[str, dict] = {}
    for page in raw["pages"]:
        soup = BeautifulSoup(page["html"], "html.parser")
        for card in soup.select('[data-hook="products_list_item"]'):
            link = card.select_one(".card-scooters__name a") or card.select_one('a[href*="/products/"]')
            if not link or not link.get("href"):
                continue
            url = urljoin(base, link["href"])
            cats = [li.get_text(" ", strip=True) for li in card.select(".card-scooters__categories__list li")]
            if url in items:  # same scooter listed in two categories
                for c in cats:
                    if c not in items[url]["tags"]:
                        items[url]["tags"].append(c)
                items[url]["extra_category"] = " | ".join(items[url]["tags"])
                continue
            new_price = card.select_one(".card-scooters__price__label--new")
            old_price = card.select_one(".card-scooters__price__label--crossed")
            price = money(new_price.get_text(strip=True)) if new_price else None
            was = money(old_price.get_text(strip=True)) if old_price else None
            if price is None and was is not None:
                price, was = was, None
            title = (link.get("title") or link.get_text(" ", strip=True)).strip()
            m = re.search(r"product_(\d+)", card.get("id", ""))
            items[url] = {
                "source_id": m.group(1) if m else url.rsplit("/", 1)[-1],
                "title": title,
                "url": url,
                "price": price,
                "was_price": was if (was and price and was > price * 1.02) else None,
                "text": "",
                "tags": cats,
                "product_type": "Mobility Scooter",
                "vendor": "",
                "variant_titles": [],
                "images": [urljoin(base, img["src"]) for img in card.select("img.card-scooters__image") if img.get("src")][:6],
                "source_updated_at": None,
                "extra_category": " | ".join(cats),
            }
    return list(items.values())
