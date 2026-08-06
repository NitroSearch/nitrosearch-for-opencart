#!/usr/bin/env bash
#
# Build the two installable archives.
#
#   ./bin/build-module.sh            # both majors
#   ./bin/build-module.sh oc3        # one of them
#
# WHY TWO ARCHIVES AND NOT ONE. OpenCart 3 and OpenCart 4 are both current — 3.0.5.0
# was released eight months AFTER 4.1.0.3 — and they share almost no structure. A
# controller's directory, class name, namespace and base class all differ, one is
# typed and one is not, and the two installers disagree about where a ZIP's contents
# belong. There is no single archive both accept.
#
# WHAT IS SHARED IS EVERYTHING THAT MATTERS: src/ is framework-free PHP under the
# NitroSearch\ namespace and is copied verbatim into both. The adapters hold only the
# files whose shape the framework dictates. If a change lands in an adapter that could
# have lived in src/, it will have to be made twice, and that is the smell to watch.
#
# THE ARCHIVE LAYOUTS ARE NOT THE REPOSITORY LAYOUT, and getting that wrong fails
# silently rather than loudly:
#
#   OC3  <zip>/upload/…                → copied into the shop root. The installer
#                                        whitelists the destination prefixes, and
#                                        `system/library/` is one of them.
#   OC4  <zip>/install.json + <zip>/…  → everything is copied under
#                                        `extension/<code>/`, so paths inside the
#                                        archive are RELATIVE TO THAT, with no
#                                        `extension/<code>/` prefix of their own.
#
# Both installers reject anything not named `*.ocmod.zip`.
#
# ⚠ ON OPENCART 4 THE FILENAME IS THE EXTENSION CODE. `marketplace/installer` takes
# `basename($file, ".ocmod.zip")` as the code, ignoring the `name` in install.json —
# so it decides the install directory (`extension/<code>/`) AND the namespace it
# registers (`Opencart\Catalog\Controller\Extension\<Code>`). A first build named the
# archive `nitrosearch-oc4-1.0.0.ocmod.zip`; OpenCart recorded the code as
# `nitrosearch-oc4-1.0.0`, which is not even a legal namespace segment, and nothing
# was reachable. The OC4 archive is therefore named for the code and NOTHING ELSE —
# no version, no major — and the version lives in install.json where it belongs.
#
# The same applies to a merchant who renames the download. It is called out in the
# README because there is nothing the module can do about it from inside.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' adapters/oc4/install.json | head -n1)"
[ -n "$VERSION" ] || { echo "could not read the version from adapters/oc4/install.json" >&2; exit 1; }

OUT="dist"
mkdir -p "$OUT"

WANTED="${1:-all}"

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
die() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ── Guards, before anything is packaged ──────────────────────────────────────
#
# Each runs its own self-test first. A guard that has quietly stopped
# discriminating is worse than no guard, because it reads as coverage — so the
# build refuses to trust any of them until it has watched them fail on purpose.
#
# THE LIST IS DERIVED, NOT WRITTEN DOWN. It used to name two guards, and a third
# added beside them would have sat in bin/ running on nobody's machine while the
# build reported "Guards" and passed. Every bin/check-*.sh is a release gate by
# construction now, so adding one is one file rather than one file and a line
# here that is easy to forget.
say "Guards"

# ⚠ STALE ARCHIVES ARE REMOVED BEFORE ANYTHING CAN FAIL. `dist/` is created above,
# and the OpenCart 4 archive has a FIXED name — `nitrosearch.ocmod.zip`, because
# the filename is the extension code — so a build that aborts after a previous
# successful one leaves last version's archive sitting under the exact name that
# gets attached to a release. Nothing downstream can tell the difference.
rm -f "$OUT/nitrosearch.ocmod.zip" "$OUT"/nitrosearch-oc3-*.ocmod.zip

# Lint everything that ships, before it ships.
#
# This module had NO lint at all: a parse error anywhere in src/ built both
# archives, exited 0, and shipped the unparseable file to merchants — where it is
# a fatal on the first request that autoloads it. The sibling module has linted
# from the start; this is parity, and it earns its place the moment any file here
# is edited by hand.
if command -v php >/dev/null 2>&1; then
    while IFS= read -r file; do
        php -l "$file" >/dev/null 2>&1 || die "PHP syntax error in $file"
    done < <(find src adapters vendor -name '*.php' 2>/dev/null)
    ok "every PHP file parses"
else
    die "php is not on PATH — refusing to build unlinted (this module shipped a parse error once precisely here)"
fi

