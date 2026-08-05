<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Admin;

use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * What the Configure screen's buttons actually do.
 *
 * THE ORCHESTRATION LIVES HERE, NOT IN THE CONTROLLERS, because it is identical on
 * both majors and the controllers are not. Each adapter's controller is reduced to
 * three lines — check the permission, call one of these, emit JSON — so the logic
 * cannot drift between OpenCart 3 and OpenCart 4 while the two admin themes,
 * class shapes and route conventions do.
 *
 * Every method returns a plain array. Nothing here knows about HTTP responses,
 * templates or sessions.
 */
final class Actions
{
    /** @var Settings */
    private $settings;

    /** @var Client */
    private $client;

    public function __construct(Settings $settings, $siteUrl)
    {
        $this->settings = $settings;
        $this->client = new Client($settings, $siteUrl);
    }

    /**
     * Connect the shop, then immediately ask to be verified.
     *
     * THE TWO STEPS ARE SEPARATE ON PURPOSE, and failing the second is not failing
     * the first. Connecting stores credentials; verification is the service fetching
     * this shop's public endpoint from the outside, which cannot succeed on a
     * localhost install, behind basic auth, or on a staging host with a password.
     * Those shops are correctly connected and simply not yet verified — reporting
     * that as a connect failure would send a merchant looking for a problem in the
     * wrong place, and would tempt them to disconnect and retry, which changes
     * nothing.
     *
     * @return array{ok: bool, connected: bool, verified: bool, error: string, reason: string}
     */
    public function connect()
    {
        $result = $this->client->connect();

        if (empty($result['ok'])) {
            return array(
                'ok' => false,
                'connected' => false,
                'verified' => false,
                'error' => isset($result['error']) ? (string) $result['error'] : 'connect failed',
                'reason' => '',
            );
        }

        $verification = $this->client->verify();

        return array(
            'ok' => true,
            'connected' => true,
            'verified' => !empty($verification['verified']),
            'error' => '',
            'reason' => isset($verification['reason']) ? (string) $verification['reason'] : '',
        );
    }

    /**
     * Re-ask for verification, and pick up anything that follows from it.
     *
     * The button a merchant presses after fixing their hosting. `fetchSearchKey()`
     * runs on success because verification usually happens through the service's own
     * loopback rather than through a call we made — so the shop can become verified
     * without this module ever learning it, and without a key the storefront widget
     * has nothing to search with.
     *
     * @return array{ok: bool, verified: bool, reason: string}
     */
    public function refresh()
    {
        if (!$this->settings->isConnected()) {
            return array('ok' => false, 'verified' => false, 'reason' => 'not_connected');
        }

        $verification = $this->client->verify();

        if (!empty($verification['verified'])) {
            $this->client->fetchSearchKey();
        }

        $this->client->status();

        return array(
            'ok' => !empty($verification['ok']),
            'verified' => !empty($verification['verified']),
            'reason' => isset($verification['reason']) ? (string) $verification['reason'] : '',
        );
    }

    /**
     * Forget this shop's credentials.
     *
     * LOCAL ONLY, AND DELIBERATELY SO. It does not ask the service to delete
     * anything: a merchant who disconnects by accident, or who is moving a shop
     * between domains, should be able to reconnect without having lost their index.
     * Removing data on the service is a separate, explicit request — destroying a
     * catalogue should never be a side effect of a button labelled "disconnect".
     *
     * The install id survives, so a reconnect is recognisably the same shop.
     *
     * @return array{ok: bool}
     */
    public function disconnect()
    {
        $installId = $this->settings->installId();

        $this->settings->purge();
        $this->settings->update(array('INSTALL_ID' => $installId));

        return array('ok' => true);
    }

    /**
     * Everything the Configure screen displays. Never includes a credential.
     *
     * @return array<string, mixed>
     */
    public function state()
    {
        return array(
            'connected' => $this->settings->isConnected(),
            'verified' => (bool) $this->settings->get('VERIFIED'),
            'plan' => (string) $this->settings->get('PLAN'),
            'product_count' => (int) $this->settings->get('PRODUCT_COUNT'),
            'product_limit' => (int) $this->settings->get('PRODUCT_LIMIT'),
            'at_limit' => (bool) $this->settings->get('AT_LIMIT'),
            'last_error' => (string) $this->settings->get('LAST_ERROR'),
        );
    }
}
