import json
from pathlib import Path

import httpx
import pytest

from aum_scraper.adapters import mobility_giant, shopify
from aum_scraper.fetch import BlockedByRobots, Fetcher
from aum_scraper.normalise import (clean_title, detect_brand, detect_category, detect_condition,
                                   detect_year, extract_specs, extract_warranty_months)
from aum_scraper.pipeline import run
from aum_scraper.robots import Robots

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = ROOT / "fixtures"


# ------------------------------------------------------------------ robots.txt

SHOPIFY_ROBOTS = """User-agent: *
Disallow: /admin
Disallow: /collections/*sort_by*
Disallow: /*?*page=*
Disallow: /search
"""


def test_robots_wildcards():
    r = Robots(SHOPIFY_ROBOTS, "ApprovedUsedMobilityBot/0.1")
    assert r.allowed("https://x.co.uk/collections/used/products.json?limit=250")
    assert not r.allowed("https://x.co.uk/collections/used/products.json?limit=250&page=2")
    assert not r.allowed("https://x.co.uk/collections/used?sort_by=price")
    assert not r.allowed("https://x.co.uk/search?q=scooter")


def test_robots_specific_agent_and_allow_wins_tie():
    text = "User-agent: *\nDisallow: /\n\nUser-agent: ApprovedUsedMobilityBot\nAllow: /products\nDisallow: /cart\n"
    r = Robots(text, "ApprovedUsedMobilityBot/0.1")
    assert r.allowed("https://x.co.uk/products/abc")
    assert not r.allowed("https://x.co.uk/cart")


# ------------------------------------------------------------------ normalising

@pytest.mark.parametrize("title,vendor,expected", [
    ("Sunrise Medical QUICKIE Q100 R (2023)", "Mobility Solutions", "Quickie"),
    ("Sunrise Medical STERLING S700 (2023)", "", "Sterling"),
    ("Colt Executive", "Seacroft Mobility", "Pride"),
    ("Careco Li-Tech Spectrum (2026)", "", "CareCo"),
    ("Electric Mobility Veo Sport (2024)", "", "Rascal"),
    ("Drive Compact Lite (2024)", "", "Drive"),
    ("Apex Carbon Active Wheelchair - SHOWROOM MODEL", "Susorro", None),
    ("Pre-Owned Pride Apex Alumalite - Portable Mobility Scooters", "", "Pride"),
    ("Alumina Car Portable boot Mobility Scooter Motion New Batts", "Motion Healthcare", "Motion Healthcare"),
    ("Kensington S", "Seacroft Mobility", None),
])
def test_brand(title, vendor, expected):
    assert detect_brand(title, vendor, [], "Seacroft Mobility") == expected


def test_year_and_condition():
    assert detect_year("TGA Breeze S4 (2023)") == 2023
    assert detect_year("Drive Sport Rider 8mph 1300W Motorbike Style") is None
    assert detect_condition("EX DEMO Motion Connex") == "Ex-demo"
    assert detect_condition("Approved Used Kymco Midi XLS") == "Used"
    assert detect_condition("NEW Atom N Fold Folding Scooter 6 Months Warranty NEW BATTS") == "New"
    assert detect_condition("Kymco Maxer", default="Used") == "Used"
    assert detect_condition("Kymco Maxer") == "Not stated"


def test_category():
    assert detect_category("Pre-Owned Kymco Agility - 8mph Mobility Scooter") == "Road scooter (8mph)"
    assert detect_category("Pride Apex Rapid 4mph Travel Mobility Scooter") == "Boot / folding scooter"
    assert detect_category("Rascal 388s", product_type="Mobility Scooters", top_speed_mph=4) == "Pavement scooter (4mph)"
    assert detect_category("Kensington S", product_type="Mobility Scooter") == "Mobility scooter (speed not stated)"
    assert detect_category("Quickie Q500 M Sedeo Pro (2026)", tags=["Powerchairs"]) == "Powerchair"
    assert detect_category("Invacare Action 3NG Wheelchair From £985", product_type="Wheelchairs") == "Manual wheelchair"


def test_specs_first_mention_wins_and_conflicts_are_reported():
    text = ("Supporting users up to 19 stone (120kg). ✔ Up to 9-mile (15km) travel range ✔ Maximum speed 4mph\n"
            "S/N:1002 Top Speed: 8mph Range: 35 miles Max User Weight | 200 kg (31.5 Stone)")
    s = extract_specs(text)
    assert s["max_user_weight_kg"] == 120
    assert s["top_speed_mph"] == 4
    assert s["range_miles"] == 9
    assert set(s["spec_conflicts"]) == {"max_user_weight_kg", "top_speed_mph", "range_miles"}


def test_specs_glued_strip_and_other_weights_ignored():
    text = "8 mphTop speed 25 milesRange per charge 25 st.Maximum user weight 103.8 kgTotal weight"
    s = extract_specs(text)
    assert s["max_user_weight_kg"] == 159      # 25 stone, not the 103.8 kg scooter weight
    assert s["top_speed_mph"] == 8
    assert s["range_miles"] == 25
    s = extract_specs("Scooter Weight: Approx. 148kg Max User Weight: 31 stone (200kg)")
    assert s["max_user_weight_kg"] == 200 and not s["spec_conflicts"]


def test_specs_heaviest_part_and_unitless_table():
    s = extract_specs("It comes apart into pieces, the heaviest weighing 15 kg.")
    assert s["heaviest_part_kg"] == 15
    s = extract_specs("Maximum speed | 8 | Maximum range | 34 |")
    assert s["top_speed_mph"] == 8 and s["range_miles"] == 34


