#!/usr/bin/env bash
#
# Every string the admin TEMPLATE renders must be one the CONTROLLER passes it.
#
# WHY THIS EXISTS. `1.3.0` shipped, in both archives, with FOUR UNLABELLED BUTTONS.
# The template renders `{{ text_connect }}`, `{{ text_refresh }}`, `{{ text_sync }}`
# and `{{ text_disconnect }}`; all four were defined in the language file; and the
# controller assigned language strings from a HARDCODED ARRAY OF THIRTEEN KEYS that
# did not include them. Twig renders an undefined variable as the empty string, so a
# merchant opened the module's screen and saw a row of blank buttons. No error, no
# warning, nothing in any log.
#
# It is the same failure this repo keeps meeting from other directions — a subject
# list written by hand stops covering the next thing added — and the language file
# was RIGHT the whole time. Nothing compared the two.
#
# ⚠ WHAT THIS CANNOT SEE:
#   • Whether a string is any good, or whether the language file's English is right.
#   • A variable built dynamically (`{{ attribute(_context, key) }}`), which nothing
#     textual can resolve.
#   • Values assigned by `$actions->state()` / `->urls()`, which are merged after the
#     language block and are not language strings — those are matched loosely below
#     and deliberately not enumerated here, because enumerating them is the mistake
#     this file exists to catch.
#
#   bin/check-admin-strings.sh              # check the working tree
#   bin/check-admin-strings.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

FAILED=0
fail() { printf '\033[31mFAIL  %s\033[0m\n' "$*"; FAILED=1; }
pass() { printf '\033[32mok    %s\033[0m\n' "$*"; }

# Boolean grep that reads to the end — never `grep -q` under `pipefail`, which
# reports failure when it MATCHES because the producer takes SIGPIPE. See the
# sibling guards in this directory.
qgrep() { grep -c "$@" >/dev/null; }

# The language-ish placeholders a template renders: text_*, heading_*, button_*,
# entry_*, error_*, help_*. Derived from the template, never listed.
template_keys() {
    grep -rhoE '\{\{ *(text|heading|button|entry|error|help)_[a-z0-9_]+' "$1" --include='*.twig' 2>/dev/null \
        | sed -E 's/\{\{ *//' | sort -u
}

# Keys the language file declares.
language_keys() {
    grep -hoE "^\\\$_\['[a-z0-9_]+'\]" "$1" 2>/dev/null | sed -E "s/^\\\$_\['//; s/'\]$//" | sort -u
}

check_major() {
    local name="$1" template_dir="$2" language_file="$3" controller="$4"
    local missing=0 key

    if [ ! -d "$template_dir" ] || [ ! -f "$language_file" ] || [ ! -f "$controller" ]; then
        fail "${name}: expected files are missing — this check is not looking at what it thinks it is"
        return
    fi

    local tkeys lkeys
    tkeys="$(template_keys "$template_dir")"
    lkeys="$(language_keys "$language_file")"

    # ANTI-VACUITY. A template that yields no keys, or a language file that yields
    # none, would make every comparison below pass over nothing — which is exactly
    # how the defect this file exists for stayed invisible.
    if [ "$(printf '%s\n' "$tkeys" | grep -c .)" -lt 5 ]; then
        fail "${name}: found fewer than 5 language placeholders in the template — the parser is broken"
        return
    fi
    if [ "$(printf '%s\n' "$lkeys" | grep -c .)" -lt 5 ]; then
        fail "${name}: found fewer than 5 keys in the language file — the parser is broken"
        return
    fi

    # 1. Every key the template renders must be DECLARED in the language file.
    while IFS= read -r key; do
        [ -n "$key" ] || continue
        if ! printf '%s\n' "$lkeys" | qgrep -Fx "$key"; then
            fail "${name}: the template renders {{ ${key} }} and the language file declares no such string"
            missing=$((missing + 1))
        fi
    done <<EOF
$tkeys
EOF

    # 2. …and the controller must actually PASS the language strings through, rather
    #    than hand-picking some of them. A derived assignment covers every key by
    #    construction; a hardcoded list is the defect, so the shape is what is checked.
    if ! qgrep -F 'load->language(' "$controller"; then
        fail "${name}: the controller never loads its language file"
        missing=$((missing + 1))
    elif qgrep -E "foreach[[:space:]]*\\((array\\(|\\[)[^)]*['\"](text|heading|button|entry|error|help)_" "$controller"; then
        # ⚠ THE DISCRIMINATOR IS THE ARRAY LITERAL, not the assignment. The first
        # version of this check looked for `$data[$key] =` and passed the defect
        # happily — because the hardcoded form had that line too:
        #
        #     foreach (array('heading_title', 'text_edit', …) as $key) {
        #         $data[$key] = $this->language->get($key);
        #     }
        #
        # Both shapes assign; only one of them ENUMERATES. Checking the assignment was
        # checking the half they share, which is how a guard comes to pass over the
        # thing it was written for.
        fail "${name}: the controller iterates a hand-written list of language keys — every key it forgets renders as an empty string, which is how 1.3.0 shipped four unlabelled buttons"
        missing=$((missing + 1))
    fi

    if [ "$missing" -eq 0 ]; then
        pass "${name}: every rendered string is declared and passed"
    fi

    # ⚠ ALWAYS 0. `fail()` records the verdict in $FAILED; this function's own exit
    # status is not the verdict. Ending on a bare `[ … ] && pass …` returned 1 when
    # there WAS a fault, and under `set -e` that aborted the whole script mid-self-test
    # — which printed nothing at all and exited 1, so a guard that was working looked
    # broken. Same family as the SIGPIPE trap the sibling guards carry: an exit status
    # meaning something other than what the reader assumes.
    return 0
}

