#!/usr/bin/env bash
#
# Refuse to ship a major whose unattended paths never talk to the service.
#
# WHY THIS EXISTS. Everything the module does on its own — renewing the scoped
# search key before it expires, noticing that the service has asked for a full
# re-send, picking up a key after out-of-band verification — happens in
# `Sync\ResyncCheck`. Nothing calls it on its own; it has to be wired into the
# paths that run without a merchant present, and there are two of those per
# major: the cron endpoint, and the page-load fallback for shops with no cron.
#
# THE FAILURE MODE IS SILENCE. A major wired without it looks completely healthy:
# the catalogue syncs, the Configure screen says connected, and the storefront
# searches — right up until the key expires, at which point search returns
# nothing and there is still no error anywhere. The module shipped its first
# release in exactly that state, on both majors, and no amount of using it would
# have shown the problem inside a year.
#
# THE CHECK IS DERIVED PER MAJOR, not from a list of files. `adapters/` holds one
# directory per OpenCart major, so a third major added later is required to wire
# the heartbeat on the day it is added rather than the day someone remembers this
# script exists. That is the whole point: the omission is what is dangerous, and
# a hand-written list of paths cannot see an omission it was never told about.
#
#   bin/check-heartbeat.sh              # check the working tree
#   bin/check-heartbeat.sh <path>       # check some other checkout
#   bin/check-heartbeat.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

FAILED=0
fail() { red "FAIL  $*"; FAILED=1; }

# The shared implementation, and the call every wiring site has to make.
HEARTBEAT_CLASS='src/Sync/ResyncCheck.php'

# ⚠ THE PATTERN IS THE WHOLE CHAINED CALL, NOT THE ACCESSOR. An earlier version of
# this script grepped for `resyncCheck()` alone, recursively, over the whole
# `adapters/<major>/` tree — and passed every one of these:
#
#   • the call present but COMMENTED OUT
#   • the accessor called and its result thrown away (no `->maybeRun()`)
#   • the call wired into the ADMIN controller only, which is a merchant pressing a
#     button — the exact "nothing runs unattended" state this guard exists to catch
#   • the call in a file `build-module.sh` does not package
#
# All four were demonstrated against it. So the pattern now requires the chained
# call, the search is restricted to each major's CATALOG (storefront/cron) tree
# rather than the whole adapter, and commented lines are stripped before matching.
HEARTBEAT_CALL='resyncCheck()->maybeRun()'

# Live (non-commented) occurrences of the chained call in one file.
#
# Strips `//` and `#` line comments and `*`-prefixed docblock lines before
# matching, so a call that has been commented out reads as absent — which it is.
live_calls() {
  sed -e 's://.*::' -e 's:#.*::' -e 's:^[[:space:]]*\*.*::' "$1" 2>/dev/null \
    | grep -cF "$HEARTBEAT_CALL" || true
}

check_heartbeat() {
  local root="$1" major majors_found=0 name catalog hits

  # 1. The shared implementation must exist at all. Without this the per-major
  #    checks below could pass against a module that cannot do any of it.
  if [ ! -f "$root/$HEARTBEAT_CLASS" ]; then
    fail "$HEARTBEAT_CLASS is missing — the module has no unattended heartbeat"
    return
  fi

  # 1b. And it must actually renew the key, not merely poll. `/v1/status` cannot
  #     carry a key, so a heartbeat that only ever calls it leaves every shop on
  #     the key it was issued at onboarding until that key expires — which is the
  #     silent outage this whole mechanism exists to prevent, reintroduced.
  if ! grep -q 'fetchSearchKey' "$root/$HEARTBEAT_CLASS"; then
    fail "$HEARTBEAT_CLASS never calls fetchSearchKey() — it polls but never renews the search key"
  fi

  # 1c. And the renewal needs its OWN clock. Hanging it off the poll's stamp makes
  #     it a backfill again — the fetch happens only as a side effect of a poll, so
  #     a failing status endpoint silently suppresses the one job whose absence
  #     kills storefront search.
  if ! grep -q 'REFRESH_INTERVAL' "$root/$HEARTBEAT_CLASS"; then
    fail "$HEARTBEAT_CLASS has no REFRESH_INTERVAL — the key refresh has no clock of its own"
  fi

  # 2. The page-load fallback is shared across majors, so it is checked once.
  if [ "$(live_calls "$root/src/Sync/PageLoadTick.php")" -eq 0 ]; then
    fail "src/Sync/PageLoadTick.php has no live '$HEARTBEAT_CALL' — shops without cron get no heartbeat"
  fi

  # 3. Every major's own controllers must reach it. One directory per major.
  if [ ! -d "$root/adapters" ]; then
    fail "no adapters/ directory — cannot tell which majors this builds for"
    return
  fi

  while IFS= read -r major; do
    [ -n "$major" ] || continue
    majors_found=$((majors_found + 1))
    name="$(basename "$major")"

    # ⚠ CATALOG ONLY. An admin controller is a merchant pressing a button, which is
    # exactly what this module already had and exactly what is not enough. The
    # unattended paths live under catalog/ on both majors — OC3 nests it beneath
    # `upload/`, OC4 does not, so the search is for a `catalog` directory at any
    # depth rather than a fixed path.
    hits=0
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      hits=$((hits + $(live_calls "$f")))
    done < <(find "$major" -type d -name catalog -exec find {} -name '*.php' \; 2>/dev/null)

    if [ "$hits" -eq 0 ]; then
      fail "adapters/${name}: no CATALOG controller has a live '$HEARTBEAT_CALL' — this major has no unattended heartbeat (an admin-only call does not count)"
    fi
  done < <(find "$root/adapters" -mindepth 1 -maxdepth 1 -type d | sort)

  # A loop over nothing passes. OpenCart maintains two current majors and this
  # module builds for both, so finding fewer than two means the tree is not what
  # this check was written against and its silence means nothing.
  if [ "$majors_found" -lt 2 ]; then
    fail "found ${majors_found} major(s) under adapters/ — expected at least 2; this check is not looking at what it thinks it is"
  fi
}

