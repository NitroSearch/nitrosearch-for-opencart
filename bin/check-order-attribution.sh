#!/usr/bin/env bash
#
# Refuse to ship order attribution that is wired on one major and not the other,
# or that reaches the network from a shopper's checkout.
#
# WHY THIS EXISTS. Attribution is the only feature in this module whose write path
# runs INSIDE a shopper's checkout request, and whose read path runs somewhere else
# entirely. Three things have to line up per major — the handler has to exist, an
# `oc_event` row has to point at it, and the queue has to be flushed from an
# unattended path — and every one of them fails silently when it is missing. The
# table stays empty, the merchant's dashboard shows no attributed revenue, and
# nothing anywhere logs a word.
#
# THE SHAPE OF THE FAILURE IS ALREADY ON THE RECORD, on the sibling connector.
# There, the observer for order placement was registered in the area file that sat
# next to the add-to-cart observer, because the two read like a natural pair. The
# platform's own one-page checkout does not place orders in that area, so the
# handler existed, was registered, looked right in review, and never ran. Three real
# orders went through before anyone looked at the table and found zero rows. Here
# the equivalent is cheaper to make and just as invisible: this module ships TWO
# archives from TWO adapter trees, and OpenCart 3 and OpenCart 4 disagree about the
# trigger separator, the controller path, the class name and even the name of the
# order-history method. Wiring one of them and believing you wired both is a single
# forgotten paste.
#
# ⚠ IT IS UNREACHABLE BY THE PHP SUITE, BY CONSTRUCTION. `tests/run.php` covers
# `src/`, which is copied verbatim into both archives, so a test there proves
# nothing about either adapter — reaching an adapter means booting OpenCart. The
# adapters are exactly where "this major forgot to wire X" lives, and a bash guard
# reading them is the only thing between that and a merchant.
#
# THE MAJOR LIST IS DERIVED FROM `adapters/`, NEVER WRITTEN DOWN, and the handler
# list is derived as the UNION over every major. Two consequences, both deliberate:
#
#   • A third major added later must wire attribution on the day it is added,
#     rather than the day someone remembers this script exists.
#   • A handler added to ONE major — `onOrderRefunded` on OpenCart 4, say — makes
#     every other major fail until it has one too. The guard fails toward
#     inclusion, because the omission is the dangerous direction and a guard told
#     only about the handlers that already exist cannot see the one that does not.
#
# WHAT THIS GUARD CANNOT SEE, and no reading of it should suggest otherwise:
#
#   • WHETHER THE TRIGGER STRINGS ARE CORRECT. A typo in `catalog/model/checkout/
#     order.addHistory/after` registers a row that never fires. The guard is green,
#     the report table stays empty, and the merchant sees zero revenue. Only placing
#     a real order on a real shop of each major can catch it.
#   • WHETHER THE MONEY IS RIGHT. Two shipped connectors multiply a float by 100 for
#     every currency, so a JPY store reports a hundred times its revenue. That is a
#     plausible-looking number in a well-formed payload; nothing textual can tell.
#   • WHETHER THE MARKER IS ACTUALLY READ at add-to-cart, or survives to order
#     placement.
#   • WHETHER A BAIL IS REACHABLE. The order-id check below is a text match on a
#     comparison, so a branch that can never run would satisfy it.
#
# Those are the sandbox steps, and they gate the release; this script gates the
# build. They are not substitutes for one another.
#
#   bin/check-order-attribution.sh              # check the working tree
#   bin/check-order-attribution.sh <path>       # check some other checkout
#   bin/check-order-attribution.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

FAILED=0
fail() { red "FAIL  $*"; FAILED=1; }

# The shared implementation. `src/` is copied verbatim into both archives, so these
# are checked once and cover every major at the same time.
ATTRIBUTION='src/Sync/OrderAttribution.php'   # capture, resolve, promote — checkout path
REPORTS='src/Sync/OrderReports.php'           # the durable queue and the ONLY sender
EVENTS='src/Sync/Events.php'                  # trigger and code definitions
TICK='src/Sync/PageLoadTick.php'              # the no-cron fallback
RUNNER='src/Sync/Runner.php'                  # assembles both

# The chained call every flush site has to make. THE WHOLE CHAIN, not the accessor:
# a sibling guard in this directory records that grepping for an accessor name alone
# passed a call that was commented out, a call whose result was thrown away, and a
# call wired into the admin controller only.
FLUSH_CALL='orderReports()->flush('

# The handlers attribution cannot work without. This is a FLOOR, not the list: the
# list is the union of what the majors actually define (see `required_handlers`).
# The floor is what stops a tree that defines nothing at all from passing a loop
# over nothing.
FLOOR_HANDLERS='onCartAdd onOrderCreated onOrderConfirmed'

# ── Reading PHP without executing it ─────────────────────────────────────────
#
# COMMENTS ARE STRIPPED BEFORE ANYTHING IS MATCHED, and that is not tidiness. This
# repo writes long docblocks that name the defect a piece of code exists for — the
# handler docblocks say "EVERY PARAMETER HAS A DEFAULT" and the queue's says
# "`occurred_at` IS READ FROM THE ROW AND NEVER RECOMPUTED" — so a guard reading raw
# text finds every string it is looking for, and every string it forbids, inside the
# prose explaining the rule. It would pass a tree that only talks about the rule and
# condemn one that also follows it. The sibling connector's attribution guard learned
# this the same way.
strip_comments() {
  sed -e 's://.*::' -e 's:#.*::' -e 's:^[[:space:]]*\*.*::' -e 's:^[[:space:]]*/\*.*::' "$1" 2>/dev/null
}

