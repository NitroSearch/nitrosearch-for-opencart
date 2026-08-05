# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/NitroSearch/nitrosearch-for-opencart/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/NitroSearch/nitrosearch-for-opencart/releases/tag/v1.0.0
