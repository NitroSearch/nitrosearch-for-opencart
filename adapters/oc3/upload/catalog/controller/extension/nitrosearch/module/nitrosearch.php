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

        // Keep a full walk moving FIRST. Enumeration feeds the queue the drain then
        // empties, so this order makes one invocation make progress on both rather
        // than draining an empty queue and stopping.
        $runner->fullSync()->resumeIfStalled();

        $result = $runner->drain()->run(20);
        $settings->update(array('DRAIN_RAN_AT' => time()));

        $this->respond(array(
            'ok' => true,
            'batches' => $result['batches'],
            'items' => $result['items'],
            'stopped' => $result['stopped'],
            'pending' => $result['pending'],
            'full_sync_active' => $runner->fullSync()->isActive(),
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

            // The no-cron fallback. It returns immediately unless the interval has
            // elapsed AND there is work, and defers the sending until after the
            // shopper's page has been flushed — so this costs a page view nothing.
            $runner->pageLoadTick()->maybeRun();
        } catch (\Exception $e) {
            // Nothing to do and nowhere safe to say it: a storefront page is not
            // ours to interrupt, and the Configure screen's error slot is written by
            // the paths that can reach a database.
        } catch (\Throwable $e) {
        }
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
