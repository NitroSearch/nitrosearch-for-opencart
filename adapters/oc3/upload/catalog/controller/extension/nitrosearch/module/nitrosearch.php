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
