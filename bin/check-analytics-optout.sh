#!/usr/bin/env bash
#
# Refuse to ship a module that collects search usage a merchant cannot decline.
#
# WHY THIS EXISTS. The storefront widget emits an anonymous usage beacon, and the
# merchant's control over it is one key in the config the module injects:
# `cfg.analytics`. The widget declines to emit ONLY on an explicit
# `cfg.analytics === false`.
#
# THE FAILURE MODE IS AN ABSENT KEY, NOT A WRONG ONE. An omitted key arrives as
# `undefined`, and `undefined !== false`, so leaving it out means always-on. That
# is what this module shipped in 1.0.0 and 1.1.0: there was no setting, the key
# was never sent, and the service issues the events token to every verified
# store — so there was no layer at which an OpenCart merchant could say no. Both
# other connectors have had the toggle since their first release. Nothing failed,
# nothing logged, and no amount of using the module would have shown it, because
# a control that was never built has no broken behaviour to observe.
#
# SO THE CHECK IS FOR PRESENCE ACROSS THE WHOLE CHAIN, and it is DERIVED PER
# MAJOR from `adapters/`, not from a list of files — a third major added later
# must wire the toggle on the day it is added rather than the day someone
# remembers this script exists. An omission is what is dangerous here, and a
# hand-written list of paths cannot see an omission it was never told about.
#
#   bin/check-analytics-optout.sh              # check the working tree
#   bin/check-analytics-optout.sh <path>       # check some other checkout
#   bin/check-analytics-optout.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

FAILED=0
fail() { red "FAIL  $*"; FAILED=1; }

SETTINGS='src/Settings.php'
WIDGET='src/Storefront/Widget.php'
ACTIONS='src/Admin/Actions.php'
SETTING_KEY='SHARE_SEARCH_DATA'

# Live (non-commented) occurrences of a fixed string in one file. Strips `//` and
# `#` line comments and `*`-prefixed docblock lines first, so a wiring that has
# been commented out reads as absent — which it is. Without this, the long
# explanatory comments this repo favours would satisfy every check by themselves.
live_hits() {
  sed -e 's://.*::' -e 's:#.*::' -e 's:^[[:space:]]*\*.*::' "$2" 2>/dev/null \
    | grep -cF "$1" || true
}

