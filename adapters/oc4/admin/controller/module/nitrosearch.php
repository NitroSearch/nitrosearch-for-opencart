<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace Opencart\Admin\Controller\Extension\Nitrosearch\Module;

require_once DIR_EXTENSION . 'nitrosearch/system/library/nitrosearch/autoload.php';

use NitroSearch\Admin\Actions;
use NitroSearch\Settings;
use NitroSearch\Support\ShopUrl;

/**
 * The Configure screen — OpenCart 4 build.
 *
 * DISCOVERY IS BY FILESYSTEM, REGISTRATION IS BY DATABASE, and the order matters.
 * `admin/controller/extension/module.php` lists modules with
 * `glob(DIR_EXTENSION . '*​/admin/controller/module/*.php')`, so this file is visible
 * as soon as the archive is installed — no namespace registration required to find
 * it. Installing the module then writes the `oc_extension` row, and only THAT makes
 * `catalog/controller/startup/extension.php` register
 * `Opencart\Catalog\Controller\Extension\Nitrosearch` on storefront requests.
 *
 * So the storefront verify endpoint does not answer until the merchant has
 * installed the module here — not merely uploaded the archive. OpenCart 3 has no
 * equivalent step, which is why the README spells this one out.
 */
class Nitrosearch extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->load->language('extension/nitrosearch/module/nitrosearch');

        $this->document->setTitle($this->language->get('heading_title'));

        $settings = new Settings($this->db);
        $token = $this->session->data['user_token'];

        $data = array();
        foreach (array('heading_title', 'text_extension', 'text_edit', 'text_connected', 'text_not_connected',
                       'text_shop_url', 'text_verify_url', 'text_verify_help', 'text_home') as $key) {
            $data[$key] = $this->language->get($key);
        }

        $actions = new Actions($settings, $this->shopUrl());
        $data = array_merge($data, $actions->state());

        $data['shop_url'] = $this->shopUrl();
        $data['action_connect'] = $this->url->link('extension/nitrosearch/module/nitrosearch.connect', 'user_token=' . $token);
        $data['action_refresh'] = $this->url->link('extension/nitrosearch/module/nitrosearch.refresh', 'user_token=' . $token);
        $data['action_disconnect'] = $this->url->link('extension/nitrosearch/module/nitrosearch.disconnect', 'user_token=' . $token);

        // Shown so a merchant can confirm the endpoint answers from the OUTSIDE
        // before anything depends on it. A shop behind basic auth, a firewall or a
        // staging password will fail verification, and the difference between "our
        // service is broken" and "your shop is not reachable from the internet" is
        // worth being able to establish in one click.
        $data['verify_url'] = $this->shopUrl() . '/index.php?route=extension/nitrosearch/module/nitrosearch/verify&nonce=0123456789abcdef';

        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $token),
            ],
            [
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module'),
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/nitrosearch/module/nitrosearch', 'user_token=' . $token),
            ],
        ];

        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/nitrosearch/module/nitrosearch', $data));
    }

    /** Connect this shop, then ask to be verified. */
    public function connect(): void
    {
        $this->respondJson(fn (Actions $actions) => $actions->connect());
    }

    /** Re-ask for verification and pick up the search key that follows it. */
    public function refresh(): void
    {
        $this->respondJson(fn (Actions $actions) => $actions->refresh());
    }

    /** Forget this shop's credentials locally. The service keeps the index. */
    public function disconnect(): void
    {
        $this->respondJson(fn (Actions $actions) => $actions->disconnect());
    }

    /**
     * Run one action and emit its result as JSON.
     *
     * THE PERMISSION CHECK IS HERE AND NOT IN Actions, deliberately. Who may do a
     * thing is the framework's question and differs between the majors; what the
     * thing does is ours and does not. Skipping it would let any authenticated admin
     * user — including a limited one — disconnect a shop.
     *
     * The permission key is the EXTENSION-QUALIFIED route, which is what OpenCart 4
     * granted at install time; the OpenCart 3 build's key is its own shorter form.
     */
    private function respondJson(callable $run): void
    {
        $this->load->language('extension/nitrosearch/module/nitrosearch');

        if (!$this->user->hasPermission('modify', 'extension/nitrosearch/module/nitrosearch')) {
            $this->emit(['ok' => false, 'error' => $this->language->get('error_permission')]);

            return;
        }

        $settings = new Settings($this->db);
        $this->emit($run(new Actions($settings, $this->shopUrl())));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(array $payload): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($payload));
    }

    /**
     * Called by OpenCart when the merchant installs the module.
     *
     * The install id is minted HERE rather than on first use: it is a signing input
     * and an outbound identifier, so it must exist before anything can attempt to
     * connect, and it must not be derived from anything discoverable.
     */
    public function install(): void
    {
        $settings = new Settings($this->db);
        $settings->installId();

        // A per-install cron token, minted once. Never derived from the shop URL or
        // the install id — both are discoverable, and a guessable token makes the
        // drain endpoint an unauthenticated way to load someone's server.
        if ((string) $settings->get('DRAIN_TOKEN') === '') {
            $settings->update(['DRAIN_TOKEN' => bin2hex(random_bytes(16))]);
        }
    }

    /**
     * Called by OpenCart when the merchant uninstalls the module.
     *
     * Removes every row this module owns, keyed on the settings `code` rather than
     * an enumerated list — a value added by a later version must not survive an
     * uninstall performed by an earlier one.
     */
    public function uninstall(): void
    {
        $settings = new Settings($this->db);
        $settings->purge();
    }

    /**
     * This shop's canonical base URL, as the service will see it.
     *
     * Resolved from config.php's constants rather than a settings row — see
     * {@see ShopUrl} for why `config_url` is the wrong place to look.
     */
    private function shopUrl(): string
    {
        return ShopUrl::resolve();
    }
}