# ── Why nothing here pipes into `grep -q` ────────────────────────────────────
#
# `PRODUCER | grep -q PATTERN` is unsafe in any script that sets `pipefail`, and
# this one does. `grep -q` exits the instant it matches — that is the whole point
# of the flag — which closes the pipe while the producer is still writing. The
# producer is then killed by SIGPIPE and exits 141, and `pipefail` reports the
# LAST non-zero status as the pipeline's. So the pipeline returns 141 **because
# the pattern matched**, and `if ! ...` turns that into a failure.
#
# It is a race against the pipe buffer, which is what made it expensive:
#
#   $ strip_comments src/Sync/OrderAttribution.php | grep -qE '...'
#   PIPESTATUS=(141 0)   # sed killed, grep matched → pipeline "fails"
#   PIPESTATUS=(0 0)     # same command, same tree, one run later → passes
#
# A small producer usually wins the race, so this passed for months on the short
# `printf` blocks and on macOS, then failed on Linux CI against a 656-line file.
# **It took OpenCart `1.3.0` and Magento `1.0.1` out to merchants on red CI**, and
# the message it printed accused correct code of a defect it did not have.
#
# `qgrep` reads its input to the end. That costs nothing on files this size and
# it cannot race. Use it anywhere a producer pipes into a boolean grep; pass the
# same flags you would have passed `grep` (`-E`, `-F`, `-v`), just not `-q`.
qgrep() { grep -c "$@" >/dev/null; }

# Live (non-commented) occurrences of a fixed string in one file.
# `grep -c` already reads to the end, so this was never exposed to the above.
live_hits() {
  strip_comments "$2" | grep -cF "$1" || true
}

# Live occurrences of an IDENTIFIER — the name on its own, not as a fragment of a
# longer one.
#
# ⚠ WHY THIS IS NOT `live_hits`, found 2026-08-10 while proving the registration
# check on the real tree rather than on the self-test's fixtures. A substring match
# accepts any name that CONTAINS the handler's, and the dangerous direction is the
# one that reads as a typo: registering the action
#
#     extension/nitrosearch/module/nitrosearch.onCartAddd
#
# against a controller that defines `onCartAdd` satisfied `grep -F onCartAdd`, so
# the guard went green on a tree whose add-to-cart event points at a method that
# does not exist. That is worse than the omission this check was written for — an
# unregistered handler does nothing, while a row pointing at a missing method is
# resolved on EVERY add-to-cart in the shop, which is to say on the checkout path.
# The deleted-line case was caught; only the typo slipped, and a typo is the likelier
# of the two to be written.
#
# Bounded on BOTH sides: `onCartAddd` must not satisfy `onCartAdd`, and neither must
# `myOnCartAdd`. The separators either side in real registrations are `.` `/` `'`,
# none of which is an identifier character.
live_word_hits() {
  strip_comments "$2" | grep -cE "(^|[^A-Za-z0-9_])$1([^A-Za-z0-9_]|$)" || true
}

# One method's source, signature line first, from `function <name>(` to the brace
# that closes it.
#
# ⚠ EXTRACTED, NOT GREPPED, AND THAT DISTINCTION IS THE WHOLE POINT OF CHECK 6.
# `OrderReports` legitimately calls `gmdate()` — the stale sweep has to build a
# cutoff from the current time. A guard that grepped the file for `gmdate` would go
# red on correct code, and a guard that goes red on correct code gets deleted.
method_block() {
  strip_comments "$1" | awk -v m="$2" '
    !inside && $0 ~ ("function[ \t]+" m "[ \t]*\\(") { inside = 1 }
    inside && !done {
      print
      o = gsub(/\{/, "{"); c = gsub(/\}/, "}")
      depth += o - c
      if (o > 0) opened = 1
      if (opened && depth <= 0) done = 1
    }
  '
}

# Every PHP file in a major's CATALOG tree.
#
# CATALOG ONLY, on purpose. The admin tree is a merchant pressing a button; the
# storefront and cron controllers are where a shopper's checkout and an unattended
# tick actually arrive. OpenCart 3 nests `catalog/` beneath `upload/` and OpenCart 4
# does not, so the search is for a `catalog` directory at any depth.
catalog_files() {
  find "$1" -type d -name catalog -exec find {} -name '*.php' \; 2>/dev/null
}

admin_files() {
  find "$1" -type d -name admin -exec find {} -name '*.php' \; 2>/dev/null
}

# The attribution handlers one major defines. Recognised by SHAPE rather than by a
# list — `on…Cart…` / `on…Order…` — so a fourth handler invented later is picked up
# without editing this script, which is what makes the union derivation work.
handlers_in() {
  local major f
  major="$1"
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    strip_comments "$f" | grep -oE 'function[[:space:]]+on[A-Za-z]*(Cart|Order)[A-Za-z]*[[:space:]]*\(' || true
  done < <(catalog_files "$major") \
    | sed -E 's/function[[:space:]]+//; s/[[:space:]]*\($//' \
    | sort -u
}

# The file in a major's catalog tree that defines a given handler, if any.
handler_file() {
  local major="$1" name="$2" f
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    if strip_comments "$f" | qgrep -E "function[[:space:]]+${name}[[:space:]]*\("; then
      printf '%s\n' "$f"
      return
    fi
  done < <(catalog_files "$major")
}

