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

# Every dealer that has a saved capture in fixtures/ (a new dealer gets one from a test run).
CAPTURED = sorted(p.stem for p in FIXTURES.glob("*.json"))


def test_pipeline_offline_and_history(tmp_path):
    out = tmp_path / "data"
    h = run(ROOT / "dealers.yaml", out, offline_dir=FIXTURES, only=CAPTURED)
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
    run(ROOT / "dealers.yaml", out, offline_dir=fx_dir, only=CAPTURED)
    ids = {r["id"] for r in json.loads((out / "listings.json").read_text())["listings"]}
    assert gone_id in ids and gone_id in first_ids
    run(ROOT / "dealers.yaml", out, offline_dir=fx_dir, only=CAPTURED)
    ids = {r["id"] for r in json.loads((out / "listings.json").read_text())["listings"]}
    assert gone_id not in ids
    state = json.loads((out / "state.json").read_text())
    assert state["listings"][gone_id]["status"] == "removed"


# ------------------------------------------------------------------ equipment for the home (18 Nov 2026)

from aum_scraper.normalise import detect_type_detail  # noqa: E402


@pytest.mark.parametrize("title,category,brand,condition,detail", [
    ("Reconditioned Stannah Siena 260 Curved Stairlift", "Stairlift", "Stannah", "Reconditioned", "Curved"),
    ("Acorn 130 Straight Stairlift - Red", "Stairlift", "Acorn", "Not stated", "Straight"),
    ("Platinum Ergo Curve Stairlift", "Stairlift", "Platinum Stairlifts", "Not stated", "Curved"),
    ("Multicare Pre-Used Straight Stairlifts", "Stairlift", None, "Used", "Straight"),
    ("Handicare 1000 Perch Stairlift", "Stairlift", "Handicare", "Not stated", "Straight"),
    ("Burlington fabric dual motor riser recliner chair - Blue - Ex Demo", "Riser recliner chair", None, "Ex-demo", "Dual motor"),
    ("Bronte 4 Motor chair Compact Size Stafford oatmeal Fabric - Ex Demo", "Riser recliner chair", None, "Ex-demo", "4 motor"),
    ("The Leather Ultra 5 Motor High leg Lift Rise Recliner Chair with Heat and Massage-Medium Demo",
     "Riser recliner chair", None, "Ex-demo", "5 motor"),
    ("Drive Neptune Bath Lift - Ex Demo", "Bath lift", "Drive", "Ex-demo", None),
    ("Ex Demo Birdie Folding Hoists", "Patient hoist", None, "Ex-demo", None),
    ("Smart Lifter LC Compact Hoist", "Other", None, "Not stated", None),          # a car boot hoist
    ("Invacare Medley Ergo Medical Profiling Bed", "Profiling bed", "Invacare", "Not stated", None),
    ("Sterling Sapphire 2 Portable Travel Mobility Scooter Ex Display - Red", "Boot / folding scooter", "Sterling", "Ex-demo", None),
    ("Mini Crosser M2 8 Mph Mobility Scooter USED", "Road scooter (8mph)", "Mini Crosser", "Used", None),
])
def test_home_equipment(title, category, brand, condition, detail):
    cat = detect_category(title)
    assert cat == category
    assert detect_brand(title) == brand
    assert detect_condition(title) == condition
    assert detect_type_detail(cat, title) == detail


def test_clean_title_home_equipment():
    assert clean_title("Multicare Pre-Used Straight Stairlifts") == "Multicare Straight Stairlifts"
    assert clean_title("Reconditioned Stannah Siena 600 Straight Stairlift") == "Stannah Siena 600 Straight Stairlift"
    assert clean_title("Queensbury beige dual riser recliner chair - Ex Showroom Model") == "Queensbury beige dual riser recliner chair"
    assert clean_title("Envoy 4 Mobility Scooter 4mph - Blue - Ex Display Model") == "Envoy 4 Mobility Scooter 4mph - Blue"


def _product(title, variants, **kw):
    return {"id": 1, "handle": "x", "title": title, "body_html": "", "tags": [], "product_type": "",
            "vendor": "", "images": [], "variants": variants, **kw}


def test_shopify_keeps_only_reconditioned_variants_and_from_price():
    dealer = {"id": "d", "website": "https://d.co.uk", "used_variants": r"recondition|pre[- ]?used"}
    p = _product("Acorn 130 Straight Stairlift", [
        {"title": "New", "option1": "New", "price": "2995.00", "available": True},
        {"title": "Reconditioned / Red", "option1": "Reconditioned", "price": "1595.00", "available": True},
        {"title": "Reconditioned / Green", "option1": "Reconditioned", "price": "1695.00", "available": True},
    ])
    [item] = shopify.parse(dealer, {"products": [p]})
    assert item["price"] == 1595.0 and item["price_varies"] is True
    assert item["variant_titles"] == ["Reconditioned / Red", "Reconditioned / Green"]
    # a page sold new only is skipped
    new_only = _product("Acorn 180 Curved Stairlift", [{"title": "New", "price": "4995", "available": True}])
    assert shopify.parse(dealer, {"products": [new_only]}) == []