self_test() {
    # NOT `local`. The EXIT trap runs after this function has returned, so a local
    # $tmp is out of scope by then and `set -u` turns the cleanup into
    # "tmp: unbound variable" — printed after a PASSING self-test, with exit 1.
    # A guard whose own teardown reports failure is indistinguishable from a guard
    # that failed.
    tmp="$(mktemp -d)"
    trap 'rm -rf "${tmp:-}"' EXIT

    mkdir -p "$tmp/tpl"
    # Five declared keys, so the good-tree case clears the anti-vacuity floor below
    # rather than tripping it — a fixture too small to pass its own guard proves
    # nothing about the guard.
    printf '%s\n' '{{ text_connect }} {{ heading_title }} {{ text_a }} {{ text_b }} {{ text_c }}' > "$tmp/tpl/x.twig"
    printf '%s\n' '<button>{{ text_ghost }}</button>' > "$tmp/tpl/y.twig"
    # Enough keys to clear the anti-vacuity floor.
    {
        printf "%s\n" "\$_['text_connect']  = 'Connect';"
        printf "%s\n" "\$_['heading_title'] = 'NitroSearch';"
        printf "%s\n" "\$_['text_a'] = 'a';"
        printf "%s\n" "\$_['text_b'] = 'b';"
        printf "%s\n" "\$_['text_c'] = 'c';"
    } > "$tmp/lang.php"
    printf '%s\n' '<?php $this->load->language("x"); foreach ($strings as $key => $value) { $data[$key] = $value; }' > "$tmp/ctrl.php"

    # Fires on a template key with no language entry.
    FAILED=0
    check_major "self-test/bad-key" "$tmp/tpl" "$tmp/lang.php" "$tmp/ctrl.php" >/dev/null 2>&1
    if [ "$FAILED" -eq 1 ]; then
        printf '  \033[32mok\033[0m  fires on a template string the language file never declares\n'
    else
        printf '  \033[31m%s\033[0m\n' "did NOT fire on an undeclared template string"; exit 1
    fi

    # Quiet on a good tree.
    rm "$tmp/tpl/y.twig"
    FAILED=0
    check_major "self-test/good" "$tmp/tpl" "$tmp/lang.php" "$tmp/ctrl.php" >/dev/null 2>&1
    if [ "$FAILED" -eq 0 ]; then
        printf '  \033[32mok\033[0m  stays quiet when every rendered string is declared\n'
    else
        printf '  \033[31m%s\033[0m\n' "fired on a correct tree"; exit 1
    fi

    # Fires on the exact defect: a hand-written key list in the controller.
    printf '%s\n' '<?php $this->load->language("x"); foreach (array("heading_title") as $key) { $data[$key] = $this->language->get($key); }' > "$tmp/ctrl.php"
    FAILED=0
    check_major "self-test/hardcoded" "$tmp/tpl" "$tmp/lang.php" "$tmp/ctrl.php" >/dev/null 2>&1
    if [ "$FAILED" -eq 1 ]; then
        printf '  \033[32mok\033[0m  fires on a controller that hand-picks its language keys\n'
    else
        printf '  \033[31m%s\033[0m\n' "did NOT fire on a hardcoded key list — the defect that shipped 1.3.0"; exit 1
    fi

    printf '\033[32m%s\033[0m\n' "self-test passed"
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit 0
fi

check_major "oc4" \
    "$ROOT/adapters/oc4/admin/view/template/module" \
    "$ROOT/adapters/oc4/admin/language/en-gb/module/nitrosearch.php" \
    "$ROOT/adapters/oc4/admin/controller/module/nitrosearch.php"

check_major "oc3" \
    "$ROOT/adapters/oc3/upload/admin/view/template/extension/module" \
    "$ROOT/adapters/oc3/upload/admin/language/en-gb/extension/module/nitrosearch.php" \
    "$ROOT/adapters/oc3/upload/admin/controller/extension/module/nitrosearch.php"

if [ "$FAILED" -ne 0 ]; then
    printf '\033[31m%s\033[0m\n' "admin string check FAILED"
    exit 1
fi

printf '\033[32m%s\033[0m\n' "every admin string the templates render is declared and passed, on both majors"