# Does every parameter of this signature carry a default?
#
# On OpenCart 4 an event handler is a public controller method and therefore a
# reachable storefront URL, and the dispatcher invokes it with NO arguments. A
# required parameter renders an uncaught ArgumentCountError — the fatal, the
# absolute file path and a full backtrace — at HTTP 200. OpenCart's own bundled
# handlers do exactly that on a stock store.
params_defaulted() {
  # ⚠ `done = 1; next` RATHER THAN `exit`. This awk consumes a pipe, so leaving early
  # closes it and the `printf` behind it takes SIGPIPE — the same fault that emptied
  # this guard's own diagnosis elsewhere in this file. The producer here is one short
  # line and would almost always win the race, but "almost always" is the entire
  # problem with the construct: it is what made the other instances survive review.
  # The flag keeps exactly one answer per invocation while reading to the end.
  printf '%s\n' "$1" | awk '
    !done {
      i = index($0, "(");     if (i == 0) { print "no-parens"; done = 1; next }
      s = substr($0, i + 1)
      j = index(s, ")");      if (j == 0) { print "no-parens"; done = 1; next }
      s = substr(s, 1, j - 1)
      if (s ~ /^[ \t]*$/)     { print "no-params"; done = 1; next }
      n = split(s, p, ",")
      for (k = 1; k <= n; k++) {
        if (p[k] ~ /\$/ && p[k] !~ /=/) { print "missing"; done = 1; next }
      }
      print "ok"
      done = 1
    }'
}

