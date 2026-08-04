# NitroSearch for OpenCart

Fast, typo-tolerant search for your OpenCart shop. Results appear as your shoppers type.

**Your shop's server is never in the search path.** Shopper queries go straight from the browser to
the search engine with a scoped, read-only key — they never come back through PHP. Search stays
fast even while your own server is busy, and this module is not on that path at all.

> **Status: early.** The storefront proof-of-control endpoint is in place and installs cleanly on
> both supported majors. Connecting a shop, syncing a catalogue and the storefront widget are not
> finished yet, so there is nothing useful to install for a live shop today.

## Supported versions

| | |
|---|---|
| **OpenCart 4** | 4.0.x – 4.1.x |
| **OpenCart 3** | 3.0.x |
| **PHP** | 8.1+ |

**Both majors are current, which is unusual and worth saying plainly.** OpenCart 3.0.5.0 was
released in December 2025 — eight months *after* 4.1.0.3 — so 3.x is not a legacy line being
tolerated here. The two are not compatible with each other: a controller's directory, class name,
namespace and base class all differ, and the two installers disagree about where an archive's
contents belong. There is no single archive both accept, so this repository builds **two**.

Everything that carries logic is shared between them, verbatim. Only the files whose shape the
framework dictates are written twice.

## Installing

Download the archive for your major from the [Releases](../../releases) page.

### OpenCart 4 — `nitrosearch.ocmod.zip`

1. **Do not rename the file.** OpenCart 4 uses the archive's *filename* as the extension code — it
   decides both the install directory and the PHP namespace it registers, and it ignores the name
   inside the archive. A renamed download installs to the wrong place and nothing works. This is an
   OpenCart behaviour and the module cannot detect or correct it from inside.
2. Admin → **Extensions → Installer** → **Upload**, choose the file, then **Install** (the green +).
3. Admin → **Extensions → Extensions**, choose **Modules**, find **NitroSearch**, and install it.
   **Step 3 is required, not optional** — OpenCart 4 does not register an extension's code until a
   module of it is installed, and until then the storefront half of this module is unreachable.

### OpenCart 3 — `nitrosearch-oc3-<version>.ocmod.zip`

1. Admin → **Extensions → Installer** → **Upload**, choose the file.
2. Admin → **Extensions → Extensions**, choose **Modules**, find **NitroSearch**, and install it.

The filename does not matter on OpenCart 3.

## Privacy

The module sends your catalogue — the same product data your shoppers already see — and nothing
else. It does not send customer records, addresses, payment details, or order contents. Order
attribution, when enabled, reports an order's *value* against an opaque reference derived from your
own install; the real order id never leaves your shop.

## Building from source

```bash
./bin/build-module.sh
```

Writes both archives to `dist/`. They are release assets rather than tracked files: any tagged
commit reproduces them.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

[GPL-3.0](LICENSE), matching OpenCart itself.
