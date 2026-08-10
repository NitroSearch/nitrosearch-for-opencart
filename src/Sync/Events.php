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
 * Catalogue changes, caught as they happen.
 *
 * WITHOUT THIS THE ONLY WAY TO SYNC IS A FULL WALK, and a merchant who edits one
 * price would wait for the next one. With it, a save marks one row dirty and the
 * next drain sends one item.
 *
 * OPENCART'S EVENT SYSTEM IS THE SAME IDEA IN BOTH MAJORS AND SPELLED DIFFERENTLY
 * IN EACH. `oc_event` rows point a trigger at an action, and both use the
 * separator their own router uses:
 *
 *   OC3   admin/model/catalog/product/editProduct/after → extension/module/nitrosearch/onProduct
 *   OC4   admin/model/catalog/product.editProduct/after → extension/nitrosearch/module/nitrosearch.onProduct
 *
 * So the ROWS are built per major and the HANDLER is shared — the same split as
 * everything else here.
 *
 * ⚠ AN IDENTICAL NAME AND SIGNATURE IS NOT AN IDENTICAL CONTRACT. This file first
 * said the split "holds because the two majors' product model methods are identical
 * in name and signature", and they are — and `copyProduct` still behaves differently
 * in each, returning the new id on OpenCart 4 and nothing at all on OpenCart 3. The
 * signatures were never the thing that mattered. See {@see resolve()}.
 */
final class Events
{
    /**
     * The triggers this module listens for, unseparated.
     *
     * Each entry is [model path, method, op]. The adapter joins them with its own
     * major's separator, because that is the only part that differs.
     *
     * `copyProduct` IS INCLUDED and is easy to forget: duplicating a product is how
     * a lot of catalogues are actually built, and a copy that never syncs is a
     * product a shopper cannot find with no error anywhere. It is also where the two
     * majors diverge hardest — see {@see resolve()}.
     *
     * @return array<int, array{path: string, method: string, op: string}>
     */
    public static function triggers()
    {
        return array(
            array('path' => 'admin/model/catalog/product', 'method' => 'addProduct', 'op' => 'upsert'),
            array('path' => 'admin/model/catalog/product', 'method' => 'editProduct', 'op' => 'upsert'),
            array('path' => 'admin/model/catalog/product', 'method' => 'copyProduct', 'op' => 'upsert'),
            array('path' => 'admin/model/catalog/product', 'method' => 'deleteProduct', 'op' => 'delete'),
        );
    }

    /**
     * The storefront trigger, which is a different animal from the four above.
     *
     * THOSE ARE MODEL EVENTS IN THE ADMIN APPLICATION; this is a VIEW event in the
     * catalog one, and the distinction decides where the row is registered. Each
     * major's startup controller reads `oc_event` and keeps only the rows whose
     * first segment names its own application — so the `catalog/` prefix here is
     * what puts this on the storefront and keeps it out of the back office.
     *
     * ⚠ OPENCART 3 DOES NOT ACTUALLY FILTER. Its catalog startup controller strips
     * the first segment of EVERY row, whatever it says, so the module's `admin/…`
     * model triggers are also registered on the storefront — harmless, because the
     * catalog application has no `addProduct` model method to fire them, but not
     * something to rely on. OpenCart 4's startup controller does filter properly.
     *
     * `common/header` IS THE ONLY INJECTION POINT THE TWO MAJORS SHARE without
     * editing a theme. Its rendered output is the whole document head, which is
     * where a config blob has to be defined before the loader script runs.
     * `document->addScript()` exists in both and takes a URL only, so there is
     * nowhere to put the blob through it. OpenCart's own bundled Google extension
     * injects its site tag through this identical trigger on OpenCart 3, which is
     * the closest thing to a blessing the platform offers.
     *
     * THE ACTION IS NOT HERE, because it is the one part that genuinely differs:
     * OpenCart 3 spells a controller method with a slash and OpenCart 4 with a dot,
     * exactly as with the model triggers. Each adapter supplies its own.
     *
     * @return array{code: string, trigger: string}
     */
    public static function storefrontTrigger()
    {
        return array(
            'code' => 'nitrosearch_storefront',
            'trigger' => 'catalog/view/common/header/after',
        );
    }

