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

# The shared tree, listed once. Copied into both archives at the path each major's
# installer can actually reach.
copy_shared() {
    local dest="$1"
    mkdir -p "$dest"
    cp -R src/. "$dest/"
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
