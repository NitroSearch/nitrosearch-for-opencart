<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace Opencart\Catalog\Controller\Extension\Nitrosearch\Module;

require_once DIR_EXTENSION . 'nitrosearch/system/library/nitrosearch/autoload.php';

use NitroSearch\Sync\Events;
use NitroSearch\Sync\Runner;

/**
 * The storefront event handler — OpenCart 4 build.
 *
 * WHY THIS IS A CLASS AND OPENCART 3'S IS A METHOD, again. This major splits an
 * action route on its LAST DOT: `extension/nitrosearch/module/nitrosearch.onStorefront`
 * resolves to `…\Module\Nitrosearch::onStorefront()`, so the handler needs a
 * `Nitrosearch` controller sitting beside the `Nitrosearch/` directory that holds
 * `Verify` and `Cron`. OpenCart 3 pops path segments from the right instead, which
 * lands every one of its storefront entry points on the same single file.
 *
 * A file and a directory of the same name is not a conflict on either the
 * filesystem or in the namespace: `Module\Nitrosearch` is this class,
 * `Module\Nitrosearch\Verify` is the one in the directory.
 *
 * NOTHING HERE IS SHARED WITH THE VERIFY OR CRON CONTROLLERS, deliberately: those
 * emit JSON and `exit`, this one modifies a page and must return. Making them a
 * common base class would put an `exit` one inheritance step away from a shopper's
 * storefront.
 */