    /**
     * The add-to-cart trigger — order attribution's only window onto a search term.
     *
     * THIS IS THE ONE MOMENT AT WHICH A SEARCH AND A PRODUCT ARE IN THE SAME REQUEST.
     * The basket keeps no memory of how anything got into it, and the order keeps
     * none either, so a term not written down here is a term that no longer exists by
     * the time the shopper pays. That is why the handler writes to the shop's own
     * session rather than deferring the question to checkout: there is nothing to
     * defer it to.
     *
     * A CONTROLLER EVENT, unlike the order rows below, which are model events. The
     * distinction is not cosmetic — a controller event's arguments are the method's
     * own arguments, and `add()` takes none, so the handler reads the request body
     * directly. Expecting the POST in `$args` is the silent-empty failure this note
     * exists to prevent.
     *
     * THE SEPARATOR IS THE ADAPTER'S JOB, exactly as with the model triggers above:
     * `catalog/controller/checkout/cart/add/after` on OpenCart 3 and
     * `catalog/controller/checkout/cart.add/after` on OpenCart 4. So is the ACTION,
     * which names a controller method and is spelled differently again per major.
     *
     * THE CODE IS FIXED AND MAJOR-AGNOSTIC rather than derived from the method name.
     * {@see codes()} builds the product codes as `nitrosearch_<method>`, and for the
     * confirmation hook below the method name DIFFERS BETWEEN MAJORS — so a derived
     * code would give uninstall a list that matches on one major and misses on the
     * other. One fixed code is correct on both.
     *
     * @return array{code: string, path: string, methods: array<string, string>, description: string}
     */
    public static function cartTrigger()
    {
        return array(
            'code' => 'nitrosearch_cart_add',
            'path' => 'catalog/controller/checkout/cart',
            'methods' => array('oc3' => 'add', 'oc4' => 'add'),
            'description' => 'NitroSearch: note a search-driven add to basket',
        );
    }

    /**
     * The order lifecycle rows — where attribution becomes durable, and then real.
     *
     * ⚠ THESE DIFFER BETWEEN THE MAJORS IN THREE WAYS, NOT ONE, and only the first is
     * the separator split the rest of this file already handles:
     *
     *   1. SEPARATOR.   `order/addOrder/after` against `order.addOrder/after`.
     *   2. METHOD NAME. The confirmation hook is `addOrderHistory` on OpenCart 3 and
     *      `addHistory` on OpenCart 4. A DIFFERENT NAME, not a different spelling of
     *      the same one — treating it as a separator difference registers a row that
     *      can never fire on one of the two majors, and nothing anywhere says so.
     *   3. WHETHER THE ROW EXISTS AT ALL. OpenCart 4's confirm controller calls
     *      `editOrder` on an order it already created; OpenCart 3's has no such branch
     *      and calls `addOrder` again on every render of the confirm page. So the
     *      `editOrder` row belongs to OpenCart 4 and there is nothing for it to point
     *      at on OpenCart 3.
     *
     * `methods` IS KEYED BY MAJOR FOR EXACTLY THAT REASON, and the absence of a key is
     * the statement "this major has no such hook". An adapter that iterates blindly
     * would build `catalog/model/checkout/order/editOrder/after` on OpenCart 3 — a row
     * that is registered, is counted, looks right in a `SELECT`, and never fires.
     *
     * WHY TWO SEPARATE ROWS POINT AT ONE HANDLER on OpenCart 4: the basket can still
     * change between creation and confirmation, so the report has to be re-resolved
     * from the order's own lines rather than written once and trusted. The write is an
     * upsert for that reason.
     *
     * THE ORDER IS NOT A SALE AT ANY OF THE FIRST TWO. OpenCart writes the row with
     * `order_status_id` 0 and only a payment extension calling the history method with
     * a configured status makes it real — frequently from a server-to-server gateway
     * callback with no shopper session at all. That split is the whole reason the work
     * is in two phases rather than one.
     *
     * @return array<int, array{code: string, path: string, methods: array<string, string>, description: string}>
     */
    public static function orderTriggers()
    {
        return array(
            array(
                'code' => 'nitrosearch_order_created',
                'path' => 'catalog/model/checkout/order',
                'methods' => array('oc3' => 'addOrder', 'oc4' => 'addOrder'),
                'description' => 'NitroSearch: resolve a search-attributed order',
            ),
            array(
                'code' => 'nitrosearch_order_edited',
                'path' => 'catalog/model/checkout/order',
                // OpenCart 4 only — see the third difference in the docblock above.
                'methods' => array('oc4' => 'editOrder'),
                'description' => 'NitroSearch: re-resolve a search-attributed order',
            ),
            array(
                'code' => 'nitrosearch_order_confirmed',
                'path' => 'catalog/model/checkout/order',
                'methods' => array('oc3' => 'addOrderHistory', 'oc4' => 'addHistory'),
                'description' => 'NitroSearch: promote a report once the order is a sale',
            ),
        );
    }

