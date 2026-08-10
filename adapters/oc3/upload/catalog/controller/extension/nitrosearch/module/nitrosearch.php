<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

require_once DIR_SYSTEM . 'library/nitrosearch/autoload.php';

use NitroSearch\Settings;
use NitroSearch\Sync\Events;
use NitroSearch\Sync\OrderAttribution;
use NitroSearch\Sync\Runner;
use NitroSearch\Support\VerifyChallenge;

/**
 * The storefront controller — OpenCart 3 build.
 *
 * ROUTE, AND WHY IT IS SHAPED LIKE THIS:
 *
 *   index.php?route=extension/nitrosearch/module/nitrosearch/verify&nonce=…
 *
 * OpenCart 3's dispatcher (`system/engine/action.php`) pops path segments from the
 * RIGHT until a controller file exists, so `…/nitrosearch/verify` resolves to this
 * file with `verify` as the method. OpenCart 4 resolves the SAME url to a class one
 * level deeper — `…\Module\Nitrosearch\Verify::index()` — which is why the OC4 build
 * ships a differently-shaped file behind the identical route. One url, two builds,
 * and the service therefore needs only one path for the platform.
 *
 * The class name is dictated: OpenCart 3 derives it from the route by stripping
 * non-alphanumerics, so `extension/nitrosearch/module/nitrosearch` MUST be
 * `ControllerExtensionNitrosearchModuleNitrosearch` and nothing else. A mismatch is
 * a fatal, not a 404.
 */
class ControllerExtensionNitrosearchModuleNitrosearch extends Controller
{
    /**
     * Proof-of-control endpoint. NitroSearch fetches this to confirm that this shop
     * controls its own hostname before it will index anything.
     *
     * IT IS DELIBERATELY PUBLIC, AND THAT IS SAFE. The service is unauthenticated
     * when it makes this call — it is trying to establish who we are, so it has
     * nothing to authenticate with yet. What makes the endpoint safe is that the
     * answer is an HMAC over this shop's sync secret: a caller who does not hold the
     * secret cannot produce it, so simply reflecting the nonce never passes. And
     * because the proof is domain-separated from the ingest signature, this endpoint
     * can never be used as an oracle to sign an ingest request.
     */
    public function verify()
    {
        $nonce = isset($this->request->get['nonce']) ? $this->request->get['nonce'] : null;

        if (!VerifyChallenge::acceptableNonce($nonce)) {
            $this->respond(array('error' => 'invalid_nonce'), 400);
        }

        $settings = new Settings($this->db);
        $secret = (string) $settings->get('SYNC_SECRET');

        if ($secret === '') {
            // Not connected yet, so there is no secret to prove control with. 409
            // rather than 500: nothing is broken, the handshake simply has not
            // happened in this order.
            $this->respond(array('error' => 'not_connected'), 409);
        }

        $this->respond(array('proof' => VerifyChallenge::proof($nonce, $secret)), 200);
    }

    /**
     * The drain entry point.
     *
     *   index.php?route=extension/nitrosearch/module/nitrosearch/cron&token=…
     *
     * THE SAME URL THE OPENCART 4 BUILD ANSWERS, which is why this is a method here
     * rather than a `cron.php` beside this file. OpenCart 3 pops path segments from
     * the right until a controller exists, so `…/nitrosearch/cron` lands on this
     * file's `cron()`; OpenCart 4 splits on the last dot, so the same url resolves one
     * class deeper to `…\Module\Nitrosearch\Cron::index()`. Two shapes, one url —
     * the same arrangement the verify endpoint uses, and the reason the service needs
     * only one address per capability rather than one per major.
     *
     * A merchant points their host's cron at it every few minutes. Neither major has a
     * job queue this can use, so a request IS the worker.
     *
     * TOKEN-GATED, WITH A CONSTANT-TIME COMPARISON. The token is not worth much on its
     * own — the worst it buys an attacker is making us sync — but an unauthenticated
     * endpoint that performs unbounded work is a free denial-of-service against the
     * merchant's own server. `hash_equals` rather than `===` because a plain
     * comparison leaks the token's prefix through timing.
     */
    public function cron()
    {
        $settings = new Settings($this->db);

        $provided = isset($this->request->get['token']) ? (string) $this->request->get['token'] : '';
        $expected = (string) $settings->get('DRAIN_TOKEN');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->respond(array('error' => 'forbidden'), 403);
        }

        if (!$settings->isConnected()) {
            $this->respond(array('error' => 'not_connected'), 409);
        }

