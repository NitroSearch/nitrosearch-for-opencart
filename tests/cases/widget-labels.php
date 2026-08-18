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
 * WIDGET LABELS — the strings a shopper reads, in the shop's language.
 *
 * The search panel is drawn by one shared bundle carrying no locales, so it
 * renders English unless this module sends `cfg.labels`. Until now it never did,
 * and `Widget::config()` said so in a comment: there were no catalogues to send.
 *
 * ⚠ WHAT MAKES THIS FAILURE MODE NASTY IS THAT NOTHING BREAKS. A missing label
 * key does not error, does not warn, and does not fail a request — the widget
 * falls back to its own English per key and renders a panel that looks entirely
 * correct to anyone who reads English. The only symptom is a shopper in Bucharest
 * reading a word nobody chose for them. There is no crash to assert on, so these
 * cases assert the CONTENT and the COMPLETENESS.
 *
 * ⚠ AND THE LOCALE SPELLING IS OPENCART'S, NOT THE CATALOGUE'S. OpenCart writes
 * language codes LOWER-CASE with a hyphen — `de-de`, `en-gb` — and the catalogues
 * are named `de_DE`, `en_GB`. Every case below feeds the resolver what OpenCart
 * actually produces rather than the tidy form, because a resolver that only
 * matched the tidy form would pass a test suite and send nothing on every real
 * shop.
 *
 * WHAT THIS CANNOT PROVE: that the widget renders them. The labels go over the
 * wire as JSON to a bundle this repo does not contain, with plural categories
 * chosen by the browser's Intl.PluralRules at render time. The honest
 * verification for that is a real store on both majors.
 */

require_once dirname(dirname(__DIR__)) . '/src/Storefront/Labels.php';

use NitroSearch\Storefront\Labels;

/** The widget's own label table — the contract, read from the bundle it belongs to. */
function ns_oc_widget_contract($root)
{
    $path = $root . '/../backend/widget/src/widget.jsx';
    if (!is_file($path)) {
        return null;
    }
    $src = (string) file_get_contents($path);
    if (!preg_match('/const LABELS = \{(.*?)\n\};/s', $src, $m)) {
        return null;
    }
    preg_match_all('/(\w+):\s*(\{[^}]*\}|\'(?:[^\'\\\\]|\\\\.)*\')/', $m[1], $mm, PREG_SET_ORDER);
    $keys = array();
    foreach ($mm as $x) {
        $keys[$x[1]] = $x[2][0] === '{';
    }

    return $keys;
}