# ── The check ────────────────────────────────────────────────────────────────
check_attribution() {
  local root="$1"
  local major name majors_found=0 required handler block sig file hits
  local trigger_sources=0 codes_block src

  # 1. THE SHARED IMPLEMENTATION MUST EXIST AT ALL. Without this every per-major
  #    check below could pass against a module that cannot attribute anything.
  for src in "$ATTRIBUTION" "$REPORTS"; do
    if [ ! -f "$root/$src" ]; then
      fail "$src is missing — this module cannot attribute an order at all"
      return
    fi
  done

  # 1b. THE TABLE MUST BE CREATABLE OUTSIDE install(). OpenCart runs a module's
  #     install() when it is INSTALLED and never when it is UPGRADED — there is no
  #     module upgrade hook on either major. A table created only by install()
  #     therefore never exists on a shop that already had the module, so the write
  #     fails, the checkout-path seal swallows it, and attribution is lost for
  #     exactly the merchants who already use us. A fresh install works perfectly,
  #     which is why an install-from-archive check cannot see this.
  #
  #     Caught on 2026-08-10 before the release was tagged, by a question about
  #     uninstall. The sibling PrestaShop module solved it the same way and this
  #     module had not.
  if ! grep -qE 'CREATE TABLE IF NOT EXISTS' "$root/$REPORTS" 2>/dev/null; then
    fail "$REPORTS does not use CREATE TABLE IF NOT EXISTS — an upgrading shop cannot get the table"
  elif ! grep -qE 'function[[:space:]]+ensureSchema[[:space:]]*\(' "$root/$REPORTS" 2>/dev/null; then
    fail "$REPORTS creates its table only from install() — OpenCart never re-runs install() on an UPGRADE, so every existing shop would write to a table that does not exist and lose the attribution silently"
  elif ! awk '/function[[:space:]]+queuePending[[:space:]]*\(/,/^    }/' "$root/$REPORTS" 2>/dev/null | qgrep 'ensureSchema'; then
    fail "$REPORTS has ensureSchema() but the queue writer never calls it — an upgrading shop still gets no table"
  fi

  if ! grep -qE 'function[[:space:]]+orderReports[[:space:]]*\(' "$root/$RUNNER" 2>/dev/null; then
    fail "$RUNNER has no orderReports() accessor — every flush call site would fatal on a shopper's request"
  fi

  # 2. NO NETWORK ON THE CHECKOUT PATH, ENFORCED BY CONSTRUCTION RATHER THAN BY
  #    INTENTION. `OrderAttribution` runs inside the shopper's own request at
  #    add-to-cart and at order creation. If it can reach a client, a socket, or the
  #    queue's own sender, then a slow or unreachable service becomes a slow or
  #    broken checkout — and no try/catch anywhere fixes a timeout, because the
  #    shopper has already waited. The object that could send is never handed the
  #    means, and this is what says so.
  for token in 'Client' 'curl_' 'file_get_contents' 'fsockopen' 'stream_socket' "$FLUSH_CALL" 'reportOrder('; do
    if [ "$(live_hits "$token" "$root/$ATTRIBUTION")" -ne 0 ]; then
      fail "$ATTRIBUTION references '$token' — that is network on the checkout path, which no exception handler can make safe"
    fi
  done

  # 3. THE ORDER ID BAIL. The sibling connector hooked an event dispatched BEFORE the
  #    order row was saved, so the id was null and `(int) null` is 0 — and
  #    `order_ref = sha256(install_id|order|order_id)` became a CONSTANT. The
  #    service's own dedupe then folded every order the store ever attributed into
  #    one row. A merchant sees a single attributed order forever and nothing errors.
  #
  #    ⚠ MATCHED ON THE SHAPE OF THE COMPARISON, NOT ON A FIXED STRING. The sibling
  #    guard greps the literal `orderId <= 0`, so renaming a variable on a correct
  #    tree fails it. This accepts any order-ish variable compared against zero.
  if ! strip_comments "$root/$ATTRIBUTION" \
      | qgrep -E '\$[A-Za-z_]*[Oo]rder[A-Za-z_]*[[:space:]]*(<=[[:space:]]*0|<[[:space:]]*1)'; then
    fail "$ATTRIBUTION never refuses an order with no id — every report would carry the same order_ref and the service would fold them into one"
  fi

  # 3b. THE MONEY CONVERSION TWO SHIPPED CONNECTORS GOT WRONG. Both multiply a float
  #     by 100 for every currency, so a JPY store reports a hundred times its revenue
  #     and a KWD store a tenth of it — in a well-formed payload the service accepts
  #     without complaint. The same modules serialise catalogue prices correctly, so
  #     this is not a missing capability; it is one line written from memory.
  #
  #     ⚠ THIS DOES NOT CHECK THAT THE MONEY IS RIGHT, and the header says so. It
  #     checks for the one specific shape that is known to have shipped, and for the
  #     presence of the exponent-aware kit this module already vendors and already
  #     uses for prices. A wrong sum built out of the right kit still passes here and
  #     only a real order in a zero-decimal currency can catch it.
  if strip_comments "$root/$ATTRIBUTION" | qgrep -E '(\*[[:space:]]*100([^0-9]|$)|(^|[^0-9])100[[:space:]]*\*)'; then
    fail "$ATTRIBUTION multiplies by 100 — that is right for dollars and wrong for JPY and KWD; the vendored exponent table exists for this"
  fi

  if [ "$(live_hits 'Money::' "$root/$ATTRIBUTION")" -eq 0 ]; then
    fail "$ATTRIBUTION never uses Money:: — minor units cannot be derived without the currency's exponent"
  fi

  # 4. `occurred_at` IS READ FROM THE ROW AND NEVER RECOMPUTED. It is part of the
  #    service's dedupe key, so a value re-derived at send time makes a retry a
  #    SECOND conversion row for the same order — the merchant's revenue counted
  #    twice, silently, and only on the orders that had to be retried.
  #
  #    The send method is found by what it does (it calls `reportOrder`) rather than
  #    by name, so renaming it does not quietly disable this check.
  #    ⚠ THE awk MUST NOT `exit`. It is the CONSUMER of a pipe, so leaving early
  #    closes it, `strip_comments` takes SIGPIPE and exits 141, and `pipefail` makes
  #    this command substitution fail — fatal under `set -e` inside an assignment,
  #    which means the script dies HERE, silently, with no FAIL line and no clue.
  #    That is exactly how it presented: CI showed only the build's own "refused this
  #    tree", and the PHP version it struck rotated between runs because it is a race
  #    against the pipe buffer. A `found` flag reads to EOF and prints the same first
  #    match. Same family as `grep -q` and `head`, both of which cost this repo a red
  #    CI on the same day; none of the three may consume a pipe here.
  local send_method
  send_method="$(strip_comments "$root/$REPORTS" \
    | awk '/function[ \t]+[A-Za-z_]+[ \t]*\(/ { match($0, /function[ \t]+[A-Za-z_]+/); m = substr($0, RSTART, RLENGTH); sub(/function[ \t]+/, "", m) }
           /reportOrder[ \t]*\(/ && !found { print m; found = 1 }')"

  if [ -z "$send_method" ]; then
    fail "$REPORTS never calls reportOrder() — nothing in this module sends an order report"
  else
    block="$(method_block "$root/$REPORTS" "$send_method")"

    if printf '%s\n' "$block" | qgrep -E '\b(gmdate|date)[[:space:]]*\('; then
      fail "$REPORTS::${send_method}() recomputes a timestamp — occurred_at must be read from the row, or every retry double-counts the merchant's revenue"
    fi

    if ! printf '%s\n' "$block" | qgrep -F "occurred_at"; then
      fail "$REPORTS::${send_method}() never reads occurred_at from the row"
    fi
  fi

  # 5. UNINSTALL MUST BE ABLE TO SEE EVERY EVENT CODE. `Events::codes()` exists
  #    because both adapters once rebuilt the code list from the product triggers
  #    alone, which could not see a code built any other way — and an event row that
  #    outlives its handler calls a controller that is no longer there on every
  #    add-to-cart and every order, which is to say on the checkout path.
  #
  #    Derived: every trigger-declaring method in Events must be named inside
  #    `codes()`. Adding `cartTrigger()` without teaching `codes()` about it is the
  #    same mistake in a new coat.
  if [ ! -f "$root/$EVENTS" ]; then
    fail "$EVENTS is missing"
  else
    codes_block="$(method_block "$root/$EVENTS" 'codes')"

    if [ -z "$codes_block" ]; then
      fail "$EVENTS has no codes() — uninstall has no list of event rows to delete"
    else
      while IFS= read -r name; do
        [ -n "$name" ] || continue
        trigger_sources=$((trigger_sources + 1))
        if ! printf '%s\n' "$codes_block" | qgrep -F "$name"; then
          fail "$EVENTS::codes() never consults ${name}() — those event rows would outlive the module and call a missing controller on every order"
        fi
      done < <(strip_comments "$root/$EVENTS" \
                 | grep -oE 'function[[:space:]]+[a-zA-Z]*[Tt]riggers?[[:space:]]*\(' \
                 | sed -E 's/function[[:space:]]+//; s/[[:space:]]*\($//' | sort -u)

      # A loop over nothing passes. Events has always declared at least the product
      # triggers and the storefront one.
      if [ "$trigger_sources" -lt 2 ]; then
        fail "found ${trigger_sources} trigger source(s) in $EVENTS — expected at least 2; this check is not looking at what it thinks it is"
      fi
    fi
  fi

  # 6. THE NO-CRON FALLBACK MUST FLUSH. Shared across majors, so checked once. A
  #    shop with no cron entry is the common case on shared hosting; without this it
  #    queues reports forever and sends none.
  if [ "$(live_hits "$FLUSH_CALL" "$root/$TICK")" -eq 0 ]; then
    fail "$TICK has no live '$FLUSH_CALL' — shops without cron would queue reports and never send them"
  fi

  # ── Per major ──────────────────────────────────────────────────────────────
  if [ ! -d "$root/adapters" ]; then
    fail "no adapters/ directory — cannot tell which majors this builds for"
    return
  fi

  # THE REQUIRED SET IS THE UNION OVER EVERY MAJOR, PLUS THE FLOOR. This is what
  # makes "one major wired, the other not" a failure rather than a difference of
  # opinion: whichever major has the handler decides that every other one needs it.
  required="$(
    {
      printf '%s\n' $FLOOR_HANDLERS
      while IFS= read -r major; do
        [ -n "$major" ] || continue
        handlers_in "$major"
      done < <(find "$root/adapters" -mindepth 1 -maxdepth 1 -type d | sort)
    } | sort -u
  )"

  if [ "$(printf '%s\n' "$required" | grep -c .)" -lt 3 ]; then
    fail "derived fewer than 3 attribution handlers — the handler derivation is broken and every per-major check below is meaningless"
    return
  fi

  while IFS= read -r major; do
    [ -n "$major" ] || continue
    majors_found=$((majors_found + 1))
    name="$(basename "$major")"

    # A major with no catalog tree at all would pass every check below by having
    # nothing to check.
    if [ -z "$(catalog_files "$major")" ]; then
      fail "adapters/${name}: no catalog controllers found — this check is not looking at what it thinks it is"
      continue
    fi

    while IFS= read -r handler; do
      [ -n "$handler" ] || continue

      file="$(handler_file "$major" "$handler")"

      # (a) DEFINED. The headline failure: attribution wired on one major and
      #     silently absent on the other, in a module that ships two archives.
      if [ -z "$file" ]; then
        fail "adapters/${name}: no catalog controller defines ${handler}() — another major wires it, so on this one attribution silently does nothing"
        continue
      fi

      # (b) REGISTERED, AND REGISTERED UNDER ITS OWN NAME. A handler nothing points
      #     at is the sibling connector's incident exactly: present, correct,
      #     reviewed, and never called. A handler pointed at by a NEAR-MISS of its
      #     own name is worse — see live_word_hits() — because the row still
      #     registers and is then resolved on every add-to-cart in the shop.
      if [ "$(
            hits=0
            while IFS= read -r f; do
              [ -n "$f" ] || continue
              hits=$((hits + $(live_word_hits "$handler" "$f")))
            done < <(admin_files "$major")
            printf '%s' "$hits"
          )" -eq 0 ]; then
        fail "adapters/${name}: ${handler}() is defined but never registered by the admin controller under that exact name — either nothing will call it, or an event row points at a method that does not exist and is resolved on every checkout"
      fi

      block="$(method_block "$file" "$handler")"

      # ⚠ NOT `printf … | head -n 1`. `head` is an early-exiting consumer exactly like
      # `grep -q`: it closes the pipe after the line it wanted, `printf` takes SIGPIPE
      # and exits 141, and `pipefail` makes the command substitution fail. Inside an
      # assignment that is fatal under `set -e` — the script exits with NO message at
      # all, which is how this presented: CI showed the build's own "refused this tree"
      # and not one FAIL line, on PHP 8.1 only, while every other version passed.
      #
      # Whether it fires depends on how much `printf` still has to write when `head`
      # leaves, so it is the same race as the `grep -q` family and just as unreproducible
      # on demand — 10/10 clean runs locally on the version that was failing in CI.
      # Parameter expansion takes the first line without a pipe, so there is nothing to
      # race.
      sig="${block%%$'\n'*}"

      # (c) EVERY PARAMETER DEFAULTED — see params_defaulted().
      case "$(params_defaulted "$sig")" in
        ok) ;;
        no-params) fail "adapters/${name}: ${handler}() takes no parameters — it cannot see the route it was handed, so it cannot tell an event from someone requesting its URL" ;;
        *) fail "adapters/${name}: ${handler}() has a parameter with no default — this major's dispatcher can invoke it with none, which renders a fatal and a backtrace at HTTP 200" ;;
      esac

      # (d) THE URL SURFACE CLOSED. The event dispatcher always passes the route by
      #     reference; a URL invocation passes nothing. One line removes the public
      #     surface on both majors.
      if ! printf '%s\n' "$block" | qgrep -E '\$route[[:space:]]*===[[:space:]]*null'; then
        fail "adapters/${name}: ${handler}() has no '\$route === null' bail — it is a public storefront URL anyone can request"
      fi

      # (e) EXCEPTION-SEALED. Both catches, because this runs on a checkout and a
      #     `\Throwable` that is not an `\Exception` — a TypeError, an
      #     ArgumentCountError — is exactly what an unfamiliar shop produces.
      if ! printf '%s\n' "$block" | qgrep -F 'try {'; then
        fail "adapters/${name}: ${handler}() has no try — an exception here breaks a merchant's checkout"
      fi
      if ! printf '%s\n' "$block" | qgrep -F 'catch (\Throwable'; then
        fail "adapters/${name}: ${handler}() does not catch \\Throwable — catching only \\Exception leaves a TypeError to break a merchant's checkout"
      fi

      # (f) RETURNS NOTHING, EVER. On OpenCart 3 `Event::trigger()` takes the FIRST
      #     non-null handler result and stops calling the rest, and the router
      #     substitutes it for the output. A handler that returned even `true` would
      #     silently disable every other extension registered on the same trigger —
      #     and `checkout/cart/add` is a trigger other extensions use.
      if printf '%s\n' "$block" | qgrep -E 'return[[:space:]]+[^;[:space:]]'; then
        fail "adapters/${name}: ${handler}() returns a value — on OpenCart 3 that truncates the trigger and disables every other extension on it"
      fi

      # (g) AND IT DOES NOT SEND. The whole design rests on the checkout path
      #     queueing to a local table and nothing more. A flush called from a handler
      #     puts an outbound HTTP call inside the shopper's request, where the
      #     failure is a slow checkout rather than an exception anyone catches.
      if printf '%s\n' "$block" | qgrep -E "(orderReports\(\)|flush\(|reportOrder\(|new Client)"; then
        fail "adapters/${name}: ${handler}() reaches the sender — the checkout path queues and nothing else"
      fi
    done < <(printf '%s\n' "$required")

    # (h) AND SOMETHING UNATTENDED MUST FLUSH, per major. The page-load fallback is
    #     shared and checked above; the cron endpoint is this major's own file.
    hits=0
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      hits=$((hits + $(live_hits "$FLUSH_CALL" "$f")))
    done < <(catalog_files "$major")

    if [ "$hits" -eq 0 ]; then
      fail "adapters/${name}: no catalog controller has a live '$FLUSH_CALL' — this major queues reports and never sends them"
    fi
  done < <(find "$root/adapters" -mindepth 1 -maxdepth 1 -type d | sort)

  # A loop over nothing passes. OpenCart maintains two current majors and this
  # module builds for both, so finding fewer than two means the tree is not what this
  # check was written against and its silence means nothing.
  if [ "$majors_found" -lt 2 ]; then
    fail "found ${majors_found} major(s) under adapters/ — expected at least 2; this check is not looking at what it thinks it is"
  fi
}