def test_warranty_and_title():
    assert extract_warranty_months("Checked over, with a 3-month warranty") == 3
    assert extract_warranty_months("", "3 Months Back To Shop") is None
    assert extract_warranty_months("FREE 3 MONTHS BACK TO SHOP WARRANTY") == 3
    assert clean_title("EX DEMO  Motion Connex Auto Folding Mobility Scooter 6 Months Warranty NEW BATTS") == \
        "Motion Connex Auto Folding Mobility Scooter"
    assert clean_title("Pre-Owned Pride Colt Deluxe 2.0 –  Mobility Scooter") == "Pride Colt Deluxe 2.0 – Mobility Scooter"


# ------------------------------------------------------------------ adapters

def _shopify_transport(products, robots=SHOPIFY_ROBOTS):
    def handler(request: httpx.Request):
        if request.url.path == "/robots.txt":
            return httpx.Response(200, text=robots)
        if request.url.path.endswith("/products.json"):
            page = int(request.url.params.get("page", 1))
            limit = int(request.url.params.get("limit", 30))
            chunk = products[(page - 1) * limit: page * limit]
            return httpx.Response(200, json={"products": chunk})
        return httpx.Response(404)
    return httpx.MockTransport(handler)


def _fake_products(n):
    return [{"id": i, "title": f"Pre-Owned Pride Colt {i}", "handle": f"colt-{i}", "body_html": "<p>8mph</p>",
             "tags": [], "product_type": "Mobility Scooter", "vendor": "Pride",
             "variants": [{"title": "Default Title", "price": "1000.00", "compare_at_price": None, "available": True}],
             "images": [{"src": "https://cdn/x.jpg"}]} for i in range(n)]


def test_shopify_fetch_reads_first_page_without_page_param():
    dealer = {"website": "https://shop.example", "collections": ["used"]}
    f = Fetcher("ApprovedUsedMobilityBot/0.1", delay_seconds=0, transport=_shopify_transport(_fake_products(10)),
                sleep=lambda s: None)
    raw = shopify.fetch(dealer, f)
    assert len(raw["products"]) == 10 and not raw["warnings"]


def test_shopify_fetch_stops_politely_when_robots_blocks_paging():
    dealer = {"website": "https://shop.example", "collections": ["used"]}
    f = Fetcher("ApprovedUsedMobilityBot/0.1", delay_seconds=0, transport=_shopify_transport(_fake_products(260)),
                sleep=lambda s: None)
    raw = shopify.fetch(dealer, f)
    assert len(raw["products"]) == 250
    assert "robots.txt blocks" in raw["warnings"][0]
    with pytest.raises(BlockedByRobots):
        f.get("https://shop.example/search?q=x")


def test_shopify_parse_skips_sold_and_ignores_silly_was_prices():
    dealer = {"website": "https://shop.example"}
    p = _fake_products(2)
    p[0]["variants"][0]["available"] = False
    p[1]["variants"][0]["compare_at_price"] = "200.00"
    out = shopify.parse(dealer, {"products": p})
    assert len(out) == 1 and out[0]["was_price"] is None
    assert out[0]["url"] == "https://shop.example/products/colt-1"


def test_mobility_giant_parse_merges_categories():
    fx = json.loads((FIXTURES / "mobility-giant.json").read_text())
    dealer = {"website": "https://www.mobilitygiant.co.uk"}
    items = mobility_giant.parse(dealer, mobility_giant.from_fixture(fx))
    assert len(items) > 40
    assert all(i["price"] and i["url"].startswith("https://www.mobilitygiant.co.uk/products/") for i in items)
    multi = [i for i in items if " | " in i["extra_category"]]
    assert multi, "items listed in two categories should keep both"


# ------------------------------------------------------------------ whole pipeline

def test_pipeline_offline_and_history(tmp_path):
    out = tmp_path / "data"
    h = run(ROOT / "dealers.yaml", out, offline_dir=FIXTURES)
    assert all(d["status"] == "ok" for d in h["dealers"].values())
    listings = json.loads((out / "listings.json").read_text())["listings"]
    assert len(listings) > 150
    for r in listings:
        assert r["price_gbp"] >= 300
        assert r["url"].startswith("http")
        assert r["condition"] != "New"
    first_ids = {r["id"] for r in listings}

    # Second run where one dealer's stock has vanished: kept once, removed on the next miss.
    fx_dir = tmp_path / "fx"
    fx_dir.mkdir()
    for f in FIXTURES.glob("*.json"):
        (fx_dir / f.name).write_text(f.read_text())
    mw = json.loads((fx_dir / "mobility-world.json").read_text())
    gone_id = "mobility-world:" + str(mw["collections"]["approved-used-mobility-scooters"].pop(0)["id"])
    (fx_dir / "mobility-world.json").write_text(json.dumps(mw))
    run(ROOT / "dealers.yaml", out, offline_dir=fx_dir)
    ids = {r["id"] for r in json.loads((out / "listings.json").read_text())["listings"]}
    assert gone_id in ids and gone_id in first_ids
    run(ROOT / "dealers.yaml", out, offline_dir=fx_dir)
    ids = {r["id"] for r in json.loads((out / "listings.json").read_text())["listings"]}
    assert gone_id not in ids
    state = json.loads((out / "state.json").read_text())
    assert state["listings"][gone_id]["status"] == "removed"
