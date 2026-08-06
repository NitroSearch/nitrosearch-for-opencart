# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **You can now turn off search usage sharing.** The storefront search panel reports anonymous
  usage — what shoppers searched for, what they clicked, and whether a search found nothing — so
  your dashboard can show which searches work and which end in a dead end. **Configure → Share
  anonymous search usage** switches it off, on both OpenCart 3 and OpenCart 4.

  **Until now there was no way to decline.** The setting did not exist, so the module never told
  the search panel either way, and the panel treats "not told" as yes. Both of our other connectors
  have had this switch since their first release; this one shipped 1.0.0 and 1.1.0 without it.

  **Upgrading changes nothing on its own.** The switch starts on, matching what your shop was
  already doing, and turning it off leaves storefront search working exactly as before — only the
  dashboard stops filling in.

### Changed

- **The module now has a test suite and continuous integration.** It had neither, which is how both
  the missing switch above and the missing heartbeat in 1.1.0 reached merchants: both were absences,
  and an absence has no broken behaviour to notice while using the module.

  `php tests/run.php` covers the shared code both OpenCart 3 and OpenCart 4 builds carry — the
  request signing, the proof-of-control hash, and the currency table that decides whether a price is
  1999 or 19.99. `./bin/build-module.sh` runs it before packaging, along with every guard in `bin/`,
  and CI runs the lot on PHP 8.1, 8.2 and 8.3. Nothing a merchant installs changes — no test
  material is in either archive.

## [1.1.0] — 2026-08-05

### Added

- **Your shop now keeps itself connected without anyone pressing anything.** Every few minutes,
  while your shop is being used or your scheduled sync runs, the module checks in with NitroSearch.
  Three things depend on it, and none of them used to happen on their own:

  - **Search keeps working.** The key your storefront searches with has a lifetime, and your shop
    now fetches a fresh one once a day — long before the old one runs out. Previously a shop that
    was simply left alone — which is a shop that is working properly — would eventually find
    storefront search returning nothing at all, with no error and nothing on the Configure screen
    to say why.
  - **NitroSearch can ask for your catalogue again**, and now your shop hears it. If an item was
    accepted but turned out to be unusable, this is what repairs it. The request is acted on once,
    even if confirming it fails and has to be retried.
  - **Verification completes on its own.** If NitroSearch confirms your shop while you are not
    looking at the screen, your storefront picks up its search key by itself.

  It costs a page view nothing: it runs after your shopper's page has been sent, has its own
  five-minute clock, and a slow or unreachable service cannot delay a page or interrupt a sync.

### Fixed

- **Requests no longer emit a deprecation notice on PHP 8.5.** The module closed each network
  handle explicitly, which has done nothing since PHP 8.0 and is deprecated as of 8.5. On a shop
  with error display switched on, the notice could be printed into the response — including the
  two endpoints that must answer with nothing but JSON.

## [1.0.0] — 2026-08-05

First release. **Both current OpenCart majors are supported** — 4.0.x–4.1.x and 3.0.x — from two
archives built out of one shared codebase. OpenCart 3.0.5.0 was released in December 2025, eight
months *after* 4.1.0.3, so neither line is a legacy one and neither is treated as such here.

### Added

- **Connect your shop to NitroSearch** from Extensions → Modules → NitroSearch. One screen: connect,
  check status, sync, disconnect. It shows your shop's address, the endpoint NitroSearch uses to
  confirm you control it, and your scheduled-sync address.
- **Your catalogue is indexed and kept up to date.** The first sync walks the whole catalogue in the
  background, a chunk at a time, so a large shop is not one long request that a hosting timeout can
  kill. After that, adding, editing, copying or deleting a product queues just that product.
- **Search on your storefront**, on your theme's existing search box, on every page. Shoppers get
  results as they type; on your shop's own search-results page the results grid is replaced with
  ours — same page, same address, your theme's heading and refine form left where they are.
  Add-to-cart works straight from the results, and a product with required options sends the shopper
  to its product page to choose them, exactly as your theme's own button does.
- **Scheduled sync.** Point your hosting control panel's cron at the address on the Configure screen
  and catalogue changes reach NitroSearch within minutes. It is safe to call as often as you like:
  each call does a fixed amount of work and stops.
- **Sync without a cron.** If you never set one up, an occasional storefront page view picks up the
  queue instead — after the page has been sent to the shopper, so nobody waits for it. Slower than a
  cron, and enough for a quiet shop.
- **Variations, specials and popularity are read correctly on both majors**, which store all three
  differently. The module asks your database what it actually has rather than guessing from a
  version number, so a forked or patched install is read correctly too.
- **One product is one indexed item**, however many variations it has.

### Notes for merchants

- **Do not rename the OpenCart 4 archive.** `nitrosearch.ocmod.zip` must keep exactly that name —
  OpenCart 4 takes the extension's code from the *filename*, and the code decides both where the
  files go and the name it registers internally. A renamed download installs somewhere nothing can
  find. The module cannot detect or correct this from the inside.
- **On OpenCart 4, installing the module is a separate step from uploading the archive**, and it is
  required. Until a module is installed, OpenCart 4 does not register the extension, and the
  storefront half of this one cannot answer. OpenCart 3 has no equivalent step.
- **The module indexes one catalogue**, in your shop's configured language and default currency,
  against the default store's settings. OpenCart's multi-store storefronts are not indexed
  separately.
- Uninstalling the **module** removes its settings and its queue table and disconnects the shop;
  your indexed catalogue is kept on the service, so reconnecting restores search without a re-sync.
  Uninstalling only the **archive** leaves settings alone.

[Unreleased]: https://github.com/NitroSearch/nitrosearch-for-opencart/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/NitroSearch/nitrosearch-for-opencart/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/NitroSearch/nitrosearch-for-opencart/releases/tag/v1.0.0
