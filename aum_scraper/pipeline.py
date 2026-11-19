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
from .normalise import (USED_CONDITIONS, clean_title, condition_from_text, detect_brand, detect_category,
                        detect_clearance, detect_condition, detect_type_detail, detect_year, extract_specs,
                        extract_warranty_months)

MISSES_BEFORE_REMOVAL = 2      # an item must be missing from 2 runs in a row before we drop it
HIDE_AFTER_DAYS = 7            # never show anything we haven't seen for a week
# On a clearance page, "NEW" anywhere in the title means new stock ("... Recliner - NEW").
_NEW_IN_TITLE = r"\bnew\b(?!\s*(?:batter|batts?\b|lithium|tyres?|tires?|motors?|charger|seat|cover))"
PRICE_HELD_DAYS = 7            # a cut only counts if the old price had held for a week
PRICE_DROP_SHOW_DAYS = 14      # "Price dropped" shows for two weeks after the cut

CSV_FIELDS = [
    "id", "dealer_name", "dealer_town", "dealer_postcode", "dealer_phone", "title", "clean_title",
    "brand", "year", "category", "type_detail", "condition", "clearance", "clearance_only", "price_gbp",
    "price_is_from", "was_price_gbp", "price_drop_from_gbp", "price_dropped_at", "reduced",
    "price_basis", "warranty_months", "warranty_source", "max_user_weight_kg", "range_miles",
    "top_speed_mph", "heaviest_part_kg", "spec_note", "url", "image_url", "first_seen", "last_seen",
]


def now_utc() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def parse_time(value: str) -> datetime:
    """An ISO time as written by us or a capture ("...Z" or "+00:00"), always in UTC."""
    t = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    return t if t.tzinfo else t.replace(tzinfo=timezone.utc)


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
    # Clearance: the dealer marks the item itself, or it comes from a page of theirs that we
    # list as clearance in dealers.yaml. A clearance page can hold new end-of-line stock, so the
    # dealer's usual "everything is used" default doesn't apply to it.
    from_clearance_page = bool(item.get("clearance_source"))
    clearance = (from_clearance_page or bool(item.get("in_clearance_collection"))
                 or detect_clearance(title, item.get("tags"), item.get("product_type", "")))
    if from_clearance_page:
        condition = detect_condition(title, item.get("tags"), item.get("product_type", ""), None)
        if condition == "Not stated" and re.search(_NEW_IN_TITLE, title, re.I):
            condition = "New"            # "Kelbrook Rise Recliner Chair - NEW"
        if condition == "Not stated":
            condition = condition_from_text(text) or dealer.get("clearance_condition_default") or "Not stated"
    else:
        condition = detect_condition(title, item.get("tags"), item.get("product_type", ""),
                                     dealer.get("condition_default"))
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
        "condition": condition,
        "clearance": clearance,
        # Only approved-used stock (used, reconditioned, ex-demo) may appear on the main site.
        # Anything else the dealer is clearing (new end-of-line, or condition unknown) is shown
        # in the separate Clearance section only.
        "clearance_only": clearance and condition not in USED_CONDITIONS,
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
    if rec["condition"] == "New" and not rec.get("clearance"):
        return "listed as new"
    if rec["category"] in dealer.get("drop_categories", []):
        return "not mobility equipment"
    if re.search(r"^(?!.*\bwith\b).*\b(spare|replacement)\b.*\b(battery|batteries|pack|parts?|charger)\b",
                 rec["title"], re.I):
        return "spare part or accessory"
    return None


