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

use NitroSearch\Settings;

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

        $data['connected'] = $settings->isConnected();
        $data['shop_url'] = $this->shopUrl();

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

    private function shopUrl(): string
    {
        $url = $this->config->get('config_ssl') ?: $this->config->get('config_url');

        return rtrim((string) $url, '/');
    }
}
