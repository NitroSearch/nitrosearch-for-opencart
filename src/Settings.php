<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch;

/**
 * Every persistent value the module owns, behind one accessor.
 *
 * STORED IN `oc_setting`, WHICH IS THE ONE THING BOTH MAJORS AGREE ON. OpenCart 3
 * and 4 differ in directory layout, class naming, base classes, routing and type
 * declarations — but `oc_setting` is column-for-column identical in both
 * (`setting_id, store_id, code, key, value, serialized`), and so is the `query()`
 * / `escape()` surface of their DB library. So this file is shared verbatim and
 * takes OpenCart's own `$this->db` rather than wrapping it.
 *
 * `store_id = 0` DELIBERATELY. OpenCart multi-store puts each storefront in its
 * own row; this module indexes one catalogue and holds one set of credentials, so
 * it writes the default-store row and says so on the Configure screen rather than
 * pretending to a multi-store support it does not have. Surfacing a limit beats
 * silently applying one shop's settings to another's.
 */
final class Settings
{
    /** The `code` column value for every row this module owns. */
    const CODE = 'module_nitrosearch';

    /** @var object OpenCart's DB library — query()/escape(), same in both majors */
    private $db;

    /** @var array<string, mixed>|null read-through cache for this request */
    private $cache = null;

    /**
     * Keys holding a credential. Listed explicitly because they must be cleared
     * on disconnect and must never be rendered into an admin template.
     *
     * @var array<int, string>
     */
    private static $secrets = array('SYNC_SECRET', 'SCOPED_SEARCH_KEY', 'EVENTS_TOKEN', 'CONNECT_TOKEN');

    /**
     * Defaults for everything the module reads. A key absent from here is a typo
     * rather than a feature — get() would silently return '' forever.
     *
     * @var array<string, mixed>
     */
    private static $defaults = array(
        'API_URL' => 'https://api.nitrosearch.io',
        'CONNECT_TOKEN' => '',
        'CONNECTED' => false,
        'VERIFIED' => false,
        'CLAIMED' => false,
        'INSTALL_ID' => '',
        'SITE_URL' => '',
        'STORE_ID' => '',
        'COLLECTION' => '',
        'SYNC_KEY_ID' => '',
        'SYNC_SECRET' => '',
        'SEARCH_PUBLIC_ID' => '',
        'SCOPED_SEARCH_KEY' => '',
        'ENGINE_HOST' => '',
        'WIDGET_LOADER_URL' => '',
        'WIDGET_BUNDLE_URL' => '',
        'EVENTS_URL' => '',
        'EVENTS_TOKEN' => '',
        'PRODUCT_LIMIT' => 0,
        'PRODUCT_COUNT' => 0,
        'AT_LIMIT' => false,
        'PLAN' => '',
        'LAST_SYNC' => '',
        'LAST_ERROR' => '',
        'DRAIN_TOKEN' => '',
        'DRAIN_RAN_AT' => 0,
        // The full-walk cursor. Kept here rather than in its own table because it is
        // a handful of scalars that must survive exactly as long as the install.
        'FULLSYNC_ACTIVE' => false,
        'FULLSYNC_CURSOR' => 0,
        'FULLSYNC_TOTAL' => 0,
        'FULLSYNC_STARTED' => '',
        'FULLSYNC_DONE' => '',
    );

    /**
     * @param object $db OpenCart's DB library instance
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param string $key     unprefixed, e.g. 'SYNC_SECRET'
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $all = $this->all();

        if (isset($all[$key]) && $all[$key] !== '') {
            return $all[$key];
        }

        if ($default !== null) {
            return $default;
        }

        return isset(self::$defaults[$key]) ? self::$defaults[$key] : '';
    }

    /**
     * Write one or more values. Booleans are stored as '1'/'0' so a round trip
     * through `text` cannot turn false into the string 'false', which is truthy.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values)
    {
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $escapedKey = $this->db->escape(self::CODE . '_' . strtolower($key));
            $escapedValue = $this->db->escape((string) $value);

            $this->db->query(
                "DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 0 AND `key` = '" . $escapedKey . "'"
            );
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = 0, `code` = '" . $this->db->escape(self::CODE) . "', "
                . "`key` = '" . $escapedKey . "', `value` = '" . $escapedValue . "', `serialized` = 0"
            );
        }

        $this->cache = null;
    }

    /**
     * Every value this module owns, read once per request.
     *
     * @return array<string, mixed>
     */
    public function all()
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $prefixLength = strlen(self::CODE . '_');
        $out = array();

        $result = $this->db->query(
            "SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` "
            . "WHERE `store_id` = 0 AND `code` = '" . $this->db->escape(self::CODE) . "'"
        );

        foreach ($result->rows as $row) {
            $key = strtoupper(substr($row['key'], $prefixLength));
            $out[$key] = $row['value'];
        }

        $this->cache = $out;

        return $out;
    }

    /**
     * A stable per-install identifier, minted once.
     *
     * It is a SIGNING INPUT and an outbound identifier, so it must not be derived
     * from anything discoverable: a value built from the shop URL or the database
     * name would be reproducible by anyone who knows the shop.
     *
     * @return string
     */
    public function installId()
    {
        $id = (string) $this->get('INSTALL_ID');

        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->update(array('INSTALL_ID' => $id));
        }

        return $id;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return (string) $this->get('SYNC_SECRET') !== '' && (string) $this->get('SYNC_KEY_ID') !== '';
    }

    /**
     * @return string
     */
    public function apiUrl()
    {
        return rtrim((string) $this->get('API_URL'), '/');
    }

    /**
     * Everything except the credentials — safe to render into an admin template.
     *
     * @return array<string, mixed>
     */
    public function publicValues()
    {
        $all = $this->all();

        foreach (self::$secrets as $secret) {
            unset($all[$secret]);
        }

        return $all;
    }

    /**
     * Remove every row this module owns. Used by uninstall, and by disconnect —
     * which is why it is keyed on `code` rather than enumerating keys: a value
     * added in a later version must not survive an uninstall from an earlier one.
     */
    public function purge()
    {
        $this->db->query(
            "DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . $this->db->escape(self::CODE) . "'"
        );

        $this->cache = null;
    }
}