        $runner = new Runner($this->db);

        // The unattended heartbeat. IT BELONGS HERE AS WELL AS ON THE PAGE-LOAD
        // FALLBACK, and for a reason worth stating: a shop with working cron never
        // reaches the body of `PageLoadTick`, because the cron keeps `DRAIN_RAN_AT`
        // fresh. Wiring the heartbeat only to the fallback would give it to shops
        // that ignored the setup instructions and withhold it from the ones that
        // followed them.
        //
        // It has its own five-minute clock, so calling it from a cron running every
        // minute costs four cheap settings reads and one request.
        $runner->resyncCheck()->maybeRun();

        // Keep a full walk moving FIRST. Enumeration feeds the queue the drain then
        // empties, so this order makes one invocation make progress on both rather
        // than draining an empty queue and stopping.
        $runner->fullSync()->resumeIfStalled();

        $result = $runner->drain()->run(20);

        // Search-attributed orders. THE ONLY PLACE ON THIS MAJOR, BESIDES THE
        // PAGE-LOAD FALLBACK, THAT EVER SENDS ONE — the checkout path writes a row to
        // a local table and stops there, so if neither of those two runs the reports
        // are queued forever and the merchant's dashboard reads zero with nothing
        // anywhere to explain it.
        //
        // ⚠ CALLED UNCONDITIONALLY, AND NOT BEHIND A `readyCount() > 0` TEST. The first
        // thing `flush()` does is sweep expired rows, and the rows that need sweeping
        // most on this major are `pending` ones — OpenCart 3's confirm controller calls
        // `addOrder` again on every render of the confirm page (3.0.5.0,
        // catalog/controller/checkout/confirm.php:324, verified 2026-08-10 — there is
        // no reuse branch there, unlike OpenCart 4's), so an indecisive shopper leaves
        // several order rows at status 0 and a `pending` report behind each of them.
        // Gating the call on there being something READY would leave exactly those
        // shops sweeping nothing, forever.
        //
        // Ten per tick sits far inside the service's per-store budget, and the flush
        // stops at the first retryable failure rather than working through the queue.
        $reports = $runner->orderReports()->flush(10);

        $settings->update(array('DRAIN_RAN_AT' => time()));

