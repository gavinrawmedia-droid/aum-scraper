import json
from pathlib import Path

import httpx
import pytest

from aum_scraper.wordpress import PushError, push

ROOT = Path(__file__).resolve().parents[1]


def _data(tmp_path, health):
    d = tmp_path / "data"
    d.mkdir()
    listings = [
        {"id": f"shop-a:{i}", "dealer_id": "shop-a", "title": f"Item {i}", "clean_title": f"Item {i}",
         "price_gbp": 1000 + i, "url": f"https://a.example/products/{i}", "category": "Powerchair",
         "condition": "Used", "image_urls": [], "extra_field_not_sent": "x"} for i in range(120)
    ] + [{"id": "shop-b:1", "dealer_id": "shop-b", "title": "B", "price_gbp": 900, "url": "https://b.example/p/1"}]
    (d / "listings.json").write_text(json.dumps({"listings": listings}))
    (d / "dealers.json").write_text(json.dumps([{"id": "shop-a", "name": "Shop A"}, {"id": "shop-b", "name": "Shop B"}]))
    (d / "health.json").write_text(json.dumps(health))
    return d


def _recorder(calls, status=200):
    def handler(request: httpx.Request):
        body = json.loads(request.content or b"{}")
        calls.append((request.url.path, body, request.headers.get("authorization", "")))
        if status != 200:
            return httpx.Response(status, json={"code": "rest_forbidden"})
        if request.url.path.endswith("/listings"):
            return httpx.Response(200, json={"created": len(body["listings"]), "updated": 0, "republished": 0, "errors": []})
        if request.url.path.endswith("/dealers"):
            return httpx.Response(200, json={"created": 2, "updated": 0, "errors": []})
        return httpx.Response(200, json={"removed": 0, "kept": 0, "skipped_dealers": []})
    return httpx.MockTransport(handler)


def test_push_batches_and_only_finalizes_healthy_dealers(tmp_path):
    health = {"run_at": "2026-11-16T05:17:00+00:00", "dealers": {
        "shop-a": {"status": "ok", "count": 120}, "shop-b": {"status": "error", "count": 0}}}
    calls = []
    summary = push(_data(tmp_path, health), "https://site.example", "importer", "app pw",
                   transport=_recorder(calls), batch_size=50)
    paths = [c[0] for c in calls]
    assert paths == ["/wp-json/aum-directory/v1/dealers"] + ["/wp-json/aum-directory/v1/listings"] * 3 + \
        ["/wp-json/aum-directory/v1/finalize"]
    assert summary["listings"]["created"] == 121
    assert all(c[2].startswith("Basic ") for c in calls)
    sent = calls[1][1]["listings"][0]
    assert "extra_field_not_sent" not in sent and sent["url"].startswith("https://")
    final = calls[-1][1]
    assert final["dealer_ids"] == ["shop-a"]            # the failed dealer is never cleared out
    assert len(final["active_ids"]) == 120 and "shop-b:1" not in final["active_ids"]


def test_push_explains_a_refused_login(tmp_path):
    health = {"dealers": {"shop-a": {"status": "ok", "count": 1}}}
    with pytest.raises(PushError, match="refused the login"):
        push(_data(tmp_path, health), "https://site.example", "importer", "wrong", transport=_recorder([], 401))


def test_push_explains_a_missing_plugin(tmp_path):
    health = {"dealers": {"shop-a": {"status": "ok", "count": 1}}}
    with pytest.raises(PushError, match="plugin installed"):
        push(_data(tmp_path, health), "https://site.example", "importer", "pw", transport=_recorder([], 404))
