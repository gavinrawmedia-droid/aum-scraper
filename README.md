# Approved Used Mobility – dealer stock scraper

Collects the used and ex-demo stock that UK mobility dealers publish on their own
websites. It turns each listing into one clean record and writes files the website can
read. It is built to run once a day on GitHub Actions (free), and you can also run it
on any computer with Python 3.10+.

## What it does on each run

1. Reads `dealers.yaml` for the list of dealers and where their used stock lives.
2. Fetches each dealer's stock in the gentlest way available:
   - **Shopify stores** (5 of the 6 so far) publish their catalogue as JSON, so no page scraping is needed.
   - **Mobility Giant** has no feed, so the scraper reads its category listing pages.
3. Cleans every listing: brand, year, category, condition, price, "was" price,
   warranty, max user weight, range, top speed and heaviest part.
4. Keeps history: when a listing first appeared, when it was last seen, and price changes.
   An item missing from **two runs in a row** is marked removed (sold). Nothing unseen
   for 7 days is ever shown.
5. Writes the outputs and a health report, then flags any dealer whose site seems to have changed.

## Ground rules built into the code

- **Only what the dealer says.** Specs come from the dealer's own wording. If a figure
  isn't there, it's left empty for the site to show "Not stated". Nothing is invented.
- **Contradictions are flagged, not hidden.** If a dealer's description gives two
  different figures (one Seacroft listing pastes in another scooter's spec table, for
  example), the first figure is used and `spec_note` tells the buyer to check.
- **"Was" prices** are only kept when they're above the current price. Some stores
  hold junk values like £200 against a £1,199 scooter.
- **Polite fetching.** It identifies itself as `ApprovedUsedMobilityBot`, waits 3
  seconds between requests to the same site and retries gently. It **obeys robots.txt
  including wildcards**. For example, Mobility World's robots.txt blocks any URL with
  `page=`, so the scraper asks for page 1 without it and never pages further.
- Parts and accessories (e.g. spare battery packs), items listed as new and anything
  under £300 are left out.

## Outputs (in `data/`)

| File | What it is |
| --- | --- |
| `listings.json` | Every live listing, ready for the website to read |
| `listings.csv` | The same, for opening in Excel or Google Sheets |
| `dealers.json` | Dealer details, listing counts, and map coordinates (with `--geocode`) |
| `health.json` | Per-dealer status: ok / warning / error, item counts, and what went wrong |
| `state.json` | History used to track first-seen, last-seen, sold and price changes |

Each listing also says whether the dealer marks it as **clearance** (`clearance`), whether it
belongs in the site's Clearance section only (`clearance_only`: new end-of-line stock, or a
clearance item whose condition isn't stated), and whether it is **reduced** (`reduced`: the
dealer's own "was" price, or a price cut the scraper saw itself, recorded in
`price_drop_from_gbp` / `price_dropped_at` when the old price had held for a week).

## Running it

```bash
pip install -r requirements.txt
python -m aum_scraper run                    # live run, all dealers
python -m aum_scraper run --only aspire2     # one dealer
python -m aum_scraper run --geocode          # also look up dealer postcodes (postcodes.io)
python -m aum_scraper run --offline fixtures # re-run on the saved test captures, no internet
python -m pytest -q                          # run after any change
```

## Running it every day automatically

This repository's `.github/workflows/scrape.yml` runs it
every morning at 05:17 (UK winter time) and commits the updated `data/` folder. You can
also start it by hand from the Actions tab. If any dealer errors, the run is marked as
failed and GitHub emails you.

## Sending stock to WordPress

The `wordpress-plugin/aum-directory` plugin gives the site real pages for listings, dealers
and equipment types, and an import endpoint. After each scrape the daily run sends the data
there, once two GitHub secrets exist (`AUM_WP_USER`, `AUM_WP_APP_PASSWORD`).
`wordpress-plugin/README.md` has the setup steps. By hand:

```bash
AUM_WP_USER=stock-importer AUM_WP_APP_PASSWORD='...' python -m aum_scraper push
```

## Adding a dealer

1. Check whether their site is Shopify: open `https://their-site/products.json` in a
   browser. If you see product data, it's Shopify.
2. Find the used or ex-demo collection. The URL looks like `/collections/approved-used-scooters`.
3. Copy a Shopify block in `dealers.yaml`, fill in the details and list the collection handles.
4. Run `python -m aum_scraper run --only <their-id>` and check the output.

Non-Shopify sites (WooCommerce, custom builds) each need a small adapter in
`aum_scraper/adapters/`. `mobility_giant.py` is the example to copy.

## Current dealers

| Dealer | Town | Platform | Where the used stock comes from |
| --- | --- | --- | --- |
| Active Mobility | Stratford-upon-Avon | Shopify | pre-owned scooters and electric wheelchairs collections |
| Aspire2 Mobility | Oakham | Shopify | whole store, filtered to pre-owned / ex-demo items |
| Mobility Solutions | Glasgow | Shopify | whole store, product type "Used" (their "Trade" stock is excluded) |
| Mobility World | Letchworth & Harrow | Shopify | approved used scooters and power wheelchairs collections |
| Seacroft Mobility | Skegness / Lincolnshire | Shopify | "All Used Products" collection |
| Mobility Giant | Sevenoaks | HTML pages | four scooter category pages |

## Known gaps, next to build

- **Mobility Solutions and Mobility Giant specs and RRP** are only on each product page, not
  in the feed or listing cards. An optional "read the product page" step would add them,
  at about one extra request per listing.
- **Multiple identical units** (e.g. nine Kymco K-Lite FE at Mobility Giant) are separate
  listings. The website may want to group them.
- **Push to WordPress**: a small step to create and update listings through the
  WordPress REST API once the listing post type exists.
