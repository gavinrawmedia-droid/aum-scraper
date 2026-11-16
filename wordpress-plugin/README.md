# AUM Directory (WordPress plugin)

Turns the scraper's data into real pages on approvedusedmobility.co.uk.

| Page | Address |
| --- | --- |
| A listing | `/listing/<name>/` |
| All listings, cheapest first | `/listings/` |
| An equipment type | `/used/road-scooters/`, `/used/powerchairs/`, `/used/boot-scooters/` … |
| A brand | `/brand/pride/` |
| A dealer, with all its stock | `/dealers/<name>/` |
| Click-through to the dealer | `/go/<listing id>/` (recorded, then redirected) |

## What it does

- **Listings and dealers** as their own post types, with equipment type, condition and brand.
  Every figure from the scraper is a custom field (`aum_price_gbp`, `aum_range_miles` …),
  so Breakdance templates can use them as dynamic data.
- **Import endpoint** for the scraper at `/wp-json/aum-directory/v1/`. It accepts only users with
  the **Stock importer** role (or admins). That role can import stock and nothing else.
  Imports are idempotent: matching is on the scraper's ID, so sending the same data twice changes nothing.
- **Sold items come down by themselves.** When a dealer's listing disappears from the scraper's data, it
  moves to *Sold / removed*: hidden from the site but kept for history. If it comes back, it's republished.
  Safety net: if more than half a dealer's stock vanishes at once (and more than 10 items),
  nothing is taken down and Tools > AUM Directory shows a warning, because the scraper has probably broken.
- **Click tracking**: "See it on the dealer's website" goes through `/go/<id>/`, which counts the
  click and sends the visitor to the dealer's page for that item with UTM tags. Search engines
  and link previews aren't counted, and no personal data (IP, browser, cookies) is stored.
  Totals per dealer are under **Tools > AUM Directory**.
- **Structured data**: Product + Offer (used condition, GBP price, seller) on listings and
  LocalBusiness on dealers, as JSON-LD.
- **Works before any design**: listing and dealer pages show price, VAT note, key facts
  ("Not stated" where the dealer doesn't say), a spec-conflict warning, questions to ask the
  dealer, and the dealer's details. The same blocks are shortcodes for Breakdance:
  `[aum_listing_details]`, `[aum_go_button]`, `[aum_dealer_details]`,
  `[aum_listings equipment="road-scooters" brand="pride" dealer="mobility-world" limit="12"]`.
- The dealer's description text is **not** copied (it's their copyright). Photos are shown from
  the dealer's own site, credited. Get each dealer's OK before launch.

## Installing

1. Zip the `aum-directory` folder (or use `aum-directory.zip` from a release).
2. WordPress: **Plugins > Add New Plugin > Upload Plugin**, choose the zip, **Install Now**, **Activate**.
3. **Users > Add New User**: username `stock-importer`, any email you control, role **Stock importer**.
4. Open that user, scroll to **Application Passwords**, name it `GitHub scraper`, click
   **Add New Application Password** and copy the password it shows (it's only shown once).
5. GitHub repo **Settings > Secrets and variables > Actions > New repository secret**:
   - `AUM_WP_USER` = `stock-importer`
   - `AUM_WP_APP_PASSWORD` = the password from step 4
6. **Actions > Scrape dealer stock > Run workflow**. Stock appears under **Listings** in WordPress.

Never paste the application password anywhere else. To stop the importer, revoke it on the
user's profile or delete the user.

## Tested on

WordPress 7.2 beta with PHP 8.3, importing the 16 Nov 2026 data (223 listings, 6 dealers):
first import 10 seconds; re-import changes nothing; sold items come down and go back up;
the safety net holds; the importer can't create posts or change settings; anonymous calls get 401.