check_optout() {
  local root="$1" major majors_found=0 name

  # 1. The setting must exist, with a default. A key absent from the defaults
  #    table returns '' forever, which is falsey — so the toggle would read as
  #    "off" on every shop while the screen showed whatever the template hardcoded.
  if [ "$(live_hits "$SETTING_KEY" "$root/$SETTINGS")" -eq 0 ]; then
    fail "$SETTINGS has no live $SETTING_KEY default — the merchant's choice has nowhere to live"
  fi

  # 2. The widget must SEND the key, and send it from the setting.
  if [ "$(live_hits "'analytics'" "$root/$WIDGET")" -eq 0 ]; then
    fail "$WIDGET never sends an 'analytics' key — an absent key is undefined, which is not false, so the beacon is always on"
  elif [ "$(live_hits "$SETTING_KEY" "$root/$WIDGET")" -eq 0 ]; then
    fail "$WIDGET sends 'analytics' but never reads $SETTING_KEY — the value is hardcoded, so the toggle changes nothing"
  fi

  # 3. Something must be able to WRITE it. A read-only setting is a display.
  if [ "$(live_hits "$SETTING_KEY" "$root/$ACTIONS")" -eq 0 ]; then
    fail "$ACTIONS never writes $SETTING_KEY — the setting can be read but never changed"
  fi

  # 4. Every major must expose it. One directory per major.
  if [ ! -d "$root/adapters" ]; then
    fail "no adapters/ directory — cannot tell which majors this builds for"
    return
  fi

  while IFS= read -r major; do
    [ -n "$major" ] || continue
    majors_found=$((majors_found + 1))
    name="$(basename "$major")"

    # ⚠ THE CONTROLLER AND THE TEMPLATE, BOTH. Either alone is a dead control:
    # an endpoint no screen can reach, or a checkbox that posts nowhere. The
    # first draft of this guard checked only that the string appeared somewhere
    # under the major, and a tree with the action wired and no checkbox passed it.
    local controller_hits=0 template_hits=0 f
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      controller_hits=$((controller_hits + $(live_hits 'setShareSearchData' "$f")))
    done < <(find "$major" -type d -name admin -exec find {} -name '*.php' \; 2>/dev/null)

    while IFS= read -r f; do
      [ -n "$f" ] || continue
      template_hits=$((template_hits + $(live_hits 'ns-share-data' "$f")))
    done < <(find "$major" -name '*.twig' 2>/dev/null)

    if [ "$controller_hits" -eq 0 ]; then
      fail "adapters/${name}: no admin controller calls setShareSearchData() — this major cannot store the merchant's choice"
    fi
    if [ "$template_hits" -eq 0 ]; then
      fail "adapters/${name}: no template carries the ns-share-data control — this major offers the merchant no way to decline"
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
  local tmp good_out

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  printf 'self-test\n'

  mkdir -p "$tmp/good/src/Storefront" "$tmp/good/src/Admin" \
           "$tmp/good/adapters/oc3/upload/admin/controller" "$tmp/good/adapters/oc3/upload/admin/view/template" \
           "$tmp/good/adapters/oc4/admin/controller" "$tmp/good/adapters/oc4/admin/view/template"

  printf "private static \$defaults = array('SHARE_SEARCH_DATA' => true);\n" > "$tmp/good/src/Settings.php"
  printf "'analytics' => (bool) \$this->settings->get('SHARE_SEARCH_DATA'),\n" > "$tmp/good/src/Storefront/Widget.php"
  printf "public function setShareSearchData(\$on) { \$this->settings->update(array('SHARE_SEARCH_DATA' => (bool) \$on)); }\n" > "$tmp/good/src/Admin/Actions.php"
  for m in oc3/upload oc4; do
    printf "\$actions->setShareSearchData(\$on);\n" > "$tmp/good/adapters/${m}/admin/controller/nitrosearch.php"
    printf '<input type="checkbox" id="ns-share-data">\n' > "$tmp/good/adapters/${m}/admin/view/template/nitrosearch.twig"
  done

  good_out="$( check_optout "$tmp/good" 2>&1 )" || true
  case "$good_out" in
    *FAIL*)
      red "  the guard fired on a CORRECT tree:"
      printf '%s\n' "$good_out"
      exit 1
      ;;
  esac
  green "  ok  stays quiet on a fully wired tree"

  # ── Each of these is a real way the control can be absent. ─────────────────
  #
  #   name             | what it does to the good tree
  #   -----------------+----------------------------------------------------------
  #   no-key           | the widget stops sending 'analytics'  (the shipped defect)
  #   hardcoded        | the key is sent, but not from the setting
  #   no-default       | the setting has nowhere to live
  #   read-only        | nothing can write it
  #   no-checkbox      | one major has the endpoint and no control
  #   commented-out    | the control is present but commented
  try_evasion() {
    local label="$1" expect="$2" out
    out="$( check_optout "$tmp/bad" 2>&1 )" || true
    case "$out" in
      *"$expect"*) green "  ok  fires on: ${label}" ;;
      *) red "  the guard did NOT fire on: ${label}"; printf '%s\n' "$out"; exit 1 ;;
    esac
  }

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf "'badge' => false,\n" > "$tmp/bad/src/Storefront/Widget.php"
  try_evasion "the widget sends no analytics key (the 1.0.0/1.1.0 defect)" "never sends an 'analytics' key"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf "'analytics' => true,\n" > "$tmp/bad/src/Storefront/Widget.php"
  try_evasion "the key hardcoded rather than read from the setting" "the value is hardcoded"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf "private static \$defaults = array('EVENTS_TOKEN' => '');\n" > "$tmp/bad/src/Settings.php"
  try_evasion "no default for the setting" "has nowhere to live"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf "public function state() { return array(); }\n" > "$tmp/bad/src/Admin/Actions.php"
  try_evasion "the setting readable but never writable" "can be read but never changed"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '<p>no control here</p>\n' > "$tmp/bad/adapters/oc3/upload/admin/view/template/nitrosearch.twig"
  try_evasion "one major with the endpoint and no checkbox" "adapters/oc3"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '// <input type="checkbox" id="ns-share-data">\n' > "$tmp/bad/adapters/oc4/admin/view/template/nitrosearch.twig"
  try_evasion "the control present but commented out" "adapters/oc4"

  green "self-test passed"
}

case "${1:-}" in
  --self-test) self_test; exit 0 ;;
  "") check_optout "$ROOT" ;;
  *)  check_optout "$1" ;;
esac

if [ "$FAILED" -eq 0 ]; then
  green "ok    the usage beacon is declinable, on every major"
else
  exit 1
fi
