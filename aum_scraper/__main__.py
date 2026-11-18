"""Command line:  python -m aum_scraper run [options]"""
from __future__ import annotations

import argparse
import os
import sys

from .pipeline import run
from .wordpress import PushError, push


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="aum_scraper", description="Collect approved-used stock from dealer websites.")
    sub = p.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("run", help="scrape every dealer in dealers.yaml")
    r.add_argument("--dealers", default="dealers.yaml", help="dealer config file")
    r.add_argument("--out", default="data", help="folder for listings.json/csv, dealers.json, health.json")
    r.add_argument("--offline", metavar="FIXTURES_DIR", help="read saved captures instead of the live sites")
    r.add_argument("--only", help="comma-separated dealer ids to run")
    r.add_argument("--geocode", action="store_true", help="look up dealer postcodes with postcodes.io")
    r.add_argument("--save-raw", metavar="DIR", help="also save what each dealer's site returned (for tests)")
    pr = sub.add_parser("probe", help="fetch a few pages politely and save them, to see how a new dealer's site works")
    pr.add_argument("urls", nargs="+")
    pr.add_argument("--out", default="probe", help="folder to save the pages in")
    ps = sub.add_parser("push", help="send the latest data/ files to the WordPress site")
    ps.add_argument("--data", default="data", help="folder written by the run command")
    ps.add_argument("--site", default=os.environ.get("AUM_WP_URL", "https://approvedusedmobility.co.uk"))
    args = p.parse_args(argv)

    if args.cmd == "probe":
        return probe(args.urls, args.out)

    if args.cmd == "push":
        user, password = os.environ.get("AUM_WP_USER"), os.environ.get("AUM_WP_APP_PASSWORD")
        if not user or not password:
            print("Set AUM_WP_USER and AUM_WP_APP_PASSWORD (the Stock importer user's application password).")
            return 2
        try:
            summary = push(args.data, args.site, user, password)
        except PushError as exc:
            print(f"Push failed: {exc}")
            return 1
        d, l, f = summary["dealers"], summary["listings"], summary["finalize"]
        print(f"Dealers: {d['created']} new, {d['updated']} updated")
        print(f"Listings: {l['created']} new, {l['updated']} updated, {l['republished']} back in stock, {len(l['errors'])} rejected")
        for e in l["errors"][:20]:
            print("  rejected:", e)
        print(f"Taken down (sold or removed): {f['removed']}")
        for s in f.get("skipped_dealers", []):
            print("  NOT taken down, check the scraper:", s)
        return 1 if l["errors"] else 0

    health = run(args.dealers, args.out, offline_dir=args.offline,
                 only=args.only.split(",") if args.only else None, do_geocode=args.geocode,
                 save_raw=args.save_raw)

    print(f"\n{'Dealer':<22}{'Status':<10}{'Items':>6}  Notes")
    for dealer_id, h in health["dealers"].items():
        dropped = ", ".join(f"{n} {k}" for k, n in h["dropped"].items()) if h.get("dropped") else ""
        notes = "; ".join(h["messages"] + ([f"dropped: {dropped}"] if dropped else []))
        print(f"{dealer_id:<22}{h['status']:<10}{h['count']:>6}  {notes}")
    print(f"\nTotal live listings: {health['total_listings']}")
    return 1 if any(h["status"] == "error" for h in health["dealers"].values()) else 0


def probe(urls: list[str], out: str) -> int:
    """Fetch each URL the way the scraper would (same name, robots.txt, pauses) and save it."""
    import re
    from pathlib import Path

    import httpx
    import yaml

    from .fetch import BlockedByRobots, Fetcher

    defaults = yaml.safe_load(Path("dealers.yaml").read_text()).get("defaults", {})
    fetcher = Fetcher(defaults.get("user_agent", "ApprovedUsedMobilityBot/0.1"),
                      delay_seconds=defaults.get("delay_seconds", 3), retries=0)
    Path(out).mkdir(parents=True, exist_ok=True)
    for i, url in enumerate(urls, 1):
        name = f"{i:02d}-" + re.sub(r"[^A-Za-z0-9]+", "-", url.split("://", 1)[-1])[:80].strip("-")
        try:
            r = fetcher.get(url)
            kind = r.headers.get("content-type", "").split(";")[0]
            ext = ".json" if "json" in kind else ".html" if "html" in kind else ".txt"
            Path(out, name + ext).write_text(r.text)
            print(f"{r.status_code} {kind:<18} {len(r.text):>8} bytes  {url}")
        except BlockedByRobots:
            print(f"BLOCKED by robots.txt               {url}")
        except httpx.HTTPStatusError as exc:
            print(f"{exc.response.status_code} {'':<18} {'':>8}        {url}")
        except httpx.HTTPError as exc:
            print(f"ERR {type(exc).__name__:<16} {'':>8}        {url}")
    fetcher.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