_guards_run=0
for guard in ./bin/check-*.sh; do
    [ -f "$guard" ] || continue
    _guards_run=$((_guards_run + 1))
    "$guard" --self-test >/dev/null \
        || die "$(basename "$guard") failed its own self-test — fix the guard before trusting this build"
    "$guard" \
        || die "$(basename "$guard") refused this tree (see above)"
done

# A loop over nothing passes, and "Guards" would print above it. If the glob ever
# stops matching, the build must stop rather than quietly package an ungated tree.
[ "$_guards_run" -ge 3 ] \
    || die "only ${_guards_run} guard(s) ran — bin/check-*.sh matched less than expected, so this build is not gated"

# ── The test suite, before anything is packaged ──────────────────────────────
#
# The bash guards above cover the adapters — that each major wires what it must.
# This covers `src/`, which is copied verbatim into both archives: a module that
# cannot reproduce the shared HMAC vector cannot talk to the service at all, and
# finding that out at package time beats finding it out as a 401 on a merchant's
# shop. `php` is already required above, so there is no fallback to skip it.
#
# Relative, because this script cd's to the repo root at the top and there is no
# $ROOT here — `set -u` caught that immediately, which is the whole reason it is
# set.
php tests/run.php || die "the test suite refused this tree (see above)"

# The shared tree, listed once. Copied into both archives at the path each major's
# installer can actually reach.
#
# THE VENDORED CONTRACT KIT IS FOLDED IN HERE, at `AdapterKit/`. It lives in the
# repository under `vendor/nitrosearch-contract/` so its provenance is obvious — it is
# generated elsewhere and copied in verbatim, never edited here — but it declares
# `NitroSearch\AdapterKit`, and `src/autoload.php` maps the whole `NitroSearch\` prefix
# to one directory. Placing it inside that directory at build time means the autoloader
# needs no second rule, and a merchant's install has no `vendor/` path to reason about.
#
# Vendored, never Composer-required: OpenCart merchants upload an archive through a
# back office, and a module that resolves dependencies at install time fails on the
# hosts least able to fix it.
copy_shared() {
    local dest="$1"
    mkdir -p "$dest"
    cp -R src/. "$dest/"
    mkdir -p "$dest/AdapterKit"
    cp -R vendor/nitrosearch-contract/src/. "$dest/AdapterKit/"
}

build_oc3() {
    say "OpenCart 3 — nitrosearch-oc3-${VERSION}.ocmod.zip"

    local stage
    stage="$(mktemp -d)"
    trap 'rm -rf "$stage"' RETURN

    cp -R adapters/oc3/upload "$stage/upload"

    # `system/library/` is inside OC3's installer whitelist; `vendor/` is not, which
    # is why the shared tree goes here rather than anywhere more conventional.
    copy_shared "$stage/upload/system/library/nitrosearch"

    ( cd "$stage" && zip -qr "$OLDPWD/$OUT/nitrosearch-oc3-${VERSION}.ocmod.zip" . -x '.*' -x '__MACOSX/*' )

    ok "$OUT/nitrosearch-oc3-${VERSION}.ocmod.zip"
}

build_oc4() {
    say "OpenCart 4 — nitrosearch-oc4-${VERSION}.ocmod.zip"

    local stage
    stage="$(mktemp -d)"
    trap 'rm -rf "$stage"' RETURN

    cp adapters/oc4/install.json "$stage/install.json"
    cp -R adapters/oc4/catalog "$stage/catalog"
    [ -d adapters/oc4/admin ] && cp -R adapters/oc4/admin "$stage/admin"

    # Lands at extension/nitrosearch/system/library/nitrosearch/ once installed, which
    # is what the OC4 controllers require through DIR_EXTENSION.
    copy_shared "$stage/system/library/nitrosearch"

    # NOT versioned, NOT suffixed — see the note at the top. The filename IS the code.
    ( cd "$stage" && zip -qr "$OLDPWD/$OUT/nitrosearch.ocmod.zip" . -x '.*' -x '__MACOSX/*' )

    ok "$OUT/nitrosearch.ocmod.zip  (code: nitrosearch — the filename decides it)"
}

case "$WANTED" in
    oc3) build_oc3 ;;
    oc4) build_oc4 ;;
    all) build_oc3; build_oc4 ;;
    *)   echo "usage: $0 [oc3|oc4]" >&2; exit 1 ;;
esac

say "Contents"
for f in "$OUT"/nitrosearch-oc3-"${VERSION}".ocmod.zip "$OUT"/nitrosearch.ocmod.zip; do
    [ -f "$f" ] || continue
    printf '\n%s\n' "$f"
    unzip -l "$f" | sed -n '4,$p' | head -n 40
done
