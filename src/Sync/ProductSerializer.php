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

use NitroSearch\AdapterKit\ItemBuilder;
use NitroSearch\AdapterKit\Money;

/**
 * One OpenCart product → one ingest item.
 *
 * READS THE DATABASE DIRECTLY rather than going through OpenCart's catalogue model,
 * and that is deliberate. `ModelCatalogProduct` applies the ambient customer group,
 * store, language and tax rules, so the same product serialises differently
 * depending on who triggered the sync — a drain from a page load would see the
 * shopper's context and a drain from cron would see none. PrestaShop taught this
 * one the expensive way: `Product::getPriceStatic()` calls `die()` outside a request
 * with an employee or a cart, so every back-office test passed while the cron path
 * died on the first product. SQL has no ambient context to get wrong.
 *
 * ONE PRODUCT IS ONE INDEXED OBJECT, however many variations it has. That is the
 * merchant's quota unit, and it is why OpenCart 4's variant rows are folded into
 * their parent rather than walked as products of their own.
 */
final class ProductSerializer
{
    /** @var object */
    private $db;

    /** @var Schema */
    private $schema;

    /** @var array<string, mixed> */
    private $context;

    /**
     * @param object               $db
     * @param array<string, mixed> $context shop_url, currency, language_id, and
     *                                      language_code when the shop needs one on
     *                                      its urls (OpenCart 4 does, 3 does not)
     */
    public function __construct($db, Schema $schema, array $context)
    {
        $this->db = $db;
        $this->schema = $schema;
        $this->context = $context;
    }

    /**
     * @param int $productId
     *
     * @return array<string, mixed>|null null when the product no longer exists
     */
    public function serialize($productId)
    {
        $productId = (int) $productId;

        $row = $this->row($productId);
        if ($row === null) {
            return null;
        }

        $currency = (string) $this->context['currency'];

        $item = ItemBuilder::product($productId)
            ->name(self::text((string) $row['name']))
            // FAILS CLOSED, and the field is always emitted. `status` is OpenCart's
            // enabled flag; a disabled product stays indexed but unreachable through
            // the public search key rather than being deleted, so re-enabling it does
            // not cost a re-sync.
            ->visible((int) $row['status'] === 1)
            ->price($this->money((string) $row['price'], $currency))
            ->inStock($this->inStock($row))
            ->version($this->version($row));

        $sku = trim((string) $row['sku']) !== '' ? trim((string) $row['sku']) : trim((string) $row['model']);
        if ($sku !== '') {
            $item->sku($sku);
        }

        $description = self::text(strip_tags((string) $row['description']));
        if ($description !== '') {
            $item->description($description);
        }

        $brand = self::text((string) $row['manufacturer']);
        if ($brand !== '') {
            $item->brand($brand);
        }

        if (trim((string) $row['image']) !== '') {
            $item->image($this->url('image/' . ltrim((string) $row['image'], '/')));
        }

        $item->permalink($this->productUrl($productId));

        $categories = $this->categories($productId);
        if (!empty($categories)) {
            $item->categories($categories);
        }

        if ($this->onSale($productId)) {
            $item->onSale(true);
        }

        // The only popularity signal either major offers without joining orders. It
        // lives in a different place in each, and reading only the column would have
        // silently given every OpenCart 4 shop no popularity signal at all.
        $views = $this->views($productId, $row);
        if ($views !== null) {
            $item->popularity($views);
        }

        foreach ($this->variants($productId, $currency) as $variant) {
            $item->variant(
                $variant['id'],
                $variant['sku'],
                $variant['price'],
                $variant['in_stock'],
                $variant['attributes']
            );
        }

        return $item->toArray();
    }

    /**
     * A deletion, which needs no product row — the object may already be gone.
     *
     * @param int $productId
     *
     * @return array<string, mixed>
     */
    public function tombstone($productId)
    {
        return ItemBuilder::product((int) $productId)
            ->delete()
            ->version((int) round(microtime(true) * 1000))
            ->toArray();
    }

