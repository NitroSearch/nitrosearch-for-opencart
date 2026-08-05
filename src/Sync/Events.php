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
     * Every event code this module registers.
     *
     * EXISTS SO UNINSTALL CANNOT MISS ONE. Both adapters used to delete events by
     * rebuilding the code from the model trigger list, which silently could not see
     * a code built any other way — and the storefront row is built another way. An
     * event row outliving its handler is worse than an orphan setting: every page
     * view calls a controller that is no longer there.
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
