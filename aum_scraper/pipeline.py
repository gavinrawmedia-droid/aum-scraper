"""Run every dealer: fetch -> parse -> normalise -> filter, then keep history,
write the outputs and a health report."""
from __future__ import annotations

import csv
import json
import re
import traceback
from datetime import datetime, timedelta, timezone
from pathlib import Path

import httpx
import yaml

from .adapters import ADAPTERS
from .fetch import Fetcher
from .normalise import (clean_title, detect_brand, detect_category, detect_condition, detect_type_detail,
                        detect_year, extract_specs, extract_warranty_months)

MISSES_BEFORE_REMOVAL = 2      # an item must be missing from 2 runs in a row before we drop it
HIDE_AFTER_DAYS = 7            # never show anything we haven't seen for a week

CSV_FIELDS = [
    "id", "dealer_name", "dealer_town", "dealer_postcode", "dealer_phone", "title", "clean_title",
    "brand", "year", "category", "type_detail", "condition", "price_gbp", "price_is_from", "was_price_gbp",
    "price_basis", "warranty_months", "warranty_source", "max_user_weight_kg", "range_miles",
    "top_speed_mph", "heaviest_part_kg", "spec_note", "url", "image_url", "first_seen", "last_seen",
]


def now_utc() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def load_config(path: str | Path) -> dict:
    cfg = yaml.safe_load(Path(path).read_text())
    defaults = cfg.get("defaults", {})
    for d in cfg["dealers"]:
        for k, v in defaults.items():
            d.setdefault(k, v)
    return cfg


def normalise(dealer: dict, item: dict, seen_at: str) -> dict:
    text = item.get("text", "")
    specs = extract_specs(item["title"] + "\n" + text)
    # When the same page also sells the item new, its description's warranty is likely the new one's.
    warranty_texts = [item["title"], " ".join(item.get("variant_titles", []))] + ([] if item.get("sold_new_too") else [text])
    warranty = extract_warranty_months(*warranty_texts)
    warranty_source = "Listing" if warranty else None
    if not warranty and dealer.get("warranty_policy"):
        warranty = dealer["warranty_policy"]["months"]
        warranty_source = dealer["warranty_policy"]["source"]
    title = item["title"]
    images = item.get("images", [])
    category = detect_category(title, item.get("tags"), item.get("product_type", ""),
                               item.get("extra_category", ""), specs["top_speed_mph"], text)
    # A stairlift's price always depends on the staircase, so it is a starting price.
    price_is_from = bool(re.search(r"\bfrom\b\s*£|available from", title, re.I)
                         or item.get("price_varies") or category == "Stairlift")
    return {
        "id": f"{dealer['id']}:{item['source_id']}",
        "dealer_id": dealer["id"],
        "dealer_name": dealer["name"],
        "dealer_town": dealer.get("town"),
        "dealer_postcode": dealer.get("postcode"),
        "dealer_phone": dealer.get("phone"),
        "title": title,
        "clean_title": clean_title(title),
        "brand": detect_brand(title, item.get("vendor", ""), item.get("tags"), dealer["name"]),
        "year": detect_year(title),
        "category": category,
        "type_detail": detect_type_detail(category, title, text),
        "condition": detect_condition(title, item.get("tags"), item.get("product_type", ""),
                                      dealer.get("condition_default")),
        "price_gbp": item.get("price"),
        "price_is_from": price_is_from,
        "was_price_gbp": item.get("was_price"),
        "price_basis": item.get("price_basis") or dealer.get("price_basis", "check"),
        "warranty_months": warranty,
        "warranty_source": warranty_source,
        **{k: v for k, v in specs.items() if k != "spec_conflicts"},
        "spec_note": conflict_note(specs["spec_conflicts"]),
        "url": item["url"],
        "image_url": images[0] if images else None,
        "image_urls": images,
        "source_updated_at": item.get("source_updated_at"),
        "last_checked": seen_at,
    }


def keep(dealer: dict, rec: dict) -> str | None:
    """Return a reason to drop the record, or None to keep it."""
    if rec["price_gbp"] is None:
        return "no price"
    if rec["price_gbp"] < dealer.get("min_price", 0):
        return "below minimum price"
    if rec["condition"] == "New":
        return "listed as new"
    if rec["category"] in dealer.get("drop_categories", []):
        return "not mobility equipment"
    if re.search(r"^(?!.*\bwith\b).*\b(spare|replacement)\b.*\b(battery|batteries|pack|parts?|charger)\b",
                 rec["title"], re.I):
        return "spare part or accessory"
    return None


UNITS = {"max_user_weight_kg": ("max user weight", "kg"), "range_miles": ("range", "miles"),
         "top_speed_mph": ("top speed", "mph"), "heaviest_part_kg": ("heaviest part", "kg")}


def conflict_note(conflicts: dict) -> str | None:
    if not conflicts:
        return None
    parts = [f"{UNITS[k][0]} ({' vs '.join(f'{v:g}' for v in vals)} {UNITS[k][1]})" for k, vals in conflicts.items()]
    return "Dealer's description gives different figures for " + "; ".join(parts) + " - we show the first. Check with the dealer."


def geocode(postcodes: list[str], cache: dict) -> dict:
    """Look up dealer postcodes once with postcodes.io (free UK service) and cache the result."""
    todo = [p for p in postcodes if p and p not in cache]
    if todo:
        try:
            r = httpx.post("https://api.postcodes.io/postcodes", json={"postcodes": todo}, timeout=20)
            for row in r.json().get("result", []):
                res = row.get("result")
                if res:
                    cache[row["query"]] = {"lat": res["latitude"], "lng": res["longitude"]}
        except (httpx.HTTPError, ValueError):
            pass
    return cache


