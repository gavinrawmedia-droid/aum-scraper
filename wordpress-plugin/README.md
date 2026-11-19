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

## New in 0.6.1

- Dealer addresses with encoded characters now survive the import. WordPress's text cleaning
  deleted them, so GMS addresses that start with a ✅ (`/products/%E2%9C%85-...`) were saved as
  `/products/-...` and went to a 404. The next stock update after installing rewrites them.

## New in 0.6.0: Clearance, kept apart

Dealers mark some stock as clearance (in its title or tags, or by putting it in a collection or
page they call clearance). The scraper sends two flags with each listing:

- `clearance`: the dealer marks it as clearance. Approved-used items (used, reconditioned,
  ex-demo) with this flag stay on the main site and also appear in Clearance, with a
  "Clearance" label.
- `clearance_only`: clearance stock that isn't approved used: new end-of-line items (condition
  "New (end of line)") or items whose condition the dealer doesn't state. These appear **only**
  in the Clearance section and on their own listing pages. Every listing query on the site
  (archives, Breakdance loops, `[aum_listings]`, filter counts, the header menu, type counts,
  dealer counts) leaves them out unless it asks for `'aum_section' => 'clearance'` (or `'all'`).

The Clearance page is the page with the address `/clearance/`. It holds the same shortcodes as
the listings archive (`[aum_results_count]`, `[aum_filters]`, `[aum_active_filters]`,
`[aum_no_results]`), which switch to clearance stock there, plus `[aum_clearance_grid]` for the
cards and page numbers (`?pg=2`).

- `[aum_try_clearance]` goes under the archive's results. On the last page it says how many
  matching items are only in Clearance and links there with the same filters. The "nothing
  matches" message mentions matching clearance stock too.
- The Browse panel and the phone menu get a quiet "Clearance and end of line" link.
- New card fields: `aum_clearance_text` ("Clearance") and `aum_drop_text` ("Price dropped £150").
- New filter: "Reduced" (`?reduced=1`), a dealer's own "was" price or a price cut the scraper
  saw itself (the old price held for at least 7 days; shown for 14 days after the cut).
- The scraper holds clearance-only stock back until this version is installed.

## New in 0.5.0: the "Browse equipment" menu

- `[aum_type_menu]`: a "Browse equipment" button for the site header. It opens a full-width panel with the types in two groups, "Out and about" and "Around the home", each with a line icon and its number of listings, plus a "Find a dealer near you" postcode box and links to compare and to all listings. It opens on click and closes with Escape, a click outside or tabbing away.
- `[aum_type_menu layout="drawer"]`: the same types as a list for the phone Menu drawer, with counts.
- Types with no listings stay in the menu as "Coming soon" and become links by themselves when stock arrives. Counts are published listings only.
- `[aum_compare_link]`: a "Compare" link that shows how many items the visitor has picked.
- The postcode typed into the menu is passed to the dealers page in the visitor's browser (session storage), not in the web address, and the dealers page searches with it straight away.

## New in 0.4.2

- "Questions to ask the dealer" fit the equipment: stairlifts (survey, fitting, rail, buy-back), riser recliners (seat size, weight, delivery), beds, hoists and bath lifts; scooters and chairs keep the battery questions.

## New in 0.4.1

- Equipment types and conditions are checked once per plugin version and before each import, so a new type always has its proper name (0.4.0 named new types after their web address, such as "riser-recliners"). Names someone has changed by hand are kept.

## New in 0.4.0: equipment for the home

- New equipment types: stairlifts, riser recliner chairs, profiling beds, hoists and bath lifts. Their pages appear at `/used/stairlifts/`, `/used/riser-recliners/` and so on as soon as stock arrives.
- A new listing field, `aum_type_detail`: straight or curved for a stairlift, how many motors for a riser recliner. It shows on cards, in Key facts ("Stairlift type", "Motors") and as a row on the compare page.
- Key facts leave out top speed, range and heaviest part for home equipment unless the dealer states them.
- Stairlift prices always read "From £…", with a note that the final price depends on the staircase and to ask whether fitting is included.
- Dealers can have a "Before you visit" note (for example "Viewing by appointment only"), shown on the dealer page and in the dealer map list.

## New in 0.3.0: compare

- Visitors tick **Compare** on up to 3 listings (on listing cards and in the price box on a listing page). A bar along the bottom of the screen shows their picks, with a button to compare them side by side.
- `[aum_compare]` shows the comparison. Put it on a page with the address `/compare/`. Picks are read from the address (`/compare/?ids=12,34,56`), so a comparison can be bookmarked or sent to someone.
- The table marks the lowest price, furthest range, highest weight limit, lightest part to lift and longest warranty. Rows nobody states are left out, and an "Only show differences" switch hides rows that match.
- `[aum_compare_button]` adds the tick box to a Breakdance listing card (it uses the listing in the loop). `[aum_compare_button style="wide"]` gives a full-width version.
- Picks are kept in the visitor's own browser. Nothing is stored on the server and no cookies are set. The compare page is hidden from search engines.
- Distances on the dealer map now say they are straight-line distances.

## New in 0.2.0 (for the Breakdance design)

- **Filters from the address bar** on `/listings/`, `/used/<type>/` and `/brand/<brand>/`:
  `?type=road-scooters&brand=pride&dealer=mobility-world&condition=ex-demo&min_price=500&max_price=2000&warranty=1&sort=price_desc`
  (`sort` is `price_asc`, `price_desc`, `newest` or `year`). Items with no price go last when sorting by price.
- **Filter shortcodes** with live counts: `[aum_filters]`, `[aum_active_filters]`, `[aum_results_count]`, `[aum_no_results]`.
- **Dealer map**: `[aum_dealer_map]` shows every dealer on an OpenStreetMap map with a "nearest to my postcode"
  search (postcodes.io, nothing stored) and a list. `[aum_dealer_map dealer="current" search="no" list="no" height="320"]`
  on a dealer page shows just that dealer. Leaflet 1.9.4 is bundled (BSD licence), so no map code loads from other sites;
  only the map tiles come from OpenStreetMap.
- **Listing page blocks**: `[aum_gallery]` (all the dealer's photos with thumbnails), `[aum_buy_box]`, `[aum_key_facts]`,
  `[aum_questions]`, `[aum_dealer_box]`; dealer page: `[aum_dealer_contact]`.
- `[aum_listings]` accepts `equipment="current"`, `brand="current"`, `dealer="current"`, `exclude="current"` and `orderby="newest"`,
  and uses the same card as the Breakdance templates.
- **Ready-to-show fields** that Breakdance can bind as custom fields (worked out on the fly, nothing extra stored):
  listings `aum_price_text`, `aum_was_text`, `aum_saving_text`, `aum_year_text`, `aum_warranty_text`, `aum_card_specs`,
  `aum_condition_name`, `aum_equipment_name`, `aum_brand_name`, `aum_dealer_name`, `aum_dealer_town`, `aum_dealer_place`,
  `aum_dealer_link`, `aum_go_url`, `aum_photo_credit`; dealers `aum_address_full`, `aum_directions_url`, `aum_phone_link`,
  `aum_website_host`, `aum_listing_count_text`, `aum_stock_url`.
- The stylesheet now uses the brand colours and loads on every page (Breakdance keeps shortcodes outside the post content).

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