def test_shopify_title_rules():
    dealer = {"id": "d", "website": "https://d.co.uk",
              "include": {"title_matches": r"ex[- ]?demo|\bdemo\b", "title_excludes": r"\bnew\b(?!\s*batt)"}}
    keep = _product("Burlington Riser Recliner - Ex Demo", [{"title": "Default Title", "price": "399.99", "available": True}])
    drop = _product("Kelbrook Rise Recliner Chair Dual Motor - NEW", [{"title": "Default Title", "price": "999", "available": True}])
    assert [i["title"] for i in shopify.parse(dealer, {"products": [keep, drop]})] == ["Burlington Riser Recliner - Ex Demo"]


def test_mobility_station_cards():
    from aum_scraper.adapters import mobility_station
    fx = json.loads((FIXTURES / "mobility-station.json").read_text())["raw"]
    items = mobility_station.parse({"id": "mobility-station", "website": "https://mobilitystation.co.uk"}, fx)
    assert len(items) == 10                                   # 16 on the page, 6 out of stock
    mc = next(i for i in items if i["source_id"] == "mini-crosser-m2-8-mph-mobility-scooter-used")
    assert mc["price"] == 1800.0 and mc["grade"] == "C" and "Grade C" in mc["tags"]
    assert mc["url"] == "https://mobilitystation.co.uk/products/mini-crosser-m2-8-mph-mobility-scooter-used"
    assert len(mc["images"]) > 1 and mc["text"]               # photos and description from the product page
    assert all(i["images"][0].startswith("https://") for i in items)


def test_shopify_price_variants_vat_relief():
    dealer = {"id": "d", "website": "https://d.co.uk", "price_variants": "VAT Exempt: Yes"}
    both = _product("Oakworth dual motor riser recliner chair - Ex Demo", [
        {"title": "VAT Exempt: No", "price": "599.99", "compare_at_price": "1019.99", "available": True},
        {"title": "VAT Exempt: Yes", "price": "499.99", "compare_at_price": "849.99", "available": True},
    ])
    only_full = _product("Karma Ergo 3 - Ex Demo", [
        {"title": "Silver / VAT Exempt: No", "price": "442.80", "available": True},
        {"title": "Silver / VAT Exempt: Yes", "price": "369.00", "available": False},
    ])
    a, b = shopify.parse(dealer, {"products": [both, only_full]})
    assert (a["price"], a["was_price"], a["price_varies"], a["price_basis"]) == (499.99, 849.99, False, None)
    assert (b["price"], b["price_basis"]) == (442.80, "check")


# ---- 18 Nov: GMS Mobility, The Mobility Shop, Eden Mobility

def test_clean_title_pipes_and_battery_notes():
    assert clean_title("Pride GoGo Elite Traveller | Refurbished Mobility Scooter Stockport") == "Pride GoGo Elite Traveller – Stockport"
    assert clean_title("Roma Paris Mobility Scooter | Refurbished") == "Roma Paris Mobility Scooter"
    assert clean_title("Motion Etravel | Refurbished Scooter Bridlington") == "Motion Etravel – Bridlington"
    assert clean_title("CareCo Frontier Boot Mobility Scooter in Silver | Ex-Demo Clearance") == "CareCo Frontier Boot Mobility Scooter in Silver"
    assert clean_title("Used Komfi Rider 8 Mobility Scooter – Red, 8mph, New 50Ah Batteries") == "Komfi Rider 8 Mobility Scooter – Red, 8mph"


def test_warranty_ignores_part_warranties_and_no_warranty():
    assert extract_warranty_months("Comes with a 5-year frame warranty for peace of mind.") is None
    assert extract_warranty_months("Sold in our clearance section without warranty. A 5-year frame warranty when new.") is None
    assert extract_warranty_months("Fully serviced with a 6-month warranty. The maker gives a 5-year frame warranty.") == 6
    assert extract_warranty_months("Includes our 12 month warranty") == 12


def test_category_falls_back_to_the_description():
    assert detect_category("Excel Galaxy mobility scooter", text="A Class 3 road legal scooter.") == "Road scooter (8mph)"
    assert detect_category("Sterling S400 mobility scooter", text="A Class 2 scooter for pavements.") == "Pavement scooter (4mph)"
    assert detect_category("Pride Apex mobility scooter", text="Unlike a Class 3 scooter, this Class 2 one stays on pavements.") \
        == "Mobility scooter (speed not stated)"
    assert detect_category("Roma Paris mobility scooter", text="It dismantles easily to fit in a car boot.") \
        == "Boot / folding scooter"
    assert detect_category("Roma Sovereign scooter", text="Not suitable for a car boot.") \
        == "Mobility scooter (speed not stated)"