        $this->respond(array(
            'ok' => true,
            'batches' => $result['batches'],
            'items' => $result['items'],
            'stopped' => $result['stopped'],
            'pending' => $result['pending'],
            'full_sync_active' => $runner->fullSync()->isActive(),
            'orders_sent' => $reports['sent'],
            'orders_ready' => $reports['ready'],
            'orders_stopped' => $reports['stopped'],
        ), 200);
    }

    /**
     * Put the storefront widget into the page, and keep the sync moving.
     *
     *   trigger  catalog/view/common/header/after
     *   action   extension/nitrosearch/module/nitrosearch/onStorefront
     *
     * OpenCart hands a view event the rendered output BY REFERENCE and expects it to
     * be changed in place. On this major the event system ALSO takes a returned value
     * as a replacement for the whole output — and stops calling every other handler
     * registered on the same trigger the moment one returns anything at all. So this
     * returns nothing, ever. Returning the modified markup would work perfectly on a
     * shop with no other extensions and silently disable theirs on every shop that
     * has any.
     *
     * EVERY PARAMETER HAS A DEFAULT, and that is not tidiness. An event handler is a
     * public controller method, so it is also a reachable url — `…?route=extension/
     * nitrosearch/module/nitrosearch/onStorefront`. This major's dispatcher refuses a
     * call with fewer arguments than the method requires and renders its 404 page, so
     * defaults are belt-and-braces here; on OpenCart 4 they are load-bearing, and the
     * two builds keeping the same signature is worth more than either one being
     * minimal.
     *
     * IT CANNOT THROW. This runs while a shopper's page is being assembled: an
     * exception escaping here is a blank storefront, which is a far worse outcome
     * than a missing search box.
     *
     * @param string $route
     * @param array  $data
     * @param string $output the rendered header markup, modified in place
     */
    public function onStorefront(&$route = null, &$data = null, &$output = null)
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
            // EXISTS. Under mod_php it does not, and shutdown functions run BEFORE
            // output buffers flush — so on those hosts the deferred work really is
            // inside the shopper's request. That is why every call it makes carries a
            // short timeout rather than the default: the honest claim is that this
            // costs a page view very little, not nothing.
            $runner->pageLoadTick()->maybeRun();
        } catch (\Exception $e) {
            // Nothing to do and nowhere safe to say it: a storefront page is not
            // ours to interrupt, and the Configure screen's error slot is written by
            // the paths that can reach a database.
        } catch (\Throwable $e) {
        }
    }

    /**
     * A product reached the basket from a search. Note it against the session.
     *
     *   trigger  catalog/controller/checkout/cart/add/after
     *   action   extension/nitrosearch/module/nitrosearch/onCartAdd
     *
     * ⚠ THE POST IS READ FROM THE REQUEST, NOT FROM `$args`, AND THAT IS THE ONE
     * MISTAKE THIS HANDLER CANNOT SURVIVE. This is a CONTROLLER event, not a model
     * one, and OpenCart 3 passes a controller event `array(&$route, &$data, &$output)`
     * where `$data` is the argument array the LOADER was given — and the main
     * dispatched route is not loaded through the loader at all. `startup/router.php`
     * builds it with `$data = array()` and passes that empty array straight through
     * (3.0.5.0, catalog/controller/startup/router.php, verified 2026-08-10). A handler
     * that looked for `product_id` in `$args` would find nothing, on every add, on
     * every shop, and would simply attribute no orders — with no error anywhere and a
     * dashboard that reads zero rather than wrong. The second parameter is still
     * ACCEPTED, because the dispatcher passes three arguments and this major refuses a
     * call with fewer parameters than the method requires.
     *
     * WHAT IS WRITTEN IS A NOTE IN THE SHOP'S OWN SESSION and nothing else: no
     * request leaves the server, and nothing at all happens unless the merchant has
     * left "share anonymous search usage" on. The widget stops sending the marker at
     * the same moment, so this is the second of two independent gates.
     *
     * @param string $route
     * @param array  $args   the loader's own arguments — EMPTY here; see above
     * @param mixed  $output
     */
    public function onCartAdd(&$route = null, &$args = null, &$output = null)
    {
        // NOT AN EVENT. A handler is a public controller method, so it is also a
        // reachable storefront url — and this major's dispatcher will happily call it
        // with no arguments at all, because every parameter has a default and
        // `Action::execute()` only refuses a call with fewer arguments than the method
        // REQUIRES (3.0.5.0, system/engine/action.php, verified 2026-08-10). The
        // dispatcher always passes the route by reference; a url invocation passes
        // nothing. This line is the whole difference.
        if ($route === null) {
            return;
        }

        try {
            $post = isset($this->request->post) && is_array($this->request->post)
                ? $this->request->post
                : array();

            $this->attribution()->captureAdd($post);
        } catch (\Exception $e) {
            // A shopper's basket is not ours to break for the sake of an analytics
            // note, and there is nowhere on an add-to-cart response to say so.
        } catch (\Throwable $e) {
        }
    }

    /**
     * An order row exists. Resolve the marker against its real lines, durably.
     *
     *   trigger  catalog/model/checkout/order/addOrder/after
     *   action   extension/nitrosearch/module/nitrosearch/onOrderCreated
     *
     * IT RUNS HERE, IN THE SHOPPER'S OWN REQUEST, BECAUSE THIS IS THE LAST MOMENT THE
     * SESSION EXISTS. The order is not a sale yet — OpenCart writes the row with
     * `order_status_id` 0 — but the marker that says which lines came from a search
     * lives in the shopper's session, and the call that later makes the order real is
     * very often a gateway posting back to the server with no session at all. So the
     * resolution happens now and the promotion happens later.
     *
     * ⚠ ONLY ONE ROW POINTS AT THIS ON OPENCART 3, WHERE OPENCART 4 HAS TWO, and the
     * difference is the platform's rather than ours. OpenCart 4's confirm controller
     * calls `editOrder` on an order it already created; this major's has no such
     * branch and calls `addOrder` again on every render of the confirm page (3.0.5.0,
     * catalog/controller/checkout/confirm.php:324, verified 2026-08-10). So a basket
     * changed after the first render is picked up by `addOrder` firing again, and the
     * write is an upsert for that reason. Registering an `editOrder` row here as well
     * would look symmetrical and would mostly fire from the back office API, where
     * there is no session and no marker — which resolves to nothing and would DELETE a
     * pending report a shopper had legitimately earned.
     *
     * THE ID COMES FROM THE RETURN VALUE. `addOrder($data)` returns the new order id,
     * so it is in `$output`; its `$args[0]` is the order DATA ARRAY, whose integer cast
     * is 1. {@see Events::orderId()} takes the return value first and refuses a
     * non-scalar fallback for exactly that reason.
     *
     * @param string $route
     * @param array  $args   the model method's own arguments
     * @param mixed  $output the model method's return value — the new order id
     */
    public function onOrderCreated(&$route = null, &$args = null, &$output = null)
    {
        if ($route === null) {
            return;
        }

        try {
            $this->attribution()->orderCreated(
                Events::orderId(is_array($args) ? $args : array(), $output)
            );
        } catch (\Exception $e) {
            // This runs inside `addOrder`, which is to say inside the checkout. There
            // is no failure here worth showing a shopper mid-purchase.
        } catch (\Throwable $e) {
        }
    }

    /**
     * The order became a sale. Promote its report, once.
     *
     *   trigger  catalog/model/checkout/order/addOrderHistory/after
     *   action   extension/nitrosearch/module/nitrosearch/onOrderConfirmed
     *
     * ⚠ THE METHOD IS NAMED `addOrderHistory` ON THIS MAJOR AND `addHistory` ON
     * OPENCART 4 — a RENAME, not the dot-versus-slash spelling difference every other
     * trigger in this module has. Wiring it as though it were the same name registers
     * a row on one major that can never fire on the other, and the symptom is a shop
     * that captures every marker, writes every pending row and reports nothing, with
     * no error at any point. The two spellings live together in {@see Events} so that
     * they cannot drift apart.
     *
     * THE STATUS IS NOT TAKEN FROM THE ARGUMENTS. `addOrderHistory($order_id,
     * $order_status_id, …)` hands both over and only the ID is used, as something to
     * LOOK UP; the status is re-read from `oc_order`. A hand-crafted request to this
     * handler's url therefore cannot assert that an unpaid order was paid, and with no
     * pending row it does nothing whatsoever.
     *
     * ⚠ THIS FIRES AGAIN ON EVERY LATER TRANSITION — an admin marking an order
     * shipped, a refund, a completion — and the promotion is written to happen exactly
     * once for that reason. See {@see \NitroSearch\Sync\OrderReports::promote()}: the
     * failure mode is a merchant's revenue counted twice with nothing anywhere to show
     * for it.
     *
     * @param string $route
     * @param array  $args   `$args[0]` is the order id; the status is deliberately unused
     * @param mixed  $output `addOrderHistory` returns nothing at all on this major
     */
    public function onOrderConfirmed(&$route = null, &$args = null, &$output = null)
    {
        if ($route === null) {
            return;
        }

        try {
            $this->attribution()->orderConfirmed(
                Events::orderId(is_array($args) ? $args : array(), $output)
            );
        } catch (\Exception $e) {
            // A payment callback that 500s because an analytics row would not write is
            // a gateway that retries, or worse, a merchant chasing a payment that
            // actually succeeded.
        } catch (\Throwable $e) {
        }
    }

    /**
     * The attribution half, built WITHOUT a {@see Runner}.
     *
     * ⚠ AND THAT OMISSION IS THE POINT RATHER THAN AN ECONOMY. `Runner` is the object
     * that knows how to construct an API client; the three handlers above all run on a
     * merchant's checkout path, so the thing that can open a socket is never built
     * there at all. It is the difference between "this code does not send" and "this
     * code cannot send", and only the second survives somebody editing it later. The
     * sending lives in `cron()` and in the shutdown-deferred page tick, both of which
     * are unattended and neither of which is a shopper waiting.
     *
     * `$this->session` IS PASSED AND MAY BE ABSENT DOWNSTREAM. It is present on all
     * three of these hooks, since all three are reached through a storefront request —
     * but the confirmation hook is also reachable from a gateway callback, and the
     * design needs no session there because the row it promotes already exists.
     *
     * @return OrderAttribution
     */
    private function attribution()
    {
        return new OrderAttribution($this->db, new Settings($this->db), $this->session);
    }

    /**
     * Emit JSON and stop.
     *
     * THE CONTENT TYPE IS PART OF THE CONTRACT. The service requires
     * `application/json` and treats anything else as a failed proof — deliberately,
     * so that an arbitrary HTML page which happens to contain a `{"proof":…}` string
     * cannot pass verification. Nothing here renders a template, and `exit` is what
     * stops OpenCart appending one.
     *
     * @param array<string, string> $payload
     * @param int                   $status
     */
    private function respond(array $payload, $status)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, $status);
            // This answer is per-nonce and must never be served from a cache — a
            // cached proof would be replayed for a different challenge and fail.
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo json_encode($payload);
        exit;
    }
}