# ── Self-test ────────────────────────────────────────────────────────────────
#
# TWO DIRECTIONS. A check that fires on the bad tree might fire on everything;
# one that passes the good tree might pass everything. Only both together say it
# discriminates.
#
# ⚠ The verdict is read out of the OUTPUT, not out of `$FAILED` — running a check
# inside `$( … )` puts it in a subshell and the flag it sets is discarded.
self_test() {
  local tmp bad_out good_out

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  printf 'self-test\n'

  # A good tree, shaped like the real one: OC3 nests catalog/ under upload/, OC4
  # does not, and both majors carry an admin/ tree as well.
  mkdir -p "$tmp/good/src/Sync" \
           "$tmp/good/adapters/oc3/upload/catalog/controller" "$tmp/good/adapters/oc3/upload/admin/controller" \
           "$tmp/good/adapters/oc4/catalog/controller" "$tmp/good/adapters/oc4/admin/controller"
  printf 'final class ResyncCheck { const REFRESH_INTERVAL = 86400; function r() { $this->client->fetchSearchKey(); } }\n' \
      > "$tmp/good/src/Sync/ResyncCheck.php"
  for f in "src/Sync/PageLoadTick.php" \
           "adapters/oc3/upload/catalog/controller/cron.php" \
           "adapters/oc4/catalog/controller/cron.php"; do
    printf '$runner->resyncCheck()->maybeRun();\n' > "$tmp/good/$f"
  done
  printf '$runner->drain()->run(20);\n' > "$tmp/good/adapters/oc3/upload/admin/controller/nitrosearch.php"
  printf '$runner->drain()->run(20);\n' > "$tmp/good/adapters/oc4/admin/controller/nitrosearch.php"

  good_out="$( check_heartbeat "$tmp/good" 2>&1 )" || true
  case "$good_out" in
    *FAIL*)
      red "  the guard fired on a CORRECT tree:"
      printf '%s\n' "$good_out"
      exit 1
      ;;
  esac
  green "  ok  stays quiet on a fully wired tree"

  # ── Each of these BEAT the first version of this guard. ────────────────────
  #
  # They are listed as one table because the point is not any single evasion but
  # that a bare recursive grep for an accessor name cannot see any of them.
  #
  #   name              | what it does to the good tree
  #   ------------------+---------------------------------------------------------
  #   commented         | comments the call out
  #   unchained         | calls the accessor and discards the result
  #   admin-only        | moves the call from catalog/ to admin/
  #   missing-class     | deletes the shared implementation
  #   poll-only         | keeps everything, but the heartbeat never fetches a key
  try_evasion() {
    local label="$1" expect="$2" out
    out="$( check_heartbeat "$tmp/bad" 2>&1 )" || true
    case "$out" in
      *"$expect"*) green "  ok  fires on: ${label}" ;;
      *) red "  the guard did NOT fire on: ${label}"; printf '%s\n' "$out"; exit 1 ;;
    esac
  }

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '// $runner->resyncCheck()->maybeRun();\n' > "$tmp/bad/adapters/oc3/upload/catalog/controller/cron.php"
  try_evasion "the call commented out" "adapters/oc3"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '$x = $runner->resyncCheck();\n' > "$tmp/bad/adapters/oc4/catalog/controller/cron.php"
  try_evasion "the accessor called but never run" "adapters/oc4"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '$runner->drain()->run(20);\n' > "$tmp/bad/adapters/oc3/upload/catalog/controller/cron.php"
  printf '$runner->resyncCheck()->maybeRun();\n' > "$tmp/bad/adapters/oc3/upload/admin/controller/nitrosearch.php"
  try_evasion "wired into admin only — a merchant pressing a button" "adapters/oc3"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  rm -f "$tmp/bad/src/Sync/ResyncCheck.php"
  try_evasion "no heartbeat implementation at all" "no unattended heartbeat"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf 'final class ResyncCheck { function r() { $this->client->status(); } }\n' \
      > "$tmp/bad/src/Sync/ResyncCheck.php"
  try_evasion "a heartbeat that polls but never renews the key" "never renews"

  # The shape the FIRST version of this fix had: it fetches, but only as a side
  # effect of the poll, so a failing status endpoint suppresses the renewal.
  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf 'final class ResyncCheck { function r() { if ($k === "") { $this->client->fetchSearchKey(); } } }\n' \
      > "$tmp/bad/src/Sync/ResyncCheck.php"
  try_evasion "a renewal with no clock of its own — a backfill wearing the name" "no REFRESH_INTERVAL"

  green "self-test passed"
  exit 0
}

if [ "${1:-}" = "--self-test" ]; then
  self_test
fi

if [ -n "${1:-}" ]; then
  ROOT="$(cd "$1" && pwd)"
fi

check_heartbeat "$ROOT"

if [ "$FAILED" -ne 0 ]; then
  red "heartbeat wiring check FAILED"
  exit 1
fi

green "ok    the unattended heartbeat is wired on every major and on the page-load fallback"