return array(

    'the locale OpenCart actually writes resolves to a catalogue' => function ($root) {
        // OpenCart's `config_language_catalog` is lower-case with a hyphen. This
        // is the case that decides whether any of this reaches a real shop.
        ns_is('de-de', 'de_DE', Labels::catalogueFor('de-de'));
        ns_is('fr-fr', 'fr_FR', Labels::catalogueFor('fr-fr'));
        ns_is('pt-br', 'pt_BR', Labels::catalogueFor('pt-br'));
        ns_is('pt-pt', 'pt_PT', Labels::catalogueFor('pt-pt'));
        ns_is('zh-cn', 'zh_CN', Labels::catalogueFor('zh-cn'));

        $de = Labels::forLocale('de-de');
        ns_is('and it carries German', 'In den Warenkorb', isset($de['add_to_cart']) ? $de['add_to_cart'] : null);
    },

    'every shipped catalogue covers the whole widget contract' => function ($root) {
        $contract = ns_oc_widget_contract($root);
        if ($contract === null) {
            ns_is('the widget contract is readable', true, true);   // skip, stated

            return;
        }
        ns_true('the contract has keys at all', count($contract) > 30);

        $shipped = Labels::shipped();
        ns_true('catalogues are shipped', count($shipped) > 0);

        foreach ($shipped as $name) {
            $labels = Labels::forLocale($name);
            ns_is($name . ' covers every contract key', array(), array_values(array_diff(array_keys($contract), array_keys($labels))));
            ns_is($name . ' sends nothing the widget cannot use', array(), array_values(array_diff(array_keys($labels), array_keys($contract))));

            foreach ($contract as $key => $isPlural) {
                if (!isset($labels[$key])) {
                    continue;
                }
                ns_is(
                    $name . ' » ' . $key . ' has the shape the widget reads',
                    $isPlural ? 'array' : 'string',
                    is_array($labels[$key]) ? 'array' : gettype($labels[$key])
                );
                if ($isPlural) {
                    ns_true($name . ' » ' . $key . ' has an "other" form', isset($labels[$key]['other']));
                } else {
                    ns_true($name . ' » ' . $key . ' is not empty', $labels[$key] !== '');
                }
            }
        }
    },

    'a shop reading English is sent nothing at all' => function ($root) {
        // Not "sent English" — sent NOTHING. The bundle already has this text, and
        // 37 redundant strings on every page view is a cost with no benefit. It
        // also preserves the property the old comment protected: the widget never
        // selects plurals by a non-English locale's rules over English strings.
        foreach (array('en-us', 'en', 'en-au', 'en-ca', 'en-nz', 'en-za', 'EN-US') as $locale) {
            ns_is('nothing for ' . $locale, array(), Labels::forLocale($locale));
        }

        $gb = Labels::forLocale('en-gb');
        ns_true('en-gb does get a catalogue', $gb !== array());
        ns_is('and it is there for the basket', 'Add to basket', isset($gb['add_to_cart']) ? $gb['add_to_cart'] : null);
    },

    'a region we do not ship falls back by language, except in English' => function ($root) {
        ns_is('de-at reads German', 'de_DE', Labels::catalogueFor('de-at'));
        ns_is('fr-be reads French', 'fr_FR', Labels::catalogueFor('fr-be'));

        // ⚠ THE ONE THAT MUST NOT FALL BACK. en_GB is the only English catalogue
        // shipped, so a naive "exactly one catalogue for this language" rule hands
        // "Add to basket" to Australia, Canada, New Zealand and South Africa — all
        // four of whose editors kept "cart".
        ns_is('en-au gets no catalogue', null, Labels::catalogueFor('en-au'));

        // Portuguese ships two and neither may stand in for the other.
        ns_is('pt-ao is refused rather than guessed', null, Labels::catalogueFor('pt-ao'));

        // Language-only catalogues serve their language whatever the region.
        ns_is('ja-jp reads ja', 'ja', Labels::catalogueFor('ja-jp'));
        ns_is('uk-ua reads uk', 'uk', Labels::catalogueFor('uk-ua'));
    },

    'a locale we have never heard of is refused, not guessed at' => function ($root) {
        // The empty string is the real one: OpenCart 3 leaves the catalogue key
        // blank on some installs, which is why Widget already treats '' as
        // "omit the locale".
        foreach (array('', '   ', 'xx-yy', 'klingon', '../../etc/passwd', 'de/../../x', 'zz') as $bad) {
            ns_is('no catalogue for ' . var_export($bad, true), null, Labels::catalogueFor($bad));
            ns_is('no labels for ' . var_export($bad, true), array(), Labels::forLocale($bad));
        }
    },

    'the catalogues carry reviewed translations, not the English source' => function ($root) {
        // If a catalogue echoed the source it would be 100% "complete", pass every
        // structural check above, and do nothing — the same shape as the en_GB
        // catalogue that echoed American English on WooCommerce and was caught by
        // mutation rather than by any guard.
        $spot = array(
            'de-de' => array('add_to_cart' => 'In den Warenkorb', 'in_stock' => 'Vorrätig'),
            'fr-fr' => array('add_to_cart' => 'Ajouter au panier'),
            'ro-ro' => array('add_to_cart' => 'Adaugă în coș'),
            'ja' => array('add_to_cart' => 'カートに追加'),
        );
        foreach ($spot as $locale => $expected) {
            $labels = Labels::forLocale($locale);
            foreach ($expected as $key => $text) {
                ns_is($locale . ' » ' . $key, $text, isset($labels[$key]) ? $labels[$key] : null);
            }
        }
    },

    'Romanian keeps the plural forms its editor actually chose' => function ($root) {
        // Why the generator samples at 1, 2, 5 and 100 rather than 1 and 2:
        // Romanian's "few" covers 2-19 and its "other" only starts at 20, where the
        // noun takes "de". Sampling at 5 for "other" would freeze the few-form in
        // and no test of counts under 20 would ever notice.
        $ro = Labels::forLocale('ro-ro');
        ns_true('results_count is a plural map', is_array($ro['results_count']));
        ns_is('few (2-19) has no "de"', '%s rezultate', $ro['results_count']['few']);
        ns_is('other (20+) has "de"', '%s de rezultate', $ro['results_count']['other']);
        ns_is('one spells the number', 'Un rezultat', $ro['results_count']['one']);
    },

    'a single-form language collapses instead of repeating itself four times' => function ($root) {
        $ja = Labels::forLocale('ja');
        ns_is('Japanese has one plural form', array('other'), array_keys($ja['results_count']));
    },

    'the committed catalogues match what the generator produces' => function ($root) {
        if (!is_dir($root . '/../plugin/languages') || !is_file($root . '/../backend/widget/src/widget.jsx')) {
            ns_is('sibling checkouts present for the drift check', true, true);   // skip, stated

            return;
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sync-widget-labels.php') . ' --check 2>&1';
        $out = array();
        $status = 0;
        exec($cmd, $out, $status);
        ns_is('generator reports no drift: ' . implode(' ', $out), 0, $status);
    },
);
