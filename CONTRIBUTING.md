# Contributing

Thanks for looking. Bug reports and pull requests are welcome.

## The one structural rule

**Logic goes in `src/`. Only what the framework dictates goes in an adapter.**

```
src/                  shared, framework-free PHP under the NitroSearch\ namespace
adapters/oc3/upload/  the OpenCart 3 file tree
adapters/oc4/         the OpenCart 4 file tree
bin/build-module.sh   assembles both archives
```

`src/` is copied verbatim into both archives. It must not reference an OpenCart class, constant or
base class — anything it needs is passed in. `Settings` takes OpenCart's own `$db`, for example,
because `query()`/`escape()` and the `oc_setting` schema are identical in both majors; that is the
exception rather than the rule, and it is written down in the file.

**If a change lands in an adapter that could have lived in `src/`, it will have to be made twice.**
That is the smell to watch for in review, and the reason the split exists.

## Why two builds

OpenCart 3 and OpenCart 4 are both current — 3.0.5.0 shipped eight months after 4.1.0.3 — and they
share almost no structure:

| | OpenCart 3 | OpenCart 4 |
|---|---|---|
| controller path | `catalog/controller/extension/…` | `extension/<code>/catalog/controller/…` |
| class | `ControllerExtensionNitrosearchModuleNitrosearch` | `Nitrosearch` in a namespace |
| base class | `Controller` | `\Opencart\System\Engine\Controller` |
| route → method | last path segment | text after the last **dot** |
| autoloading | none; file presence is enough | namespace registered at runtime, per **installed** extension |

The last row has a consequence worth knowing before you test anything: on OpenCart 4 an extension's
classes do not autoload until the extension is registered in the database, which happens when a
**module** of it is installed — not when the archive is uploaded.

## Testing

```bash
php tests/run.php        # the suite — no Composer, no PHPUnit, no network, no shop
./bin/build-module.sh    # lints, runs every bin/check-*.sh guard, runs the suite, packages both
```

The suite covers `src/`, which is copied verbatim into both archives — so one run covers both
majors. It covers the pure parts where being wrong is silent and expensive: the HMAC
canonicalisation (a drift there is a 401, not a negotiation), the proof-of-control hash, and the
vendored currency exponent table that decides whether a price is 1999 or 19.99.

It deliberately uses no Composer and no PHPUnit. This module ships as an archive with no build step
and no dependencies, and a dev-only dependency would mean a lockfile and a packaging rule to keep it
out.

**It cannot boot OpenCart.** The adapters, the catalogue walk and the drain are not covered by it.
The guards in `bin/` cover the adapters instead, by checking that each major wires what it must —
run any of them with `--self-test` to watch it fail on purpose before you trust it.

**And for everything else: install the built archive into a shop that has never seen the module.**
Not a copied file, not a symlink, not a bind mount. Packaging is where this repository can fail
silently, and none of those can show you a packaging bug — a file you placed yourself proves only
that the code runs, which was never the part in doubt.

Two live examples of what that catches:

- OpenCart 4 takes the extension code from the **archive filename**, ignoring `install.json`. An
  archive named `nitrosearch-oc4-1.0.0.ocmod.zip` registers the code `nitrosearch-oc4-1.0.0`, which
  is not a legal namespace segment, and nothing resolves. The archive must be named for the code
  and nothing else.
- Both installers reject any file not ending in `.ocmod.zip`, and OpenCart 3 additionally
  whitelists the destination prefixes it will write to.

## Code style

Match the surrounding code. Comments explain *why* — particularly where the two majors differ,
because the reason is rarely recoverable from the line itself.

## Reporting a bug

Please include your OpenCart version (both the major and the point release), your PHP version, and
your theme if the problem is on the storefront.
