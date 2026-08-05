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
use NitroSearch\Sync\Runner;

/**
 * The drain entry point — OpenCart 4 build.
 *
 *   index.php?route=extension/nitrosearch/module/nitrosearch/cron&token=…
 *
 * THE IDENTICAL URL THE OPENCART 3 BUILD ANSWERS, from a `cron()` method on its own
 * controller. OpenCart 4 splits a route on its last dot and defaults to `index()`, so
 * a dot-free path resolves one class deeper and lands here; OpenCart 3 pops segments
 * from the right and lands on a method. Same arrangement as the verify endpoint, and
 * the reason the service needs one address per capability rather than one per major.
 *
 * OPENCART 4 HAS A CRON TABLE, AND IT IS NOT USED. `oc_cron` only runs when
 * something hits its own endpoint, so it is a scheduler with no clock — adopting it
 * would add a per-major code path and a dependency on the merchant setting up
 * exactly the same thing they must set up anyway.
 */
class Cron extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $settings = new Settings($this->db);

        $provided = isset($this->request->get['token']) ? (string) $this->request->get['token'] : '';
        $expected = (string) $settings->get('DRAIN_TOKEN');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->respond(['error' => 'forbidden'], 403);
        }

        if (!$settings->isConnected()) {
            $this->respond(['error' => 'not_connected'], 409);
        }

        $runner = new Runner($this->db);

        // Keep a full walk moving FIRST — see the OpenCart 3 build for why.
        $runner->fullSync()->resumeIfStalled();

        $result = $runner->drain()->run(20);
        $settings->update(['DRAIN_RAN_AT' => time()]);

        $this->respond([
            'ok' => true,
            'batches' => $result['batches'],
            'items' => $result['items'],
            'stopped' => $result['stopped'],
            'pending' => $result['pending'],
            'full_sync_active' => $runner->fullSync()->isActive(),
        ], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $status): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, $status);
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo json_encode($payload);
        exit;
    }
}
