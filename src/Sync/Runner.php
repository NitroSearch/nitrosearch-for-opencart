<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Sync;

use NitroSearch\Api\Client;
use NitroSearch\Settings;
use NitroSearch\Storefront\Widget;
use NitroSearch\Support\ShopUrl;

/**
 * Assembles the sync from a database handle and nothing else.
 *
 * EVERY CALLER IS A CONTROLLER, and there are four of them across two majors that
 * agree on nothing structural. Without this they would each wire up six objects and
 * resolve the shop's currency and language by hand, four times, and drift.
 *
 * WHAT IT RESOLVES IS THE SHOP'S OWN CONFIGURATION, read from `setting` rather than
 * taken from the ambient request. A drain triggered by a shopper's page load and one
 * triggered by cron must serialise a product identically; anything read from the
 * current request is a value that differs between those two paths.
 */
final class Runner
{
    /** @var object */
    private $db;

    /** @var Settings */
    private $settings;

    public function __construct($db)
    {
        $this->db = $db;
        $this->settings = new Settings($db);
    }

    /**
     * @return Settings
     */
    public function settings()
    {
        return $this->settings;
    }

    /**
     * @return Drain
     */
    public function drain()
    {
        return new Drain(
            $this->db,
            $this->settings,
            new Client($this->settings, ShopUrl::resolve()),
            $this->serializer()
        );
    }

    /**
     * @return FullSync
     */
    public function fullSync()
    {
        return new FullSync($this->db, $this->settings, $this->schema());
    }

    /**
     * @return Outbox
     */
    public function outbox()
    {
        return new Outbox($this->db);
    }

    /**
     * @return Schema
     */
    public function schema()
    {
        return new Schema($this->db);
    }

    /**
     * @return ProductSerializer
     */
    public function serializer()
    {
        return new ProductSerializer($this->db, $this->schema(), $this->context());
    }

    /**
     * The storefront widget block, built from the shop's own configuration.
     *
     * ASSEMBLED HERE FOR THE SAME REASON EVERYTHING ELSE IS: two adapters would
     * otherwise each resolve the shop's url, currency and language by hand, and the
     * two would drift. It also means the widget's currency and the currency the
     * catalogue was serialised in are read from one place and cannot disagree —
     * a mismatch there would render every price in the wrong currency's format.
     *
     * @return Widget
     */
    public function storefront()
    {
        $context = $this->context();

        return new Widget(
            $this->settings,
            (string) $context['shop_url'],
            (string) $context['currency'],
            (string) $context['locale']
        );
    }

    /**
     * The no-cron fallback that rides a storefront page view.
     *
     * @return PageLoadTick
     */
    public function pageLoadTick()
    {
        return new PageLoadTick($this);
    }

    /**
     * The unattended heartbeat — search-key renewal and re-send requests.
     *
     * ASSEMBLED HERE, LIKE EVERYTHING ELSE, so that both majors' cron controllers
     * and the page-load fallback reach it identically. It is built from the same
     * `Client` the drain uses, which means the shop url it signs with is resolved
     * once rather than per caller.
     *
     * @return ResyncCheck
     */
    public function resyncCheck()
    {
        return new ResyncCheck(
            $this->settings,
            new Client($this->settings, ShopUrl::resolve()),
            $this->fullSync()
        );
    }

    /**
     * The shop's own currency and language, as the catalogue is priced and written.
     *
     * ⚠ THE LANGUAGE SETTING IS NAMED DIFFERENTLY IN EACH MAJOR, and neither stores
     * a language ID at all:
     *
     *   OpenCart 3   config_language           = 'en-gb'
     *   OpenCart 4   config_language_catalog   = 'en-gb'   (plus …_admin)
     *
     * So the code is read from whichever key exists and the id is looked up in
     * `language`. Defaulting the id to 1 instead would be right on most shops and
     * silently wrong on any shop that installed a second language first — every
     * product would serialise with an empty name, and an empty name is not an error
     * anywhere downstream.
     *
     * THE SAME KEY DECIDES WHETHER URLS CARRY A LANGUAGE. OpenCart 4 requires
     * `language=` on its own storefront links and OpenCart 3 has no such parameter,
     * so the presence of `config_language_catalog` is used as the discriminator. That
     * is a version test wearing a settings key, and it is named as one here rather
     * than hidden: there is no per-request signal available to PHP that says which
     * form this shop's links take.
     *
     * @return array<string, mixed>
     */
    private function context()
    {
        $config = $this->configValues(array('config_currency', 'config_language', 'config_language_catalog'));

        $catalogKey = isset($config['config_language_catalog']) ? (string) $config['config_language_catalog'] : '';
        $legacyKey = isset($config['config_language']) ? (string) $config['config_language'] : '';

        $code = $catalogKey !== '' ? $catalogKey : $legacyKey;

        return array(
            'shop_url' => ShopUrl::resolve(),
            // Prices in `product` are held in the shop's DEFAULT currency, whose
            // `value` is 1.0 — so no conversion is needed or wanted here. Converting
            // would index one currency's prices under another's code.
            'currency' => isset($config['config_currency']) && $config['config_currency'] !== ''
                ? (string) $config['config_currency']
                : 'USD',
            'language_id' => $this->languageId($code),
            'language_code' => $catalogKey !== '' ? $catalogKey : '',
            // THE SHOP'S LANGUAGE, WHICHEVER KEY HELD IT — and deliberately NOT
            // `language_code` above, which is something else wearing a similar name.
            // That one is empty on OpenCart 3 because it doubles as "do this shop's
            // urls carry a language parameter", and reusing it here would leave every
            // OpenCart 3 storefront formatting its prices by generic English
            // conventions. OpenCart writes these as BCP-47 already (`en-gb`), which
            // is the form the widget's Intl calls want.
            'locale' => $code,
        );
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, string>
     */
    private function configValues(array $keys)
    {
        $escaped = array();
        foreach ($keys as $key) {
            $escaped[] = "'" . $this->db->escape($key) . "'";
        }

        $result = $this->db->query(
            "SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` "
            . "WHERE `store_id` = 0 AND `key` IN (" . implode(',', $escaped) . ")"
        );

        $out = array();
        foreach ($result->rows as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * @param string $code
     *
     * @return int
     */
    private function languageId($code)
    {
        if ($code !== '') {
            $result = $this->db->query(
                "SELECT `language_id` FROM `" . DB_PREFIX . "language` "
                . "WHERE `code` = '" . $this->db->escape($code) . "' LIMIT 1"
            );

            if (isset($result->row['language_id'])) {
                return (int) $result->row['language_id'];
            }
        }

        // No configured code, or a code with no matching row — a shop mid-migration,
        // or one whose language was removed. The lowest enabled id is the shop's
        // oldest language and the likeliest to have descriptions, which beats
        // assuming 1 and serialising every product with an empty name.
        $result = $this->db->query(
            "SELECT `language_id` FROM `" . DB_PREFIX . "language` ORDER BY `language_id` ASC LIMIT 1"
        );

        return isset($result->row['language_id']) ? (int) $result->row['language_id'] : 1;
    }
}