def run(config_path: str | Path, out_dir: str | Path, offline_dir: str | Path | None = None,
        only: list[str] | None = None, do_geocode: bool = False, fetcher: Fetcher | None = None,
        save_raw: str | Path | None = None) -> dict:
    cfg = load_config(config_path)
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    state_path = out / "state.json"
    state = json.loads(state_path.read_text()) if state_path.exists() else {"listings": {}, "dealer_counts": {}, "geo": {}}
    seen_at = now_utc()
    dealers = [d for d in cfg["dealers"] if not only or d["id"] in only]
    defaults = cfg.get("defaults", {})
    if offline_dir is None and fetcher is None:
        fetcher = Fetcher(defaults.get("user_agent", "ApprovedUsedMobilityBot/0.1"),
                          delay_seconds=defaults.get("delay_seconds", 3))

    health = {"run_at": seen_at, "mode": "offline" if offline_dir else "live", "dealers": {}}
    for dealer in dealers:
        adapter = ADAPTERS[dealer["platform"]]
        h = {"status": "ok", "count": 0, "previous_count": state["dealer_counts"].get(dealer["id"]),
             "dropped": {}, "messages": []}
        try:
            if offline_dir:
                fixture = json.loads((Path(offline_dir) / f"{dealer['id']}.json").read_text())
                # "raw" is a capture made with --save-raw; older fixtures use the adapter's own shape
                raw = fixture["raw"] if "raw" in fixture else adapter.from_fixture(fixture)
                seen_at_dealer = fixture.get("fetched_at", seen_at)
            else:
                raw = adapter.fetch(dealer, fetcher)
                seen_at_dealer = seen_at
                if save_raw:
                    Path(save_raw).mkdir(parents=True, exist_ok=True)
                    (Path(save_raw) / f"{dealer['id']}.json").write_text(
                        json.dumps({"fetched_at": seen_at, "raw": raw}, ensure_ascii=False))
            h["messages"] += raw.get("warnings", [])
            items = adapter.parse(dealer, raw)
        except Exception as exc:  # one broken dealer must not stop the others
            h.update(status="error", messages=[f"{type(exc).__name__}: {exc}"], trace=traceback.format_exc(limit=3))
            health["dealers"][dealer["id"]] = h
            continue

        present = set()
        for item in items:
            rec = normalise(dealer, item, seen_at_dealer)
            reason = keep(dealer, rec)
            if reason:
                h["dropped"][reason] = h["dropped"].get(reason, 0) + 1
                continue
            prev = state["listings"].get(rec["id"], {})
            rec["first_seen"] = prev.get("first_seen", seen_at_dealer)
            rec["last_seen"] = seen_at_dealer
            if prev.get("price_gbp") not in (None, rec["price_gbp"]):
                rec["previous_price_gbp"] = prev["price_gbp"]
            rec["status"] = "active"
            rec["misses"] = 0
            state["listings"][rec["id"]] = rec
            present.add(rec["id"])

        for lid, rec in state["listings"].items():   # anything this dealer no longer shows
            if rec["dealer_id"] == dealer["id"] and lid not in present and rec.get("status") == "active":
                rec["misses"] = rec.get("misses", 0) + 1
                if rec["misses"] >= MISSES_BEFORE_REMOVAL:
                    rec["status"] = "removed"
                    rec["removed_at"] = seen_at_dealer

        h["count"] = len(present)
        prev_count = h["previous_count"]
        if h["count"] == 0:
            h["status"] = "warning"
            h["messages"].append("No items found - the dealer may have changed their site")
        elif prev_count and prev_count >= 5 and h["count"] < prev_count * 0.5:
            h["status"] = "warning"
            h["messages"].append(f"Item count fell from {prev_count} to {h['count']}")
        if h["messages"] and h["status"] == "ok":
            h["status"] = "warning"
        state["dealer_counts"][dealer["id"]] = h["count"]
        health["dealers"][dealer["id"]] = h

    if do_geocode:
        geocode([d.get("postcode") for d in cfg["dealers"]], state.setdefault("geo", {}))

    # ---- outputs
    cutoff = (datetime.now(timezone.utc) - timedelta(days=HIDE_AFTER_DAYS)).isoformat()
    live = [r for r in state["listings"].values()
            if r.get("status") == "active" and r.get("last_seen", "") >= cutoff[:10]]
    geo = state.get("geo", {})
    for r in live:
        g = geo.get(r.get("dealer_postcode") or "")
        r["dealer_lat"], r["dealer_lng"] = (g["lat"], g["lng"]) if g else (None, None)
    live.sort(key=lambda r: (r["dealer_name"], r["category"], r["price_gbp"] or 0))

    (out / "listings.json").write_text(json.dumps(
        {"generated_at": seen_at, "count": len(live), "listings": live}, indent=1, ensure_ascii=False))
    with open(out / "listings.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=CSV_FIELDS, extrasaction="ignore")
        w.writeheader()
        w.writerows(live)
    dealers_out = []
    for d in cfg["dealers"]:
        g = geo.get(d.get("postcode") or "")
        dealers_out.append({k: d.get(k) for k in ("id", "name", "website", "address", "town", "postcode",
                                                  "phone", "other_branches", "visit_note", "price_basis")}
                           | {"lat": g["lat"] if g else None, "lng": g["lng"] if g else None,
                              "listing_count": sum(1 for r in live if r["dealer_id"] == d["id"])})
    (out / "dealers.json").write_text(json.dumps(dealers_out, indent=1, ensure_ascii=False))
    (out / "health.json").write_text(json.dumps(health, indent=1))
    state_path.write_text(json.dumps(state, indent=1, ensure_ascii=False))
    if fetcher:
        health["requests_made"] = fetcher.requests_made
    health["total_listings"] = len(live)
    return health
