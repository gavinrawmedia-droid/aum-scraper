"""Command line:  python -m aum_scraper run [options]"""
from __future__ import annotations

import argparse
import sys

from .pipeline import run


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="aum_scraper", description="Collect approved-used stock from dealer websites.")
    sub = p.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("run", help="scrape every dealer in dealers.yaml")
    r.add_argument("--dealers", default="dealers.yaml", help="dealer config file")
    r.add_argument("--out", default="data", help="folder for listings.json/csv, dealers.json, health.json")
    r.add_argument("--offline", metavar="FIXTURES_DIR", help="read saved captures instead of the live sites")
    r.add_argument("--only", help="comma-separated dealer ids to run")
    r.add_argument("--geocode", action="store_true", help="look up dealer postcodes with postcodes.io")
    args = p.parse_args(argv)

    health = run(args.dealers, args.out, offline_dir=args.offline,
                 only=args.only.split(",") if args.only else None, do_geocode=args.geocode)

    print(f"\n{'Dealer':<22}{'Status':<10}{'Items':>6}  Notes")
    for dealer_id, h in health["dealers"].items():
        dropped = ", ".join(f"{n} {k}" for k, n in h["dropped"].items()) if h.get("dropped") else ""
        notes = "; ".join(h["messages"] + ([f"dropped: {dropped}"] if dropped else []))
        print(f"{dealer_id:<22}{h['status']:<10}{h['count']:>6}  {notes}")
    print(f"\nTotal live listings: {health['total_listings']}")
    return 1 if any(h["status"] == "error" for h in health["dealers"].values()) else 0


if __name__ == "__main__":
    sys.exit(main())