# ── Self-test ────────────────────────────────────────────────────────────────
#
# TWO DIRECTIONS. A check that fires on the bad tree might fire on everything; one
# that passes the good tree might pass everything. Only both together say it
# discriminates, and only the good direction can catch the mistake this script is
# most likely to make — a pattern so strict that correct code fails it, which is how
# a guard ends up deleted rather than fixed.
#
# ⚠ The verdict is read out of the OUTPUT, not out of `$FAILED` — running the check
# inside `$( … )` puts it in a subshell and the flag it sets there is discarded.
self_test() {
  local tmp

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  printf 'self-test\n'

  # A handler, in the shape both majors are required to hold.
  write_handler() {
    cat >> "$1" <<PHP

    /**
     * EVERY PARAMETER HAS A DEFAULT and it never throws — prose the guard must
     * ignore, because a tree that only talks about the rule must not pass.
     */
    public function $2(&\$route = null, &\$args = null, &\$output = null)
    {
        if (\$route === null) {
            return;
        }

        try {
            \$runner = new Runner(\$this->db);
            \$runner->orderAttribution()->handle(\$args);
        } catch (\\Exception \$e) {
        } catch (\\Throwable \$e) {
        }
    }
PHP
  }

  build_good() {
    rm -rf "$tmp/good"
    mkdir -p "$tmp/good/src/Sync" \
             "$tmp/good/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module" \
             "$tmp/good/adapters/oc3/upload/admin/controller/extension/module" \
             "$tmp/good/adapters/oc4/catalog/controller/module/nitrosearch" \
             "$tmp/good/adapters/oc4/admin/controller/module"

    # The checkout-path class: no client, no socket, and it refuses an order with
    # no id. The docblock deliberately NAMES the forbidden things, because the real
    # file does too and the guard has to read past it.
    cat > "$tmp/good/src/Sync/OrderAttribution.php" <<'PHP'
<?php
/**
 * Makes no HTTP call of any kind: no Client, no curl_, no file_get_contents.
 *
 * ⚠ NEVER `× 100`. That line is right for dollars and wrong for JPY — and the
 * guard has to read past this sentence, because the real file carries it too.
 */
final class OrderAttribution
{
    const MAX_ITEM_IDS = 100;
    const MAX_VALUE_CENTS = 100000000;

    public function orderCreated($orderId)
    {
        if ($orderId <= 0) {
            return;
        }

        $places = Money::ofMinor(1, $currency)->exponent();
    }
}
PHP

    # The queue. `expireStale()` calls gmdate() LEGITIMATELY — that is the whole
    # reason the send method is extracted rather than the file grepped, and this
    # tree proves the extraction works rather than merely asserting it.
    cat > "$tmp/good/src/Sync/OrderReports.php" <<'PHP'
<?php
final class OrderReports
{
    public static function schema()
    {
        return 'CREATE TABLE IF NOT EXISTS `x` (`order_id` INT) ENGINE=InnoDB';
    }

    private function ensureSchema()
    {
        $this->db->query(self::schema());
    }

    public function queuePending($orderId, $valueCents, $currency, array $itemIds, $q)
    {
        $this->ensureSchema();

        return true;
    }

    private function sendOne(array $row)
    {
        $outcome = $this->client->reportOrder(array(
            'occurred_at' => (string) $row['occurred_at'],
        ));

        return 'sent';
    }

    private function expireStale()
    {
        $this->db->query("DELETE WHERE `occurred_at` < '" . gmdate('c', time() - 604800) . "'");
    }
}
PHP

    cat > "$tmp/good/src/Sync/Events.php" <<'PHP'
<?php
final class Events
{
    public static function triggers() { return array(); }
    public static function storefrontTrigger() { return array('code' => 'nitrosearch_storefront'); }
    public static function cartTrigger() { return array('code' => 'nitrosearch_cart_add'); }
    public static function orderTriggers() { return array(); }

    public static function codes()
    {
        $codes = array();
        foreach (self::triggers() as $t) { $codes[] = 'nitrosearch_' . $t['method']; }
        foreach (self::orderTriggers() as $o) { $codes[] = $o['code']; }
        $s = self::storefrontTrigger(); $codes[] = $s['code'];
        $c = self::cartTrigger(); $codes[] = $c['code'];

        return $codes;
    }
}
PHP

    printf '<?php\n$runner->orderReports()->flush(3);\n' > "$tmp/good/src/Sync/PageLoadTick.php"
    printf '<?php\nclass Runner { public function orderReports() {} }\n' > "$tmp/good/src/Sync/Runner.php"

    OC3_CTRL="$tmp/good/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php"
    OC4_CTRL="$tmp/good/adapters/oc4/catalog/controller/module/nitrosearch.php"
    OC3_ADMIN="$tmp/good/adapters/oc3/upload/admin/controller/extension/module/nitrosearch.php"
    OC4_ADMIN="$tmp/good/adapters/oc4/admin/controller/module/nitrosearch.php"

    printf '<?php\nclass A {\n' > "$OC3_CTRL"
    printf '<?php\nclass B {\n' > "$OC4_CTRL"
    for h in $FLOOR_HANDLERS; do
      write_handler "$OC3_CTRL" "$h"
      write_handler "$OC4_CTRL" "$h"
    done
    printf '}\n' >> "$OC3_CTRL"
    printf '}\n' >> "$OC4_CTRL"

    printf '<?php\n$runner->orderReports()->flush(10);\n' \
      > "$tmp/good/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/cron.php"
    printf '<?php\n$runner->orderReports()->flush(10);\n' \
      > "$tmp/good/adapters/oc4/catalog/controller/module/nitrosearch/cron.php"

    {
      printf '<?php\n'
      for h in $FLOOR_HANDLERS; do
        printf "addEvent('extension/nitrosearch/module/nitrosearch/%s');\n" "$h"
      done
    } > "$OC3_ADMIN"
    {
      printf '<?php\n'
      for h in $FLOOR_HANDLERS; do
        printf "addEvent('extension/nitrosearch/module/nitrosearch.%s');\n" "$h"
      done
    } > "$OC4_ADMIN"
  }

  build_good

  local good_out
  good_out="$( check_attribution "$tmp/good" 2>&1 )" || true
  case "$good_out" in
    *FAIL*)
      red "  the guard fired on a CORRECT tree:"
      printf '%s\n' "$good_out"
      exit 1
      ;;
  esac
  green "  ok  stays quiet on a fully wired tree (including a legitimate gmdate() outside the send path)"

  try_evasion() {
    local label="$1" expect="$2" out
    out="$( check_attribution "$tmp/bad" 2>&1 )" || true
    case "$out" in
      *"$expect"*) green "  ok  fires on: ${label}" ;;
      *) red "  the guard did NOT fire on: ${label}"; printf '%s\n' "$out"; exit 1 ;;
    esac
  }

  spoil() { rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"; }

  # THE HEADLINE FAILURE, and the reason this file exists: one major wired, one not.
  spoil
  grep -v 'onOrderConfirmed' "$tmp/good/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php"
  try_evasion "one major missing a handler the other has" "adapters/oc3: no catalog controller defines"

  # THE UPGRADE PATH, which a fresh install cannot show you. OpenCart runs install()
  # on INSTALL and never on UPGRADE, so a table created only there never exists on a
  # shop that already had the module — and the checkout-path seal makes the failure
  # silent. This was the real state of the tree on 2026-08-10, hours before the tag.
  spoil
  perl -0pi -e 's/    private function ensureSchema\(\).*?\n    \}\n\n//s' \
    "$tmp/bad/src/Sync/OrderReports.php"
  perl -0pi -e 's/        \$this->ensureSchema\(\);\n\n//' "$tmp/bad/src/Sync/OrderReports.php"
  try_evasion "a table only install() creates (every upgrading shop loses attribution)" "only from install()"

  # THE SUBTLER HALF: the guard exists, and nothing calls it.
  spoil
  perl -0pi -e 's/        \$this->ensureSchema\(\);\n\n//' "$tmp/bad/src/Sync/OrderReports.php"
  try_evasion "ensureSchema() present but the writer never calls it" "never calls it"

  # THE SAME FAILURE ONE STEP LATER — the sibling connector's actual incident. The
  # handler is there, it is correct, and nothing points at it.
  spoil
  grep -v 'onOrderCreated' "$tmp/good/adapters/oc4/admin/controller/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc4/admin/controller/module/nitrosearch.php"
  try_evasion "a handler defined but never registered" "never registered"

  # THE NEAR-MISS, which is the same failure with a worse ending and the one the
  # substring match used to let through. The row registers, so nothing complains at
  # install time; the action names a method that does not exist, and it is resolved
  # on every add-to-cart in the shop.
  spoil
  sed "s/nitrosearch\.onCartAdd'/nitrosearch.onCartAddd'/" \
    "$tmp/good/adapters/oc4/admin/controller/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc4/admin/controller/module/nitrosearch.php"
  try_evasion "an action typo'd to a method that does not exist" "never registered"

  # And the other side of the boundary: a DIFFERENT handler whose name merely ends
  # with this one's must not satisfy it either.
  spoil
  sed "s/nitrosearch\.onCartAdd'/nitrosearch.reallyOnCartAdd'/" \
    "$tmp/good/adapters/oc4/admin/controller/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc4/admin/controller/module/nitrosearch.php"
  try_evasion "an action registered under a longer name that contains this one" "never registered"

  # THE DERIVATION ITSELF. A fourth handler on ONE major must make every other major
  # fail — this is what covers a third major nobody has written yet.
  spoil
  write_handler "$tmp/bad/adapters/oc4/catalog/controller/module/nitrosearch.php" 'onOrderRefunded'
  printf "addEvent('nitrosearch.onOrderRefunded');\n" >> "$tmp/bad/adapters/oc4/admin/controller/module/nitrosearch.php"
  try_evasion "a handler added to one major only (derived union)" "onOrderRefunded"

  spoil
  sed 's/&\$args = null/\&$args/' "$tmp/good/adapters/oc4/catalog/controller/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc4/catalog/controller/module/nitrosearch.php"
  try_evasion "a handler parameter with no default" "no default"

  spoil
  grep -v '\$route === null' "$tmp/good/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php"
  try_evasion "the public URL surface left open" "public storefront URL"

  spoil
  grep -v 'catch (\\Throwable' "$tmp/good/adapters/oc4/catalog/controller/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc4/catalog/controller/module/nitrosearch.php"
  try_evasion "only \\Exception caught on a checkout handler" "does not catch"

  spoil
  sed 's/^            return;$/            return true;/' \
    "$tmp/good/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/nitrosearch.php"
  try_evasion "a handler returning a value (OpenCart 3 truncates the trigger)" "returns a value"

  spoil
  sed 's|\$runner->orderAttribution()->handle(\$args);|\$runner->orderReports()->flush(1);|' \
    "$tmp/good/adapters/oc4/catalog/controller/module/nitrosearch.php" \
    > "$tmp/bad/adapters/oc4/catalog/controller/module/nitrosearch.php"
  try_evasion "the sender called from a checkout handler" "reaches the sender"

  spoil
  printf '<?php\nuse NitroSearch\\Api\\Client;\nfinal class OrderAttribution { public function orderCreated($orderId) { if ($orderId <= 0) { return; } (new Client())->reportOrder(array()); } }\n' \
    > "$tmp/bad/src/Sync/OrderAttribution.php"
  try_evasion "the checkout-path class given a way to send" "network on the checkout path"

  spoil
  grep -v 'orderId <= 0' "$tmp/good/src/Sync/OrderAttribution.php" > "$tmp/bad/src/Sync/OrderAttribution.php"
  try_evasion "an order with no id accepted (every report one order_ref)" "refuses an order with no id"

  # The money bug two connectors shipped, written the way it was actually written.
  spoil
  sed 's|\$places = Money::ofMinor(1, \$currency)->exponent();|return (int) round($amount * 100);|' \
    "$tmp/good/src/Sync/OrderAttribution.php" > "$tmp/bad/src/Sync/OrderAttribution.php"
  try_evasion "value converted with a hardcoded × 100 (a JPY store reports 100×)" "multiplies by 100"

  spoil
  sed 's|\$places = Money::ofMinor(1, \$currency)->exponent();|$places = 2;|' \
    "$tmp/good/src/Sync/OrderAttribution.php" > "$tmp/bad/src/Sync/OrderAttribution.php"
  try_evasion "the exponent table dropped altogether" "never uses Money::"

  spoil
  sed "s/(string) \$row\['occurred_at'\]/gmdate('c')/" "$tmp/good/src/Sync/OrderReports.php" \
    > "$tmp/bad/src/Sync/OrderReports.php"
  try_evasion "occurred_at recomputed at send time (retry double-counts)" "recomputes a timestamp"

  spoil
  sed 's/\$c = self::cartTrigger();//' "$tmp/good/src/Sync/Events.php" > "$tmp/bad/src/Sync/Events.php"
  try_evasion "an event code uninstall cannot see" "never consults cartTrigger"

  spoil
  printf '<?php\n// $runner->orderReports()->flush(10);\n' \
    > "$tmp/bad/adapters/oc3/upload/catalog/controller/extension/nitrosearch/module/cron.php"
  try_evasion "the flush commented out on one major" "adapters/oc3: no catalog controller has a live"

  spoil
  printf '<?php\n$runner->drain()->run(20);\n' > "$tmp/bad/src/Sync/PageLoadTick.php"
  try_evasion "no flush on the no-cron fallback" "shops without cron"

  spoil
  printf '<?php\n$runner->orderReports()->flush(10);\n' > "$tmp/bad/adapters/oc4/admin/controller/module/x.php"
  rm -f "$tmp/bad/adapters/oc4/catalog/controller/module/nitrosearch/cron.php"
  try_evasion "the flush wired into admin only — a merchant pressing a button" "adapters/oc4: no catalog controller has a live"

  # NON-VACUITY. Every check above is inside a loop; a loop over nothing passes in
  # silence, which is the failure mode this whole file is written against.
  spoil
  rm -rf "$tmp/bad/adapters/oc3"
  try_evasion "only one major on disk (a loop over nothing)" "expected at least 2"

  spoil
  rm -rf "$tmp/bad/adapters"
  try_evasion "no adapters at all" "cannot tell which majors"

  spoil
  rm -f "$tmp/bad/src/Sync/OrderAttribution.php"
  try_evasion "no attribution implementation at all" "cannot attribute an order at all"

  green "self-test passed"
  exit 0
}

case "${1:-}" in
  --self-test) self_test ;;
  '') ;;
  *) ROOT="$(cd "$1" && pwd)" ;;
esac

check_attribution "$ROOT"

if [ "$FAILED" -ne 0 ]; then
  red "order attribution wiring check FAILED"
  exit 1
fi

green "ok    order attribution is wired on every major, registered where it will fire, and never sends from a checkout"