    /**
     * The order id a fired order event is about.
     *
     * THE ID IS IN A DIFFERENT PLACE DEPENDING ON THE METHOD, and this is the same
     * trap {@see resolve()} documents for products, in a place where it costs more:
     *
     *   addOrder(array $data): int             the RETURN value
     *   editOrder(int $id, array $data)        `$args[0]`
     *   addHistory(int $id, int $status, …)    `$args[0]`   OpenCart 4
     *   addOrderHistory(int $id, int $s, …)    `$args[0]`   OpenCart 3, same hook
     *
     * ⚠ THE FALLBACK IS GUARDED WITH `is_scalar` AND THAT GUARD IS THE ENTIRE CARE IN
     * THIS FUNCTION. `addOrder`'s first argument is the order DATA ARRAY, and casting a
     * non-empty array to int in PHP yields **1** — no error, no warning, just the
     * number one. An unguarded fallback would therefore resolve every newly created
     * order on the shop to order id 1: one report, overwritten forever, carrying some
     * other customer's basket. The array can never be mistaken for an id because it is
     * never read as one.
     *
     * TAKING THE RETURN VALUE FIRST is what makes one function serve all four shapes
     * without asking which route fired: only `addOrder` returns anything, and the two
     * that do not return null, which is not scalar.
     *
     * @param array<int, mixed> $args   the method's arguments
     * @param mixed             $output the method's return value
     *
     * @return int 0 when nothing usable was found
     */
    public static function orderId(array $args, $output)
    {
        $id = is_scalar($output) ? (int) $output : 0;

        if ($id <= 0 && isset($args[0]) && is_scalar($args[0])) {
            $id = (int) $args[0];
        }

        return $id > 0 ? $id : 0;
    }

    /**
     * Every event code this module registers.
     *
     * EXISTS SO UNINSTALL CANNOT MISS ONE. Both adapters used to delete events by
     * rebuilding the code from the model trigger list, which silently could not see
     * a code built any other way — and the storefront row is built another way. An
     * event row outliving its handler is worse than an orphan setting: every page
     * view calls a controller that is no longer there.
     *
     * ⚠ AND NOW EVERY ADD-TO-CART AND EVERY ORDER, which is to say the checkout path.
     * The attribution rows added below are registered against `checkout/cart.add` and
     * `checkout/order`, so a code this list cannot see leaves a merchant who
     * uninstalled the module with a dead controller call inside every purchase their
     * shop attempts. The product rows were only ever a slow back office.
     *
     * @return array<int, string>
     */
    public static function codes()
    {
        $codes = array();

        foreach (self::triggers() as $trigger) {
            $codes[] = 'nitrosearch_' . $trigger['method'];
        }

        $storefront = self::storefrontTrigger();
        $codes[] = $storefront['code'];

        $cart = self::cartTrigger();
        $codes[] = $cart['code'];

        // EVERY MAJOR'S ROWS, NOT THIS MAJOR'S. `editOrder` exists on OpenCart 4 only,
        // and this list is asked for by an uninstall that has no idea which major
        // wrote the rows it is deleting — a shop restored from a backup, or migrated,
        // can carry rows the running major never registered. Deleting a code that was
        // never inserted costs one no-op; missing one costs a fatal per checkout.
        foreach (self::orderTriggers() as $trigger) {
            $codes[] = $trigger['code'];
        }

        return $codes;
    }

