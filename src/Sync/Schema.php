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

/**
 * What this shop's catalogue schema actually has.
 *
 * THE TWO MAJORS DIFFER IN THE DATA MODEL ITSELF, not only in where their files
 * live, and every difference found so far is SILENT — a missing column reads as a
 * missing value and a missing table is a fatal three joins deep:
 *
 *   variants     OC4 has master_id/variant/override; OC3 has no native variants
 *   views        OC3 keeps `product.viewed`; OC4 MOVED it to `product_viewed`
 *   specials     OC3 has `product_special`; OC4 folded it into `product_discount`
 *                behind a `special` flag
 *
 * The views row is worth dwelling on: the column's absence in OpenCart 4 reads as
 * "this major dropped the feature", and this file said exactly that until a query
 * for the table list showed `oc_product_viewed` sitting there. An absent column is
 * evidence about a column, not about a capability.
 *
 * ASKED OF THE DATABASE, NOT DERIVED FROM A VERSION NUMBER. A version constant
 * would be the obvious way and it is the wrong one: OpenCart is widely forked and
 * widely patched, merchants run modules that add and remove columns, and a shop on
 * 4.0.x may not have a column 4.1.x introduced. `SHOW COLUMNS` answers the question
 * that is actually being asked — *can I read this column* — rather than a proxy for
 * it, and it cannot be wrong about the shop it is running on.
 *
 * Probed once per request and cached: it is one query, and the serializer asks per
 * product.
 */
final class Schema
{
    /** @var object */
    private $db;

    /** @var array<string, bool>|null */
    private $columns = null;

    /** @var array<string, bool> */
    private $tables = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param string $column
     *
     * @return bool
     */
    public function productHas($column)
    {
        if ($this->columns === null) {
            $this->columns = array();

            $result = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product`");
            foreach ($result->rows as $row) {
                // MySQL returns the column name under `Field`; be tolerant of case,
                // because a proxy or a compatibility layer may not preserve it.
                foreach ($row as $key => $value) {
                    if (strtolower((string) $key) === 'field') {
                        $this->columns[strtolower((string) $value)] = true;
                    }
                }
            }
        }

        return isset($this->columns[strtolower((string) $column)]);
    }

    /**
     * Whether a table exists, unprefixed (`product_special`, not `oc_product_special`).
     *
     * ASKED, NOT ASSUMED, for the same reason as the columns — and here the cost of
     * assuming is higher. A missing column yields a missing array key; a missing table
     * throws, and it throws from inside a catalogue walk where the exception takes the
     * whole drain with it. `oc_product_special` is absent on OpenCart 4 and this is
     * how that was found: a fatal, on the first product, three calls deep.
     *
     * @param string $table
     *
     * @return bool
     */
    public function hasTable($table)
    {
        $key = strtolower((string) $table);

        if (!array_key_exists($key, $this->tables)) {
            $result = $this->db->query(
                "SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'"
            );
            $this->tables[$key] = !empty($result->rows);
        }

        return $this->tables[$key];
    }

    /**
     * Where this shop records a special price, or '' when it records none.
     *
     * OpenCart 3 keeps `product_special`. OpenCart 4 folded specials into
     * `product_discount` behind a `special` flag, so the table a shop has decides
     * both which one to query and what the query looks like.
     *
     * @return string 'product_special', 'product_discount', or ''
     */
    public function specialsTable()
    {
        if ($this->hasTable('product_special')) {
            return 'product_special';
        }

        if ($this->hasTable('product_discount')) {
            return 'product_discount';
        }

        return '';
    }

    /**
     * Where this shop records a per-product view count.
     *
     * @return string 'column' (OpenCart 3), 'table' (OpenCart 4), or 'none'
     */
    public function viewsSource()
    {
        if ($this->productHas('viewed')) {
            return 'column';
        }

        if ($this->hasTable('product_viewed')) {
            return 'table';
        }

        return 'none';
    }

    /**
     * Whether this shop has OpenCart 4's native variant products.
     *
     * When it does, a variant is its OWN `product` row with `master_id` pointing at
     * its parent — so a catalogue walk that does not exclude them sends every
     * variation as a separate top-level product. That fills a shopper's results with
     * near-duplicates and multiplies what the merchant is charged against their plan,
     * because one product is one indexed object however many variations it has.
     *
     * @return bool
     */
    public function hasVariantProducts()
    {
        return $this->productHas('master_id');
    }

}
