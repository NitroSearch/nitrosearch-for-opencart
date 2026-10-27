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
 * WHERE A SHOP KEEPS ITS SKU — the reason 4.1.0.4 shops indexed nothing.
 *
 * OpenCart 4.1.0.4 dropped `product.sku` (and UPC, EAN, JAN, ISBN, MPN) in favour
 * of `product_code`. The serializer named `p.sku` and every product failed. The
 * three shapes are pinned against a fake database, and the serializer is checked
 * structurally to only ever name `p.sku` behind the column branch. The real proof
 * is three real shops (4.1.0.4, 4.1.0.3 and 3.0.5.1), which this runner cannot be.
 */

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once dirname(dirname(__DIR__)) . '/src/Sync/Schema.php';

use NitroSearch\Sync\Schema;

/** Answers SHOW COLUMNS / SHOW TABLES from a fixed description of a shop. */
class NsFakeSchemaDb
{
    /** @var array<int, string> */
    public $columns;
    /** @var array<int, string> */
    public $tables;

    public function __construct(array $columns, array $tables)
    {
        $this->columns = $columns;
        $this->tables = $tables;
    }

    public function escape($value)
    {
        return addslashes((string) $value);
    }

    public function query($sql)
    {
        if (strpos($sql, 'SHOW COLUMNS') === 0) {
            return (object) array('rows' => array_map(function ($c) { return array('Field' => $c); }, $this->columns));
        }
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'/", $sql, $m)) {
            return (object) array('rows' => in_array($m[1], $this->tables, true) ? array(array('t' => $m[1])) : array());
        }

        throw new \RuntimeException('unexpected query: ' . $sql);
    }
}

return array(
    'OpenCart 3 and 4.0-4.1.0.2 keep the SKU on the product row' => function () {
        $schema = new Schema(new NsFakeSchemaDb(array('product_id', 'model', 'sku', 'price'), array('oc_product')));
        ns_is('column', 'column', $schema->skuSource());
    },

    '4.1.0.3 carries both, and the column wins, so nothing changes for it' => function () {
        $schema = new Schema(new NsFakeSchemaDb(array('product_id', 'model', 'sku', 'price'), array('oc_product', 'oc_product_code')));
        ns_is('column', 'column', $schema->skuSource());
    },

    '4.1.0.4 has only product_code' => function () {
        $schema = new Schema(new NsFakeSchemaDb(array('product_id', 'master_id', 'model', 'price'), array('oc_product', 'oc_product_code')));
        ns_is('code', 'code', $schema->skuSource());
    },

    'a shop with neither still serialises, on the model' => function () {
        $schema = new Schema(new NsFakeSchemaDb(array('product_id', 'model', 'price'), array('oc_product')));
        ns_is('none', 'none', $schema->skuSource());
    },

    'the serializer names p.sku only behind the column branch' => function () {
        $source = (string) file_get_contents(dirname(dirname(__DIR__)) . '/src/Sync/ProductSerializer.php');
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        ns_is('p.sku appears once', 1, substr_count($code, 'p.`sku`'));
        ns_true('and it is conditional', (bool) preg_match("/skuSource\(\) === 'column' \? \"p\.`sku`, \"/", $code));
        ns_false('no unguarded row sku read', (bool) preg_match("/\(string\) \\\$row\['sku'\]/", $code));
        ns_true('product_code is read', strpos($code, 'product_code') !== false);
    },
);