    /**
     * Work out which product a fired event is about.
     *
     * THE ID IS IN A DIFFERENT PLACE DEPENDING ON THE METHOD, and getting it from
     * the wrong one is silent: `addProduct` RETURNS the new id, so it is in
     * `$output`, while `editProduct` and `deleteProduct` take it as their first
     * argument. Reading `$args[0]` for an add yields the data array, whose integer
     * cast is 1 — so every new product would mark product 1 dirty and the real one
     * would never sync.
     *
     * ⚠ `copyProduct` BEHAVES DIFFERENTLY IN EACH MAJOR, despite an identical name
     * and signature, and the difference is invisible until a copy silently fails to
     * sync:
     *
     *   OC4  returns the new id, AND calls addProduct THROUGH THE LOADER — so the
     *        addProduct event fires too, with the right id.
     *   OC3  returns NOTHING, and calls `$this->addProduct()` on the model itself,
     *        which bypasses the loader entirely — so no addProduct event fires and
     *        `$output` is null. Neither the event nor its arguments carry the new id.
     *
     * On OpenCart 3 the only thing that knows the new id is the table. `$db` is
     * consulted for exactly that case: the row was inserted moments earlier in this
     * same request, so the highest id IS the copy. It is a narrow fallback and it is
     * deliberately not used anywhere else — `MAX(id)` is a guess about the world, and
     * the only reason it is sound here is that the insert is what triggered us.
     *
     * @param string      $route  the model route that fired, e.g. `catalog/product/editProduct`
     * @param array       $args   the method's arguments
     * @param mixed       $output the method's return value
     * @param object|null $db     needed only to resolve an OpenCart 3 copy
     *
     * @return array{id: int, op: string}|null null when nothing usable was found
     */
    public static function resolve($route, array $args, $output, $db = null)
    {
        $method = self::method($route);

        if ($method === 'addproduct') {
            $id = (int) $output;

            return $id > 0 ? array('id' => $id, 'op' => 'upsert') : null;
        }

        if ($method === 'copyproduct') {
            $id = (int) $output;

            // OpenCart 3: no return value and no addProduct event. Ask the table.
            if ($id <= 0 && $db !== null) {
                $id = self::newestProductId($db);
            }

            return $id > 0 ? array('id' => $id, 'op' => 'upsert') : null;
        }

        if ($method === 'editproduct') {
            $id = isset($args[0]) ? (int) $args[0] : 0;

            return $id > 0 ? array('id' => $id, 'op' => 'upsert') : null;
        }

        if ($method === 'deleteproduct') {
            $id = isset($args[0]) ? (int) $args[0] : 0;

            return $id > 0 ? array('id' => $id, 'op' => 'delete') : null;
        }

        return null;
    }

    /**
     * The highest product id in the table.
     *
     * ONLY SOUND BECAUSE OF WHEN IT IS CALLED — immediately after the insert that
     * triggered this event, in the same request. Used anywhere else it would be a
     * guess about a table other requests are also writing to.
     *
     * @param object $db
     *
     * @return int
     */
    private static function newestProductId($db)
    {
        $result = $db->query("SELECT MAX(`product_id`) AS `id` FROM `" . DB_PREFIX . "product`");

        return isset($result->row['id']) ? (int) $result->row['id'] : 0;
    }

    /**
     * The method name out of whatever shape of route this major passed.
     *
     * OpenCart hands the handler the route that fired, and the two majors spell it
     * with their own separator — `catalog/product/editProduct` against
     * `catalog/product.editProduct`. Taking the last segment after either one works
     * for both without asking which major is running.
     *
     * @param string $route
     *
     * @return string lowercased
     */
    private static function method($route)
    {
        $route = (string) $route;

        $dot = strrpos($route, '.');
        if ($dot !== false) {
            return strtolower(substr($route, $dot + 1));
        }

        $slash = strrpos($route, '/');
        if ($slash !== false) {
            return strtolower(substr($route, $slash + 1));
        }

        return strtolower($route);
    }
}
