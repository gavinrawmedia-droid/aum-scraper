"""Send the latest stock to the WordPress site (AUM Directory plugin).

Signs in as the Stock importer user with an application password, then:
  1. sends the dealers,
  2. sends the listings in batches,
  3. tells WordPress which dealers were read successfully, so it can take down
     anything those dealers no longer show. Dealers whose run failed are left alone.
"""
from __future__ import annotations

import json
from pathlib import Path

import httpx

BATCH_SIZE = 50
LISTING_FIELDS = [
    "id", "dealer_id", "title", "clean_title", "brand", "year", "category", "type_detail", "condition",
    "price_gbp", "price_is_from", "was_price_gbp", "previous_price_gbp", "price_basis",
    "warranty_months", "warranty_source", "max_user_weight_kg", "range_miles", "top_speed_mph",
    "heaviest_part_kg", "spec_note", "url", "image_urls", "first_seen", "last_seen",
]


class PushError(Exception):
    pass


def _post(client: httpx.Client, path: str, payload: dict) -> dict:
    r = client.post(path, json=payload)
    if r.status_code in (401, 403):
        raise PushError(f"WordPress refused the login ({r.status_code}). Check the importer user and its application password.")
    if r.status_code == 404:
        raise PushError("WordPress has no AUM Directory import endpoint. Is the plugin installed and active?")
    r.raise_for_status()
    return r.json()


def push(data_dir: str | Path, site_url: str, user: str, app_password: str,
         transport: httpx.BaseTransport | None = None, batch_size: int = BATCH_SIZE) -> dict:
    data = Path(data_dir)
    dealers = json.loads((data / "dealers.json").read_text())
    listings = json.loads((data / "listings.json").read_text())["listings"]
    health = json.loads((data / "health.json").read_text())

    base = site_url.rstrip("/") + "/wp-json/aum-directory/v1"
    summary = {"dealers": None, "listings": {"created": 0, "updated": 0, "republished": 0, "errors": []},
               "finalize": None}
    with httpx.Client(base_url=base, auth=(user, app_password), timeout=120, transport=transport,
                      headers={"User-Agent": "ApprovedUsedMobilityBot/0.1 (importer)"}) as client:
        summary["dealers"] = _post(client, "/dealers", {"dealers": dealers})

        rows = [{k: r.get(k) for k in LISTING_FIELDS} for r in listings]
        for start in range(0, len(rows), batch_size):
            res = _post(client, "/listings", {"listings": rows[start:start + batch_size]})
            for k in ("created", "updated", "republished"):
                summary["listings"][k] += res.get(k, 0)
            summary["listings"]["errors"] += res.get("errors", [])

        # Only dealers read successfully this run may have stock taken down.
        good = [d for d, h in health.get("dealers", {}).items() if h.get("status") != "error" and h.get("count", 0) > 0]
        active = [r["id"] for r in listings if r["dealer_id"] in good]
        summary["finalize"] = _post(client, "/finalize", {"dealer_ids": good, "active_ids": active,
                                                          "run_at": health.get("run_at")})
    return summary
