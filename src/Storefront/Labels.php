<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Storefront;

/**
 * The storefront search panel's own strings, in the shop's language.
 *
 * WHAT THIS FIXES. The panel a shopper sees is drawn by one shared widget bundle
 * that serves every store on every platform, so it carries no locales: it renders
 * its built-in English unless the module hands it `cfg.labels`. A German shop,
 * with a German admin and a German theme, showed its shoppers "Add to cart",
 * "In stock" and "No products found." `Widget::config()` carried a comment saying
 * the key was omitted DELIBERATELY because this module had no catalogues to send.
 * That was true and is no longer.
 *
 * WHERE THE WORDS COME FROM. `bin/sync-widget-labels.php` derives the catalogues
 * in `labels/` from the WooCommerce plugin's gettext catalogues — the same 37
 * English strings, already translated into 23 shipping locales, already natively
 * reviewed, and in seven of them corrected by that locale's own wordpress.org
 * translation editor. They are generated and committed; a shop never runs the
 * generator.
 *
 * WHY NOT OPENCART'S OWN LANGUAGE FILES. Because they cannot express this. An
 * OpenCart language file is a flat array of strings with no plural machinery at
 * all — a module wanting "1 result" and "5 results" is expected to write the
 * branch itself — and the widget needs four CLDR categories per plural string,
 * chosen by the browser at render time. Asking either major a question it has no
 * way to answer, in order to arrive at text we already have, would be the long
 * way round to a worse answer.
 *
 * FRAMEWORK-FREE, LIKE EVERYTHING IN src/. It reads its own directory and knows
 * nothing about OpenCart, which is what lets both majors share it unchanged.
 */
class Labels
{
    /**
     * The language the widget bundle is already written in.
     *
     * ⚠ THIS IS WHY ENGLISH NEVER FALLS BACK BY LANGUAGE. Every other language
     * may: a de-at shop reading the de_DE catalogue, or fr-be reading fr_FR, is
     * unambiguously better off than reading English. English is the one case
     * where the bundle's own text is already right for most regions and the
     * catalogue we ship is the exception — en_GB exists to say "Add to basket",
     * a word en_AU, en_CA, en_NZ and en_ZA all measurably reject. Falling en-au
     * back to en_GB would not be a near-miss; it would replace correct text with
     * wrong text.
     */
    const SOURCE_LANGUAGE = 'en';

    /**
     * @param string $locale the shop's language code. OpenCart writes these
     *                       lower-case with a hyphen — `en-gb`, `de-de` — which
     *                       is neither the catalogue's spelling nor a constant
     *                       across the two majors, so the matching below is
     *                       case-insensitive on both halves.
     *
     * @return array<string, string|array<string, string>> empty when we have
     *                                                     nothing better than
     *                                                     the widget's English
     */
    public static function forLocale($locale)
    {
        $name = self::catalogueFor($locale);
        if ($name === null) {
            return array();
        }

        $labels = include __DIR__ . '/labels/' . $name . '.php';

        return is_array($labels) ? $labels : array();
    }

    /**
     * Which shipped catalogue serves this locale, if any.
     *
     * @param string $locale
     *
     * @return string|null
     */
    public static function catalogueFor($locale)
    {
        $normalised = str_replace('-', '_', trim((string) $locale));
        if (!preg_match('/^([a-zA-Z]{2,3})(?:_([a-zA-Z0-9]{2,4}))?$/', $normalised, $m)) {
            return null;
        }

        $language = strtolower($m[1]);
        $region = isset($m[2]) ? strtoupper($m[2]) : '';
        $shipped = self::shipped();

        // Exact first: pt_BR and pt_PT are different catalogues and neither may
        // stand in for the other.
        $exact = $region === '' ? $language : $language . '_' . $region;
        foreach ($shipped as $name) {
            if (strcasecmp($name, $exact) === 0) {
                return $name;
            }
        }

        if ($language === self::SOURCE_LANGUAGE) {
            return null;   // see SOURCE_LANGUAGE
        }

        // Otherwise the language alone, but only when exactly one catalogue
        // claims it. Two would be a guess between regions, and a guess here
        // reads as a translation error rather than a missing translation.
        $candidates = array();
        foreach ($shipped as $name) {
            $head = strpos($name, '_') === false ? $name : substr($name, 0, strpos($name, '_'));
            if (strcasecmp($head, $language) === 0) {
                $candidates[] = $name;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The catalogues actually present on disk.
     *
     * Read from the directory rather than declared in a list here: the generator
     * decides which locales earn a catalogue — one that resolves every string to
     * the widget's own English is not shipped — and a second list in this file
     * would be wrong the first time that set changed.
     *
     * @return array<int, string>
     */
    public static function shipped()
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }

        // Only names that ARE locales. Excluding one known filename would have
        // been enough today and wrong the next time something else lands in this
        // directory — an editor backup, a platform's own guard file. The port
        // from PrestaShop wrote an `index.php` here and the resolver dutifully
        // offered it as a catalogue, which is the whole argument.
        $names = array();
        foreach ((array) glob(__DIR__ . '/labels/*.php') as $path) {
            $name = basename($path, '.php');
            if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