class Nitrosearch extends \Opencart\System\Engine\Controller
{
    /**
     * Put the storefront widget into the page, and keep the sync moving.
     *
     *   trigger  catalog/view/common/header/after
     *   action   extension/nitrosearch/module/nitrosearch.onStorefront
     *
     * THE OUTPUT IS MODIFIED IN PLACE AND NOTHING IS RETURNED. This major's event
     * dispatcher discards every handler's return value — `Event::trigger()` ends in
     * `return ''` — so a returned string would simply be thrown away and the page
     * would ship without the widget, with nothing anywhere to say why.
     *
     * ⚠ EVERY PARAMETER HAS A DEFAULT, AND ON THIS MAJOR THAT IS LOAD-BEARING.
     * An event handler is a public controller method, so it is also a reachable
     * storefront url. This major's dispatcher does not check the argument count
     * before calling: it invokes the method with none, and a required parameter
     * therefore produces an uncaught `ArgumentCountError` rendered as a page
     * carrying the fatal, the absolute file path and a full backtrace.
     *
     * That is not speculation and it is not our bug — OpenCart's own bundled
     * handlers do it. Requesting `?route=event/activity.addCustomer` on a stock
     * 4.1.0.3 store answers **HTTP 200** with `Error: Too few arguments to function
     * Opencart\Catalog\Controller\Event\Activity::addCustomer()` and the server's
     * directory layout underneath. Defaults plus the guard below turn that into an
     * empty 200, which is what an unrouted internal handler should be. OpenCart 3
     * refuses the call itself and needs no such care.
     *
     * IT CANNOT THROW. This runs while a shopper's page is being assembled: an
     * exception escaping here is a blank storefront, which is a far worse outcome
     * than a missing search box.
     *
     * @param string $route
     * @param array  $args
     * @param string $output the rendered header markup, modified in place
     */
    public function onStorefront(&$route = null, &$args = null, &$output = null): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        try {
            $runner = new Runner($this->db);

            $output = $runner->storefront()->injectInto($output);

            // The no-cron fallback, and the unattended heartbeat with it. It returns
            // immediately unless the interval has elapsed and there is either sync
            // work or a poll due, and defers everything to shutdown.
            //
            // ⚠ "AFTER THE PAGE" IS ONLY LITERALLY TRUE WHERE `fastcgi_finish_request`
            // EXISTS — see the OpenCart 3 build. Every call the deferred work makes
            // carries a short timeout for that reason.
            $runner->pageLoadTick()->maybeRun();
        } catch (\Exception $e) {
            // Nothing to do and nowhere safe to say it: a storefront page is not
            // ours to interrupt, and the Configure screen's error slot is written by
            // the paths that can reach a database.
        } catch (\Throwable $e) {
        }
    }

    /**
     * Note that a product reached the basket from a search.
     *
     *   trigger  catalog/controller/checkout/cart.add/after
     *   action   extension/nitrosearch/module/nitrosearch.onCartAdd
     *
     * THE ONLY MOMENT THE LINK EXISTS. The widget posts `ns_search=1` and `ns_q`
     * alongside the shop's own add-to-cart fields; nothing downstream carries either
     * one. The basket has no memory of how anything got into it and neither does the
     * order, so a term not written to the session here is a term that is gone.
     *
     * THE POST IS READ FROM THE REQUEST, NOT FROM `$args`. This is a CONTROLLER event,
     * and a controller event's arguments are the method's own arguments —
     * `Cart::add()` takes none, so `$args` is empty and always will be. Reaching for
     * the product id there yields nothing, silently, on every add-to-cart in the shop.
     *
     * ⚠ EVERY PARAMETER HAS A DEFAULT and the route bail is the first statement, for
     * the reasons {@see onStorefront()} sets out at length: on this major an event
     * handler is a public controller method and therefore a storefront url anyone can
     * request, and the dispatcher invokes it with no arguments at all.
     *
     * IT WRITES TO THE SHOP'S OWN SESSION AND NOTHING ELSE — no table, no socket. See
     * {@see \NitroSearch\Sync\OrderAttribution} for why the object it reaches cannot
     * send even if a later edit asked it to.
     *
     * @param string $route
     * @param array  $args
     * @param mixed  $output
     */
    public function onCartAdd(&$route = null, &$args = null, &$output = null): void
    {
        if ($route === null) {
            return;
        }

        try {
            $post = is_array($this->request->post) ? $this->request->post : array();

            $runner = new Runner($this->db);
            $runner->orderAttribution($this->session)->captureAdd($post);
        } catch (\Exception $e) {
            // A checkout is not ours to break and an attribution is worth nothing
            // beside one. Both catches, because the thing an unfamiliar shop produces
            // is as often a TypeError as an exception.
        } catch (\Throwable $e) {
        }
    }

    /**
     * Resolve the session marker into a durable row, inside the shopper's request.
     *
     *   trigger  catalog/model/checkout/order.addOrder/after
     *   trigger  catalog/model/checkout/order.editOrder/after
     *   action   extension/nitrosearch/module/nitrosearch.onOrderCreated
     *
     * TWO TRIGGERS, ONE HANDLER, AND THE ORDER IS NOT A SALE AT EITHER. OpenCart
     * writes the row with `order_status_id` 0 and this major's confirm controller then
     * calls `editOrder` on it while the basket is still changeable. The report is
     * therefore re-resolved rather than written once and trusted, and the write is an
     * upsert. Promotion is a separate hook — see {@see onOrderConfirmed()}.
     *
     * WHY IT RUNS HERE AT ALL, rather than only at confirmation: confirmation is
     * frequently a server-to-server gateway callback with no shopper session, and the
     * session is the only place the search term lives. Resolving it while the shopper
     * is still on the request is what makes the feature work on gateway-driven shops
     * instead of only on cash-on-delivery ones.
     *
     * ⚠ THE ID COMES FROM A DIFFERENT PLACE PER METHOD and {@see Events::orderId()}
     * is where that is spelled out — `addOrder` returns it, `editOrder` takes it. Its
     * `is_scalar` guard is what stops `addOrder`'s data array casting to the integer 1
     * and attributing every order in the shop to order number one.
     *
     * IT QUEUES AND STOPS. No network call is reachable from here; the queue object
     * this builds has no client. The send happens on the cron endpoint and the
     * shutdown-deferred page tick, neither of which is a shopper waiting.
     *
     * @param string $route
     * @param array  $args
     * @param mixed  $output the new order id, on the creating call
     */
    public function onOrderCreated(&$route = null, &$args = null, &$output = null): void
    {
        if ($route === null) {
            return;
        }

        try {
            $orderId = Events::orderId(is_array($args) ? $args : array(), $output);

            if ($orderId <= 0) {
                return;
            }

            $runner = new Runner($this->db);
            $runner->orderAttribution($this->session)->orderCreated($orderId);
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    /**
     * Promote the pending row once the order is really a sale.
     *
     *   trigger  catalog/model/checkout/order.addHistory/after
     *   action   extension/nitrosearch/module/nitrosearch.onOrderConfirmed
     *
     * ⚠ `addHistory` ON THIS MAJOR, `addOrderHistory` ON OPENCART 3. A different name,
     * not a different spelling — the dot-versus-slash split every other row here
     * survives does not cover it, and a trigger built by respelling the OpenCart 3
     * name registers a row on this major that can never fire. The report table would
     * fill with pending rows and send none, with nothing anywhere to say why.
     *
     * NO SESSION IS PASSED, AND NONE IS NEEDED. This hook routinely runs in a gateway
     * callback that has no shopper attached; everything it needs was written to the
     * table by the request that did have one.
     *
     * ⚠ THE STATUS IS NOT TAKEN FROM `$args`, THOUGH THE HOOK HANDS IT OVER.
     * `\NitroSearch\Sync\OrderAttribution::orderConfirmed()` deliberately has no
     * parameter for it and re-reads `order_status_id` from the order's own row, so a
     * hand-crafted request to this method's public url cannot assert that an unpaid
     * order was paid. `$args[0]` is used only as something to look up, which means the
     * worst a forged call can do is re-assert what the shop's own tables already say.
     *
     * @param string $route
     * @param array  $args
     * @param mixed  $output
     */
    public function onOrderConfirmed(&$route = null, &$args = null, &$output = null): void
    {
        if ($route === null) {
            return;
        }

        try {
            $orderId = Events::orderId(is_array($args) ? $args : array(), null);

            if ($orderId <= 0) {
                return;
            }

            $runner = new Runner($this->db);
            $runner->orderAttribution()->orderConfirmed($orderId);
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }
}
