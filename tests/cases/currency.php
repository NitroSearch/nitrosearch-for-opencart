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
 * THE CURRENCY EXPONENT TABLE, which decides whether a price is 1999 or 19.99.
 *
 * The service is told prices in integer minor units plus an exponent. Get the
 * exponent wrong and nothing errors anywhere: the payload is well-formed, the
 * batch is accepted, and every storefront running this module shows a price that
 * is off by two orders of magnitude. A ¥1,999 kettle becomes ¥19.99.
 *
 * The table is VENDORED — generated elsewhere and copied in — so it can fall
 * behind the service that generated it, and a stale copy is invisible from
 * inside this repo. That is what these assertions are for.
 *
 * ⚠ The currencies below are chosen because they DISAGREE with the default. A
 * table that had rotted into "2 for everything" would satisfy any test written
 * only around USD and EUR, which is most of them.
 */

require_once dirname(dirname(__DIR__)).'/vendor/nitrosearch-contract/src/CurrencyExponents.php';

use NitroSearch\AdapterKit\CurrencyExponents;

return array(
    'the zero-decimal currencies have no minor unit' => function () {
        ns_is('JPY', 0, CurrencyExponents::for('JPY'));
        ns_is('KRW', 0, CurrencyExponents::for('KRW'));
    },

    'the three-decimal currencies have three' => function () {
        ns_is('KWD', 3, CurrencyExponents::for('KWD'));
        ns_is('BHD', 3, CurrencyExponents::for('BHD'));
    },

    'the ordinary case is two' => function () {
        ns_is('USD', 2, CurrencyExponents::for('USD'));
        ns_is('EUR', 2, CurrencyExponents::for('EUR'));
        ns_is('GBP', 2, CurrencyExponents::for('GBP'));
    },

    'an unknown code falls back rather than failing' => function () {
        // Forward tolerance: a shop on a currency this table has never heard of
        // must still sync. Two is right for the overwhelming majority, and a
        // wrong price beats a shop that cannot index at all.
        ns_is('unknown', 2, CurrencyExponents::for('ZZZ'));
        ns_is('empty', 2, CurrencyExponents::for(''));
    },

    'lookup is case-insensitive' => function () {
        // OpenCart's own currency rows are not reliably upper-cased, and a lookup
        // that missed would silently return the default — right for USD, wrong
        // for JPY, so it would pass nearly all testing.
        ns_is('lowercase jpy', 0, CurrencyExponents::for('jpy'));
        ns_is('mixed-case kwd', 3, CurrencyExponents::for('Kwd'));
    },
);
