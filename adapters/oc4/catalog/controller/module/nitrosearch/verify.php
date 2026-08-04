<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace Opencart\Catalog\Controller\Extension\Nitrosearch\Module\Nitrosearch;

require_once DIR_EXTENSION . 'nitrosearch/system/library/nitrosearch/autoload.php';

use NitroSearch\Settings;
use NitroSearch\Support\VerifyChallenge;

/**
 * The storefront verify endpoint — OpenCart 4 build.
 *
 * ONE ROUTE, TWO BUILDS. The service knows a single path for this platform:
 *
 *   index.php?route=extension/nitrosearch/module/nitrosearch/verify&nonce=…
 *
 * OpenCart 4 splits a route on its LAST DOT and defaults the method to `index()`,
 * so a path-shaped route with no dot does not fail — it resolves one class deeper.
 * That is why this is `Module\Nitrosearch\Verify::index()` and not a `verify()`
 * method on a `Nitrosearch` controller: the OpenCart 3 build has the latter shape,
 * behind the identical url.
 *
 * THE NAMESPACE IS DICTATED BY THE INSTALLED LAYOUT, not by this file's position in
 * the repository. `catalog/controller/startup/extension.php` registers
 * `Opencart\Catalog\Controller\Extension\Nitrosearch` → `extension/nitrosearch/catalog/controller/`
 * at runtime, for each INSTALLED extension. Two consequences worth stating because
 * neither is visible from the file itself:
 *
 *  - The module must be registered in the database before any of this autoloads.
 *    A file dropped into place is not an installed extension, and testing one that
 *    way proves nothing about what a merchant will get.
 *  - `DIR_EXTENSION` is where the shared library lands, because OpenCart 4's
 *    installer writes only under `extension/<code>/`. The OpenCart 3 build reaches
 *    the same classes through `DIR_SYSTEM`, which is the one line that differs.
 */
class Verify extends \Opencart\System\Engine\Controller
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
    public function index(): void
    {
        $nonce = isset($this->request->get['nonce']) ? $this->request->get['nonce'] : null;

        if (!VerifyChallenge::acceptableNonce($nonce)) {
            $this->respond(['error' => 'invalid_nonce'], 400);
        }

        $settings = new Settings($this->db);
        $secret = (string) $settings->get('SYNC_SECRET');

        if ($secret === '') {
            // Not connected yet, so there is no secret to prove control with. 409
            // rather than 500: nothing is broken, the handshake simply has not
            // happened in this order.
            $this->respond(['error' => 'not_connected'], 409);
        }

        $this->respond(['proof' => VerifyChallenge::proof($nonce, $secret)], 200);
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
     */
    private function respond(array $payload, int $status): void
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