    /**
     * Money, rounded to the currency's own precision FIRST.
     *
     * OpenCart stores prices as `DECIMAL(15,4)`, so a merchant can enter `19.9950`
     * in a shop priced in dollars. The kit refuses that rather than silently dropping
     * a digit from someone's price — correctly, because which way to round is a
     * business decision and not one a library should make quietly. It is made here,
     * once, and it is half-up: the same rule a shopper sees on the shop's own pages.
     *
     * Trailing zeros are not rounding. `100.0000` in USD loses nothing and the kit
     * accepts it as-is.
     *
     * @param string $amount
     * @param string $currency
     *
     * @return Money
     */
    private function money($amount, $currency)
    {
        // Ask the KIT for the currency's precision rather than keeping a table here.
        // A second copy of the exponent list is exactly the drift the vendored kit
        // exists to prevent, and it would be a copy that disagrees silently: the two
        // would only diverge on the currencies nobody tests.
        $places = Money::ofMinor(1, $currency)->exponent();

        $rounded = number_format((float) $amount, $places, '.', '');

        return Money::fromDecimalString($rounded, $currency);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return bool
     */
    private function inStock(array $row)
    {
        // `subtract` off means the shop does not track stock for this product, so
        // quantity is meaningless and the product is always orderable. Treating a
        // zero quantity as out of stock there would hide every made-to-order and
        // digital product from an in-stock filter.
        if ((int) $row['subtract'] !== 1) {
            return true;
        }

        return (int) $row['quantity'] > 0;
    }

    /**
     * The last-write-wins clock.
     *
     * `date_modified` is what the shop knows, but it has SECOND resolution and two
     * edits inside one second would be indistinguishable — the service skips any item
     * whose version is not greater than the one it holds, so the later edit would be
     * dropped. Milliseconds now, floored to the row's modification time when that is
     * in the past, keeps ordering correct for a full walk while still moving forward
     * for a live edit.
     *
     * @param array<string, mixed> $row
     *
     * @return int
     */
    private function version(array $row)
    {
        $modified = isset($row['date_modified']) ? strtotime((string) $row['date_modified']) : false;

        if ($modified === false || $modified <= 0) {
            return (int) round(microtime(true) * 1000);
        }

        return $modified * 1000;
    }

    /**
     * @param int $productId
     *
     * @return array<string, mixed>|null
     */
    private function row($productId)
    {
        $languageId = (int) $this->context['language_id'];

        $select = "p.*, pd.`name`, pd.`description`, m.`name` AS `manufacturer`";

        $result = $this->db->query(
            "SELECT " . $select . " FROM `" . DB_PREFIX . "product` p "
            . "LEFT JOIN `" . DB_PREFIX . "product_description` pd "
            . "ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = " . $languageId . ") "
            . "LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (p.`manufacturer_id` = m.`manufacturer_id`) "
            . "WHERE p.`product_id` = " . (int) $productId
        );

        return !empty($result->row) ? $result->row : null;
    }

    /**
     * @param int $productId
     *
     * @return array<int, string>
     */
    private function categories($productId)
    {
        $languageId = (int) $this->context['language_id'];

        $result = $this->db->query(
            "SELECT cd.`name` FROM `" . DB_PREFIX . "product_to_category` p2c "
            . "INNER JOIN `" . DB_PREFIX . "category_description` cd "
            . "ON (p2c.`category_id` = cd.`category_id` AND cd.`language_id` = " . $languageId . ") "
            . "WHERE p2c.`product_id` = " . (int) $productId
        );

        $names = array();
        foreach ($result->rows as $row) {
            $name = self::text((string) $row['name']);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether a special price is active right now.
     *
     * TWO SCHEMAS, AND THE WRONG ONE IS A FATAL RATHER THAN A WRONG ANSWER.
     * OpenCart 3 keeps `product_special`; OpenCart 4 has no such table at all and
     * folds specials into `product_discount` behind a `special` flag. Querying the
     * OpenCart 3 table on an OpenCart 4 shop throws from three calls deep, inside a
     * catalogue walk, taking the whole drain with it — which is exactly how this was
     * found, on the first product of the first OpenCart 4 run.
     *
     * The date columns are `0000-00-00` when open-ended, which is neither null nor a
     * date any comparison handles sensibly — hence the explicit test rather than a
     * BETWEEN.
     *
     * @param int $productId
     *
     * @return bool
     */
    private function onSale($productId)
    {
        $table = $this->schema->specialsTable();
        if ($table === '') {
            return false;
        }

        $where = "`product_id` = " . (int) $productId . " "
            . "AND (`date_start` = '0000-00-00' OR `date_start` <= NOW()) "
            . "AND (`date_end` = '0000-00-00' OR `date_end` >= NOW())";

        // On OpenCart 4 the same table also holds ordinary quantity-break discounts,
        // which are not a sale — only rows flagged `special` are.
        if ($table === 'product_discount') {
            $where .= " AND `special` > 0";
        }

        $result = $this->db->query(
            "SELECT COUNT(*) AS `n` FROM `" . DB_PREFIX . $table . "` WHERE " . $where
        );

        return isset($result->row['n']) && (int) $result->row['n'] > 0;
    }

    /**
     * This product's view count, wherever this major keeps it.
     *
     * OpenCart 3 has a `viewed` column on the product; OpenCart 4 moved it to its own
     * `product_viewed` table. The column's absence looks like the feature was dropped
     * and it was not — reading only the column would have handed every OpenCart 4 shop
     * a catalogue with no popularity signal, silently and forever.
     *
     * @param int                  $productId
     * @param array<string, mixed> $row
     *
     * @return int|null null when this shop records none, which is left absent rather
     *                  than sent as zero — an invented score would rank a merchant's
     *                  catalogue by something they never chose and cannot inspect
     */
    private function views($productId, array $row)
    {
        switch ($this->schema->viewsSource()) {
            case 'column':
                return isset($row['viewed']) ? (int) $row['viewed'] : null;

            case 'table':
                $result = $this->db->query(
                    "SELECT `viewed` FROM `" . DB_PREFIX . "product_viewed` "
                    . "WHERE `product_id` = " . (int) $productId
                );

                return isset($result->row['viewed']) ? (int) $result->row['viewed'] : null;

            default:
                return null;
        }
    }

    /**
     * OpenCart 4's variant products, folded into their parent.
     *
     * A VARIANT IS A TOP-LEVEL `product` ROW on OpenCart 4, with `master_id` pointing
     * at its parent. Nothing stops a catalogue walk sending them as products in their
     * own right, and doing so is the mistake the wire contract warns about hardest:
     * it fills a shopper's results with near-duplicates and multiplies what the
     * merchant is charged, because one product is one indexed object and one unit of
     * their plan however many variations it has.
     *
     * OpenCart 3 has no such table shape and returns nothing here. Its product
     * options are NOT variants — they are per-line-item choices that adjust a price,
     * not separately stocked products with their own ids — so treating them as
     * variants would invent stock and SKUs a shop does not have.
     *
     * @param int    $productId
     * @param string $currency
     *
     * @return array<int, array<string, mixed>>
     */
    private function variants($productId, $currency)
    {
        if (!$this->schema->hasVariantProducts()) {
            return array();
        }

        $languageId = (int) $this->context['language_id'];

        $result = $this->db->query(
            "SELECT p.`product_id`, p.`sku`, p.`model`, p.`price`, p.`quantity`, p.`subtract`, pd.`name` "
            . "FROM `" . DB_PREFIX . "product` p "
            . "LEFT JOIN `" . DB_PREFIX . "product_description` pd "
            . "ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = " . $languageId . ") "
            . "WHERE p.`master_id` = " . (int) $productId
        );

        $variants = array();
        foreach ($result->rows as $row) {
            $sku = trim((string) $row['sku']) !== '' ? trim((string) $row['sku']) : trim((string) $row['model']);

            $variants[] = array(
                'id' => (int) $row['product_id'],
                'sku' => $sku,
                'price' => $this->money((string) $row['price'], $currency),
                'in_stock' => $this->inStock($row),
                'attributes' => self::text((string) $row['name']) !== '' ? array('Variant' => array(self::text((string) $row['name']))) : array(),
            );
        }

        return $variants;
    }

    /**
     * @param int $productId
     *
     * @return string
     */
    private function productUrl($productId)
    {
        $query = 'index.php?route=product/product&product_id=' . (int) $productId;

        // OpenCart 4 requires `language` on its own links; OpenCart 3 has no such
        // parameter and ignores it. Present only when the shop told us it has one.
        if (!empty($this->context['language_code'])) {
            $query .= '&language=' . rawurlencode((string) $this->context['language_code']);
        }

        return $this->url($query);
    }

    /**
     * Text as a shopper should read it.
     *
     * ⚠ ENTITIES ARE STORED, NOT RENDERED. OpenCart keeps names and category titles
     * HTML-escaped in the database — `Apple Cinema 30&quot;` and
     * `Laptops &amp; Notebooks` are the literal column values — because its templates
     * emit them raw. Anything that reads the table directly gets the escaped form,
     * and sending that to a search index puts `&amp;` in a facet label and `&quot;`
     * in a product title, where nothing will ever render it back.
     *
     * Found by serialising the demo catalogue and reading the output; every field
     * looked correct in the shop's own pages the whole time, because the shop is the
     * one place the entities are decoded.
     *
     * Applied to EVERY text field rather than the ones observed to be affected: which
     * columns a merchant has escaped depends on how their data was entered, and the
     * failure is silent either way.
     *
     * @param string $value
     *
     * @return string
     */
    private static function text($value)
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function url($path)
    {
        return rtrim((string) $this->context['shop_url'], '/') . '/' . ltrim($path, '/');
    }
}