def track_price_drop(rec: dict, prev: dict, seen_at: str) -> None:
    """Note a price cut we saw ourselves, so the site can say "Price dropped £X".

    Two rules keep it honest: the old price must have been on the dealer's site for at least
    PRICE_HELD_DAYS before the cut (a price that flickers up and down for a day is not a real
    "before" price), and the note is shown for PRICE_DROP_SHOW_DAYS after the cut, then
    dropped. A price that goes back up ends it at once. Several cuts in a row are measured from
    the price that held before the first one."""
    price, old = rec.get("price_gbp"), prev.get("price_gbp")
    now = parse_time(seen_at)
    # When the current price first appeared (for listings from before this was tracked: first seen).
    if old is None or price != old:
        rec["price_since"] = seen_at
    else:
        rec["price_since"] = prev.get("price_since") or prev.get("first_seen") or seen_at

    drop_from, drop_at = prev.get("price_drop_from_gbp"), prev.get("price_dropped_at")
    if drop_from and drop_at and price and price < drop_from and (old is None or price <= old) \
            and now - parse_time(drop_at) <= timedelta(days=PRICE_DROP_SHOW_DAYS):
        rec["price_drop_from_gbp"], rec["price_dropped_at"] = drop_from, drop_at   # still showing
        return
    held_since = prev.get("price_since") or prev.get("first_seen")
    if old and price and price < old and held_since \
            and now - parse_time(held_since) >= timedelta(days=PRICE_HELD_DAYS):
        rec["price_drop_from_gbp"], rec["price_dropped_at"] = old, seen_at          # a new cut


def is_reduced(rec: dict) -> bool:
    """A dealer's own "was" price, or a price cut we saw ourselves."""
    price = rec.get("price_gbp") or 0
    return bool((rec.get("was_price_gbp") or 0) > price > 0 or rec.get("price_drop_from_gbp"))


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
    # The 7-day "not seen" cut-off counts back from the newest reading in this run: the clock
    # in a live run, the newest capture in an offline (test) run, so tests don't age.
    newest_seen = "" if offline_dir else seen_at
    for dealer in dealers:
        adapter = ADAPTERS[dealer["platform"]]
        h = {"status": "ok", "count": 0, "previous_count": state["dealer_counts"].get(dealer["id"]),
             "dropped": {}, "messages": []}
        try:
            if offline_dir:
                fixture = json.loads((Path(offline_dir) / f"{dealer['id']}.json").read_text())
                # "raw" is a capture made with --save-raw; older fixtures use the adapter's own shape
                raw = fixture["raw"] if "raw" in fixture else adapter.from_fixture(fixture, dealer)
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

        newest_seen = max(newest_seen, seen_at_dealer)
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
            track_price_drop(rec, prev, seen_at_dealer)
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
    cutoff = (parse_time(newest_seen or seen_at) - timedelta(days=HIDE_AFTER_DAYS)).isoformat()
    live = [r for r in state["listings"].values()
            if r.get("status") == "active" and r.get("last_seen", "") >= cutoff[:10]]
    geo = state.get("geo", {})
    for r in live:
        g = geo.get(r.get("dealer_postcode") or "")
        r["dealer_lat"], r["dealer_lng"] = (g["lat"], g["lng"]) if g else (None, None)
        if r.get("price_dropped_at") and parse_time(newest_seen or seen_at) - parse_time(r["price_dropped_at"]) \
                > timedelta(days=PRICE_DROP_SHOW_DAYS):
            r.pop("price_drop_from_gbp", None)       # listings missed this run age out too
            r.pop("price_dropped_at", None)
        r["reduced"] = is_reduced(r)
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
                              # approved-used stock only: what the dealer's "See all N listings" opens
                              "listing_count": sum(1 for r in live if r["dealer_id"] == d["id"]
                                                   and not r.get("clearance_only")),
                              "clearance_count": sum(1 for r in live if r["dealer_id"] == d["id"]
                                                     and r.get("clearance"))})
    (out / "dealers.json").write_text(json.dumps(dealers_out, indent=1, ensure_ascii=False))
    (out / "health.json").write_text(json.dumps(health, indent=1))
    state_path.write_text(json.dumps(state, indent=1, ensure_ascii=False))
    if fetcher:
        health["requests_made"] = fetcher.requests_made
    health["total_listings"] = len(live)
    return health
