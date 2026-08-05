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

use NitroSearch\Admin\Actions;
use NitroSearch\Settings;
use NitroSearch\Sync\Events;
use NitroSearch\Sync\Outbox;
use NitroSearch\Sync\Runner;
use NitroSearch\Support\ShopUrl;

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

        $data = array();
        foreach (array('heading_title', 'text_extension', 'text_edit', 'text_connected', 'text_not_connected',
                       'text_shop_url', 'text_verify_url', 'text_verify_help', 'text_home') as $key) {
            $data[$key] = $this->language->get($key);
        }

        $actions = new Actions($settings, $this->shopUrl(), new Runner($this->db));
        $data = array_merge($data, $actions->state());

        $data['shop_url'] = $this->shopUrl();
        $data['action_connect'] = $this->url->link('extension/module/nitrosearch/connect', 'user_token=' . $token, true);
        $data['action_refresh'] = $this->url->link('extension/module/nitrosearch/refresh', 'user_token=' . $token, true);
        $data['action_disconnect'] = $this->url->link('extension/module/nitrosearch/disconnect', 'user_token=' . $token, true);
        $data['action_sync'] = $this->url->link('extension/module/nitrosearch/sync', 'user_token=' . $token, true);

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

    /** Connect this shop, then ask to be verified. */
    public function connect()
    {
        $this->respondJson(function (Actions $actions) {
            return $actions->connect();
        });
    }

    /** Re-ask for verification and pick up the search key that follows it. */
    public function refresh()
    {
        $this->respondJson(function (Actions $actions) {
            return $actions->refresh();
        });
    }

    /**
     * A product changed. Mark it dirty and return.
     *
     * DOES NO HTTP AND BUILDS NO PAYLOAD. This runs inside the merchant's own save,
     * so anything slow here is slow for them, and anything that throws here breaks a
     * product save outright. One INSERT … ON DUPLICATE KEY UPDATE is the whole job.
     *
     * @param string $route
     * @param array  $args
     * @param mixed  $output
     */
    public function onCatalogueChange($route, $args = array(), $output = null)
    {
        $change = Events::resolve($route, is_array($args) ? $args : array(), $output, $this->db);

        if ($change === null) {
            return;
        }

        $outbox = new Outbox($this->db);
        $outbox->record('product', $change['id'], $change['op']);
    }

    /** Queue the whole catalogue. The drain sends it. */
    public function sync()
    {
        $this->respondJson(function (Actions $actions) {
            return $actions->startFullSync();
        });
    }

    /** Forget this shop's credentials locally. The service keeps the index. */
    public function disconnect()
    {
        $this->respondJson(function (Actions $actions) {
            return $actions->disconnect();
        });
    }

    /**
     * Run one action and emit its result as JSON.
     *
     * THE PERMISSION CHECK IS HERE AND NOT IN Actions, deliberately. Who may do a
     * thing is the framework's question and differs between the majors; what the
     * thing does is ours and does not. Skipping it would let any authenticated admin
     * user — including a limited one — disconnect a shop.
     *
     * @param callable $run
     */
    private function respondJson($run)
    {
        $this->load->language('extension/module/nitrosearch');

        if (!$this->user->hasPermission('modify', 'extension/module/nitrosearch')) {
            $this->emit(array('ok' => false, 'error' => $this->language->get('error_permission')));

            return;
        }

        $settings = new Settings($this->db);
        $this->emit($run(new Actions($settings, $this->shopUrl(), new Runner($this->db))));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(array $payload)
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
    public function install()
    {
        $settings = new Settings($this->db);
        $settings->installId();

        // The outbox must exist before any event can fire. Creating it here rather
        // than lazily on first write means a shop that installs and immediately saves
        // a product does not lose that first change to a missing table.
        $this->db->query(Outbox::schema());

        // OpenCart 3 spells a trigger and an action with SLASHES throughout, matching
        // its own router. OpenCart 4 uses a dot before the method in both. The rows
        // differ; the handler they point at does not.
        $this->load->model('setting/event');
        foreach (Events::triggers() as $trigger) {
            $this->model_setting_event->addEvent(
                'nitrosearch_' . $trigger['method'],
                $trigger['path'] . '/' . $trigger['method'] . '/after',
                'extension/module/nitrosearch/onCatalogueChange'
            );
        }

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

        // The queue is ours alone and describes nothing the shop needs. Left behind it
        // is an orphan table a merchant cannot identify or safely remove.
        $outbox = new Outbox($this->db);
        $outbox->drop();

        // An event row outliving its handler is worse than useless: every product
        // save would try to call a controller that no longer exists.
        $this->load->model('setting/event');
        foreach (Events::triggers() as $trigger) {
            $this->model_setting_event->deleteEventByCode('nitrosearch_' . $trigger['method']);
        }
    }

    /**
     * This shop's canonical base URL, as the service will see it.
     *
     * Resolved from config.php's constants rather than a settings row — see
     * {@see ShopUrl} for why `config_url` is the wrong place to look.
     *
     * @return string
     */
    private function shopUrl()
    {
        return ShopUrl::resolve();
    }
}
