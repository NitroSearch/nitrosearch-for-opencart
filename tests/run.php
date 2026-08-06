<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

/**
 * The module's test runner. No Composer, no PHPUnit, no network, no shop.
 *
 * WHY IT IS HAND-ROLLED. This module ships as a ZIP a merchant uploads, so it has
 * no build step and no Composer dependencies on purpose — a module that resolves
 * dependencies at install time fails on most shared hosts. Adding a dev-only
 * dependency would mean a lockfile, a vendor directory that must not ship, and a
 * packaging rule to keep it out. A hundred lines of runner costs less and cannot
 * leak into the archive.
 *
 * WHAT IT COVERS, DELIBERATELY: the pure, framework-free parts where being wrong
 * is silent and expensive — the HMAC canonicalisation (a drift here is a 401 in
 * production, not a negotiation), the proof-of-control hash, and the currency
 * exponent table that decides whether a price is 1999 or 19.99. It does NOT try
 * to test the catalogue walk, the outbox or the drain: those need a real
 * OpenCart, and this repo ships for TWO majors, so the honest verification is an
 * archive installed through each back office — which is what CONTRIBUTING asks a
 * pull request to describe.
 *
 * EVERYTHING IT COVERS LIVES IN `src/`, and that is the point. `src/` is copied
 * verbatim into both archives, so a test here covers both majors at once; an
 * adapter cannot be reached from a runner because reaching it means booting
 * OpenCart. The bash guards in `bin/` cover the adapters instead, by checking
 * that each major wires what it must.
 *
 *   php tests/run.php
 */

$root = dirname(__DIR__);

$passed = 0;
$failures = array();
$currentCase = '';

/**
 * @param string $label
 * @param mixed  $expected
 * @param mixed  $actual
 */
function ns_is($label, $expected, $actual)
{
    global $passed, $failures, $currentCase;

    if ($expected === $actual) {
        $passed++;

        return;
    }

    $failures[] = sprintf(
        "%s › %s\n      expected: %s\n      actual:   %s",
        $currentCase,
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

/**
 * @param string $label
 * @param bool   $condition
 */
function ns_true($label, $condition)
{
    ns_is($label, true, (bool) $condition);
}

/**
 * @param string $label
 * @param bool   $condition
 */
function ns_false($label, $condition)
{
    ns_is($label, false, (bool) $condition);
}

$cases = glob(__DIR__.'/cases/*.php');
sort($cases);

// A runner that finds no cases prints nothing and exits 0, which is
// indistinguishable from a clean run. It has to be an error.
if (!$cases) {
    fwrite(STDERR, "no test cases found under tests/cases/ — the runner is not looking at what it thinks it is\n");
    exit(1);
}

foreach ($cases as $file) {
    $currentCase = basename($file, '.php');
    $tests = require $file;

    if (!is_array($tests) || !$tests) {
        $failures[] = $currentCase.' › the case file returned no tests';
        continue;
    }

    foreach ($tests as $name => $test) {
        $currentCase = basename($file, '.php').' :: '.$name;
        $test($root);
    }
}

if ($failures) {
    fwrite(STDERR, "\n".count($failures)." FAILED\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  ✗ ".$f."\n\n");
    }
    exit(1);
}

fwrite(STDOUT, "ok    {$passed} assertions across ".count($cases)." case file(s)\n");
exit(0);
