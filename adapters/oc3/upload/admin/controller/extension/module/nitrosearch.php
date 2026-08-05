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

/**
 * The Configure screen — OpenCart 3 build.
 *
 * ITS LOCATION IS NOT A CHOICE. `admin/controller/extension/module.php` lists
 * modules by globbing `admin/controller/extension/module/*.php`, so a module that
 * lives anywhere else simply does not appear in Extensions → Modules, with no
 * error to explain the absence. The storefront half of this module sits at
 * `catalog/controller/extension/nitrosearch/…` instead, because that half has to
 * share a url with the OpenCart 4 build; the two halves following different
 * conventions is OpenCart's arrangement, not ours.
 *
 * The class name is derived from the route by stripping non-alphanumerics, so
 * `extension/module/nitrosearch` MUST be `ControllerExtensionModuleNitrosearch`.
 */
class ControllerExtensionModuleNitrosearch extends Controller
{
    public function index()
    {
        $this->load->language('extension/module/nitrosearch');

        $this->document->setTitle($this->language->get('heading_title'));

        $settings = new Settings($this->db);
        $token = $this->session->data['user_token'];

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_not_connected'] = $this->language->get('text_not_connected');
        $data['text_connected'] = $this->language->get('text_connected');
        $data['text_shop_url'] = $this->language->get('text_shop_url');
        $data['text_verify_url'] = $this->language->get('text_verify_url');

        $data['connected'] = $settings->isConnected();
        $data['shop_url'] = $this->shopUrl();

        // Shown so a merchant can confirm the endpoint answers from the OUTSIDE
        // before anything depends on it. A shop behind basic auth, a firewall or a
        // staging password will fail verification, and the difference between "our
        // service is broken" and "your shop is not reachable from the internet" is
        // worth being able to establish in one click.
        $data['verify_url'] = $this->shopUrl() . '/index.php?route=extension/nitrosearch/module/nitrosearch/verify&nonce=0123456789abcdef';

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $token, true),
            ),
            array(
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module', true),
            ),
            array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/module/nitrosearch', 'user_token=' . $token, true),
            ),
        );

        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module', true);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/nitrosearch', $data));
    }

    /**
     * Called by OpenCart when the merchant installs the module.
     *
     * The install id is minted HERE rather than on first use: it is a signing input
     * and an outbound identifier, so it must exist before anything can attempt to
     * connect, and it must not be derived from anything discoverable.
     */
    public function install()
    {
        $settings = new Settings($this->db);
        $settings->installId();

        // A per-install cron token, minted once. Never derived from the shop URL or
        // the install id — both are discoverable, and a guessable token makes the
        // drain endpoint an unauthenticated way to load someone's server.
        if ((string) $settings->get('DRAIN_TOKEN') === '') {
            $settings->update(array('DRAIN_TOKEN' => bin2hex(random_bytes(16))));
        }
    }

    /**
     * Called by OpenCart when the merchant uninstalls the module.
     *
     * Removes every row this module owns, keyed on the settings `code` rather than
     * an enumerated list — a value added by a later version must not survive an
     * uninstall performed by an earlier one.
     */
    public function uninstall()
    {
        $settings = new Settings($this->db);
        $settings->purge();
    }

    /**
     * This shop's canonical base URL, as the service will see it.
     *
     * @return string
     */
    private function shopUrl()
    {
        $url = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');

        return rtrim((string) $url, '/');
    }
}
