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
use NitroSearch\Sync\Events;
use NitroSearch\Sync\OrderReports;
use NitroSearch\Sync\Outbox;
use NitroSearch\Sync\Runner;
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
        // ⚠ EVERY STRING THE LANGUAGE FILE DEFINES, DERIVED — never a list written here.
        //
        // This was a hardcoded array of thirteen keys, and the template uses sixteen.
        // The four it omitted were `text_connect`, `text_refresh`, `text_sync` and
        // `text_disconnect` — all four present in the language file, all four referenced
        // by the template, and none of them ever assigned. Twig renders an undefined
        // variable as the empty string, so the module shipped **four unlabelled buttons**
        // in both archives of 1.3.0. No error, no warning, nothing in a log: a merchant
        // simply saw a row of blank buttons and had to guess.
        //
        // `load->language()` returns everything the file declares, so a string added
        // later is available to the template the moment it exists rather than the day
        // somebody remembers this array. Same failure as every hardcoded subject list
        // this project keeps meeting; the fix is the same one every time.
        //
        // Assigned BEFORE the state and URL merges below so those still win on any
        // key that appears in both.
        foreach ((array) $this->load->language('extension/nitrosearch/module/nitrosearch') as $key => $value) {
            $data[$key] = $value;
        }

        $actions = new Actions($settings, $this->shopUrl(), new Runner($this->db));
        $data = array_merge($data, $actions->state(), $actions->urls());

        $data['shop_url'] = $this->shopUrl();
        $data['action_connect'] = $this->url->link('extension/nitrosearch/module/nitrosearch.connect', 'user_token=' . $token);
        $data['action_refresh'] = $this->url->link('extension/nitrosearch/module/nitrosearch.refresh', 'user_token=' . $token);
        $data['action_disconnect'] = $this->url->link('extension/nitrosearch/module/nitrosearch.disconnect', 'user_token=' . $token);
        $data['action_sync'] = $this->url->link('extension/nitrosearch/module/nitrosearch.sync', 'user_token=' . $token);
        $data['action_share_data'] = $this->url->link('extension/nitrosearch/module/nitrosearch.shareData', 'user_token=' . $token);
        $data['action_save'] = $this->url->link('extension/nitrosearch/module/nitrosearch.save', 'user_token=' . $token);

        // The appearance selects, derived from Design::choices() and labelled from
        // the language file this controller already loaded. Never a list here.
        $data = array_merge($data, Actions::designOptions(
            (array) $this->load->language('extension/nitrosearch/module/nitrosearch')
        ));

        // Rendered after a save so the merchant is told it happened. Read from the
        // query string rather than the session because it survives the redirect
        // without a second write, and it says nothing a URL should not carry.
        $data['saved'] = isset($this->request->get['saved']);

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

    /**
     * Turn the anonymous usage beacon on or off.
     *
     * READS THE VALUE HERE so `src/` never touches OpenCart's request object. The
     * cast is deliberate and one-directional: anything that is not the string '1'
     * is off, so a malformed or missing parameter turns sharing OFF rather than on.
     * A privacy control that fails open is not a control.
     */
    public function shareData(): void
    {
        $on = isset($this->request->post['share']) && $this->request->post['share'] === '1';

        $this->respondJson(fn (Actions $actions) => $actions->setShareSearchData($on));
    }

    /**
     * Save the appearance and behaviour settings.
     *
     * A FORM POST AND A REDIRECT, not the JSON the buttons use. The buttons are
     * actions with a result worth reporting in place; this is a settings save, and
     * redirecting means the screen the merchant reads afterwards is rendered from
     * what was actually stored — not from what the browser believed it sent.
     *
     * READS THE SUPERGLOBAL HERE so `src/` never learns what an OpenCart request
     * is. Actions takes a plain array and decides what is acceptable.
     */
    public function save(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/nitrosearch/module/nitrosearch')) {
            $this->session->data['error'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link(
                'extension/nitrosearch/module/nitrosearch',
                'user_token=' . $this->session->data['user_token']
            ));

            return;
        }

        $settings = new Settings($this->db);
        $actions = new Actions($settings, $this->shopUrl(), new Runner($this->db));
        $actions->saveSettings((array) $this->request->post);

        $this->response->redirect($this->url->link(
            'extension/nitrosearch/module/nitrosearch',
            'user_token=' . $this->session->data['user_token'] . '&saved=1'
        ));
    }

    /**
     * A product changed. Mark it dirty and return.
     *
     * DOES NO HTTP AND BUILDS NO PAYLOAD. This runs inside the merchant's own save,
     * so anything slow here is slow for them, and anything that throws here breaks a
     * product save outright. One INSERT … ON DUPLICATE KEY UPDATE is the whole job.
     *
     * @param mixed $output
     */
    public function onCatalogueChange(string &$route, array &$args, &$output): void
    {
        $change = Events::resolve($route, $args, $output, $this->db);

        if ($change === null) {
            return;
        }

        $outbox = new Outbox($this->db);
        $outbox->record('product', $change['id'], $change['op']);
    }

    /** Queue the whole catalogue. The drain sends it. */
    public function sync(): void
    {
        $this->respondJson(fn (Actions $actions) => $actions->startFullSync());
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
        $this->emit($run(new Actions($settings, $this->shopUrl(), new Runner($this->db))));
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

        // The outbox must exist before any event can fire. Creating it here rather
        // than lazily on first write means a shop that installs and immediately saves
        // a product does not lose that first change to a missing table.
        $this->db->query(Outbox::schema());

        // The order-report queue, for the same reason and with more of it: this table
        // is written from inside a shopper's checkout, so a shop that took an order
        // before the table existed would lose the attribution and record the failure
        // in the merchant's error slot rather than anywhere useful. It is InnoDB —
        // see the class — because a checkout must take a row lock and not a table one.
        $this->db->query(OrderReports::schema());

        // OpenCart 4 puts a DOT before the method in both the trigger and the action,
        // matching its own router; OpenCart 3 uses slashes throughout. The rows
        // differ; the handler they point at does not.
        $this->load->model('setting/event');
        foreach (Events::triggers() as $trigger) {
            $this->model_setting_event->addEvent([
                'code' => 'nitrosearch_' . $trigger['method'],
                'description' => 'NitroSearch: queue a catalogue change',
                'trigger' => $trigger['path'] . '.' . $trigger['method'] . '/after',
                'action' => 'extension/nitrosearch/module/nitrosearch.onCatalogueChange',
                'status' => 1,
                'sort_order' => 0,
            ]);
        }

        // The storefront. A CATALOG event, unlike the four above, and this major's
        // startup controller keeps only the rows whose first segment names its own
        // application — so the `catalog/` prefix is what puts this on the storefront
        // rather than in the back office.
        $storefront = Events::storefrontTrigger();
        $this->model_setting_event->addEvent([
            'code' => $storefront['code'],
            'description' => 'NitroSearch: emit the storefront widget',
            'trigger' => $storefront['trigger'],
            'action' => 'extension/nitrosearch/module/nitrosearch.onStorefront',
            'status' => 1,
            'sort_order' => 0,
        ]);

        // ── Order attribution ────────────────────────────────────────────────────
        //
        // FOUR ROWS ON THIS MAJOR AND THREE ON OPENCART 3, and the difference is not a
        // spelling one. `Events::orderTriggers()` keys each method by major precisely
        // so this loop cannot build a trigger for a hook the running major does not
        // have; `editOrder` is this major's alone, because OpenCart 3's confirm
        // controller re-runs `addOrder` instead of editing.
        //
        // ⚠ THE ACTIONS ARE WRITTEN OUT IN FULL AND NOT ASSEMBLED FROM THE CODE, which
        // is a decision about legibility rather than a limitation. A handler name that
        // only exists as a fragment concatenated at runtime is invisible to a grep for
        // "is this handler registered anywhere" — which is the exact check that stands
        // between this module and the sibling connector's incident, where a handler was
        // written, was correct, was reviewed, and was pointed at by nothing. The one
        // string that has to match a method on the storefront controller is spelled
        // here the way it is spelled there.
        //
        // A code with no entry in this map is SKIPPED rather than registered against a
        // half-built action, so a fifth trigger added to `Events` without a line here
        // is a hook that quietly does nothing until someone places a test order — bad,
        // but survivable, where a row pointing at a missing controller would fire on
        // every checkout in the shop. `Events::codes()` still removes it at uninstall.
        $actions = [
            'nitrosearch_cart_add' => 'extension/nitrosearch/module/nitrosearch.onCartAdd',
            'nitrosearch_order_created' => 'extension/nitrosearch/module/nitrosearch.onOrderCreated',
            'nitrosearch_order_edited' => 'extension/nitrosearch/module/nitrosearch.onOrderCreated',
            'nitrosearch_order_confirmed' => 'extension/nitrosearch/module/nitrosearch.onOrderConfirmed',
        ];

        $attribution = array_merge([Events::cartTrigger()], Events::orderTriggers());

        foreach ($attribution as $trigger) {
            if (!isset($trigger['methods']['oc4']) || !isset($actions[$trigger['code']])) {
                continue;
            }

            $this->model_setting_event->addEvent([
                'code' => $trigger['code'],
                'description' => $trigger['description'],
                'trigger' => $trigger['path'] . '.' . $trigger['methods']['oc4'] . '/after',
                'action' => $actions[$trigger['code']],
                'status' => 1,
                'sort_order' => 0,
            ]);
        }

        // A per-install cron token, minted once and kept for the life of the install
        // — including across a disconnect, because it is embedded in the url the
        // merchant gave their scheduler. Minted through Settings so install-time and
        // any later caller share one path; see {@see Settings::drainToken()}.
        $settings->drainToken();
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

        // The queue is ours alone and describes nothing the shop needs. Left behind it
        // is an orphan table a merchant cannot identify or safely remove.
        $outbox = new Outbox($this->db);
        $outbox->drop();

        // The report queue goes with it. It holds order ids, values and search terms
        // for the merchant's own shop and would be an unidentifiable orphan table left
        // behind — and the report table's whole point is that it is short-lived.
        //
        // BUILT WITHOUT A CLIENT, as it is everywhere outside the two unattended send
        // paths. Dropping a table has no business holding something that can open a
        // socket.
        $reports = new OrderReports($this->db);
        $reports->drop();

        // An event row outliving its handler is worse than useless: every product
        // save — and, for the storefront row, every page view — would try to call a
        // controller that no longer exists.
        //
        // ⚠ AND, SINCE ATTRIBUTION, EVERY ADD TO BASKET AND EVERY ORDER. The rows this
        // now removes are registered against `checkout/cart.add` and `checkout/order`,
        // so one missed code is not a slow back office — it is a fatal inside a
        // shopper's checkout on a shop that believes it has uninstalled this module.
        //
        // ASKS `Events` FOR THE CODES rather than rebuilding them from the trigger
        // list, which is what this did and which could not see a code built any other
        // way. The storefront row is built another way.
        $this->load->model('setting/event');
        foreach (Events::codes() as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
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
