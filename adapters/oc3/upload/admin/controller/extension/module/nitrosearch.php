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
use NitroSearch\Sync\OrderReports;
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
        foreach ((array) $this->load->language('extension/module/nitrosearch') as $key => $value) {
            $data[$key] = $value;
        }

        $actions = new Actions($settings, $this->shopUrl(), new Runner($this->db));
        $data = array_merge($data, $actions->state(), $actions->urls());

        $data['shop_url'] = $this->shopUrl();
        $data['action_connect'] = $this->url->link('extension/module/nitrosearch/connect', 'user_token=' . $token, true);
        $data['action_refresh'] = $this->url->link('extension/module/nitrosearch/refresh', 'user_token=' . $token, true);
        $data['action_disconnect'] = $this->url->link('extension/module/nitrosearch/disconnect', 'user_token=' . $token, true);
        $data['action_sync'] = $this->url->link('extension/module/nitrosearch/sync', 'user_token=' . $token, true);
        $data['action_share_data'] = $this->url->link('extension/module/nitrosearch/shareData', 'user_token=' . $token, true);
        $data['action_save'] = $this->url->link('extension/module/nitrosearch/save', 'user_token=' . $token, true);

        // The appearance selects, derived from Design::choices() and labelled from
        // the language file this controller already loaded. Never a list here.
        $data = array_merge($data, Actions::designOptions(
            (array) $this->load->language('extension/module/nitrosearch')
        ));

        $data['saved'] = isset($this->request->get['saved']);

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
     * Turn the anonymous usage beacon on or off.
     *
     * READS THE VALUE HERE so `src/` never touches OpenCart's request object. The
     * cast is deliberate and one-directional: anything that is not the string '1'
     * is off, so a malformed or missing parameter turns sharing OFF rather than on.
     * A privacy control that fails open is not a control.
     */
    public function shareData()
    {
        $on = isset($this->request->post['share']) && $this->request->post['share'] === '1';

        $this->respondJson(function (Actions $actions) use ($on) {
            return $actions->setShareSearchData($on);
        });
    }

    /**
     * Save the appearance and behaviour settings.
     *
     * A FORM POST AND A REDIRECT, not the JSON the buttons use — see the OpenCart 4
     * build for the reasoning. The permission key is this major's own shorter route
     * form, which is what OpenCart 3 granted at install time.
     */
    public function save()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/nitrosearch')) {
            $this->session->data['error'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link(
                'extension/module/nitrosearch',
                'user_token=' . $this->session->data['user_token'],
                true
            ));

            return;
        }

        $settings = new Settings($this->db);
        $actions = new Actions($settings, $this->shopUrl(), new Runner($this->db));
        $actions->saveSettings((array) $this->request->post);

        $this->response->redirect($this->url->link(
            'extension/module/nitrosearch',
            'user_token=' . $this->session->data['user_token'] . '&saved=1',
            true
        ));
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

        // The order-report queue, for the same reason and one more. A shop that
        // installs and takes an order in the next minute must not lose it to a missing
        // table — and unlike a catalogue row, an order report cannot be rebuilt later
        // from anything: the link between a search term and a line exists only in the
        // shopper's session, for the length of one visit.
        //
        // ⚠ THE STATEMENT CARRIES `ENGINE=InnoDB` EXPLICITLY, WHICH MATTERS HERE AND
        // ON NO OTHER MAJOR. OpenCart 3 still creates MyISAM tables, and MyISAM takes a
        // TABLE-level lock on every write. This table is written inside a shopper's
        // checkout, so inheriting the default would make every concurrent checkout on a
        // busy shop queue behind every other one — the "never slow a merchant's
        // checkout" rule failing as a performance cliff rather than as an error anybody
        // would see. See {@see OrderReports::schema()}.
        $this->db->query(OrderReports::schema());

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

        // The storefront. A CATALOG event, unlike the four above, so its action names
        // the catalog controller — `extension/nitrosearch/…`, where the back office
        // half is `extension/module/…`. The two halves of this module following
        // different directory conventions is OpenCart 3's arrangement, not ours, and
        // this line is where it is easiest to get wrong.
        $storefront = Events::storefrontTrigger();
        $this->model_setting_event->addEvent(
            $storefront['code'],
            $storefront['trigger'],
            'extension/nitrosearch/module/nitrosearch/onStorefront'
        );

        // ── Search → order attribution ──────────────────────────────────────────
        //
        // CATALOG ROWS, LIKE THE STOREFRONT ONE ABOVE AND UNLIKE THE FOUR PRODUCT ROWS,
        // so their actions name the catalog controller — `extension/nitrosearch/…`
        // where this back-office half is `extension/module/…`. That the two halves of
        // one module follow different directory conventions is OpenCart 3's
        // arrangement, not ours, and it is the easiest line here to get wrong.
        //
        // ⚠ THESE RUN ON THE CHECKOUT PATH, WHICH RAISES THE COST OF EVERY MISTAKE IN
        // THIS BLOCK. A product row that points at nothing is a slow back office; one
        // of these pointing at nothing is a missing controller called inside every
        // add-to-cart and every order a shop takes. That is also why {@see
        // Events::codes()} is asked for the uninstall list rather than it being
        // rebuilt here — see uninstall() below.
        $cart = Events::cartTrigger();
        $this->model_setting_event->addEvent(
            $cart['code'],
            $cart['path'] . '/' . $cart['methods']['oc3'] . '/after',
            'extension/nitrosearch/module/nitrosearch/onCartAdd'
        );

        // THE HANDLER NAMES ARE WRITTEN OUT HERE rather than derived from the code,
        // because a registration is only worth anything if the method on the other end
        // of it exists — and these literals are what lets that be checked without
        // booting a shop. `Events` owns the triggers, which is where the two majors
        // genuinely disagree; this file owns the action, which is spelled with a slash
        // on this major and a dot on the other.
        $handlers = array(
            'nitrosearch_order_created' => 'onOrderCreated',
            'nitrosearch_order_confirmed' => 'onOrderConfirmed',
        );

        foreach (Events::orderTriggers() as $order) {
            // A HOOK THIS MAJOR DOES NOT HAVE. OpenCart 4's confirm controller calls
            // `editOrder` on an order it already created; this one has no such branch
            // and calls `addOrder` again on every render of the confirm page. The
            // absence of an `oc3` method is that statement, made once in `Events` where
            // both majors can be read side by side, rather than as a version test here.
            if (!isset($order['methods']['oc3'])) {
                continue;
            }

            // A code with no handler on this side. Registering it against a guessed
            // method would point a checkout-path row at something that may not exist;
            // registering nothing leaves that part of attribution simply off, which is
            // the recoverable direction. `Events::codes()` still knows the code, so an
            // uninstall removes any row an older version did write.
            if (!isset($handlers[$order['code']])) {
                continue;
            }

            $this->model_setting_event->addEvent(
                $order['code'],
                $order['path'] . '/' . $order['methods']['oc3'] . '/after',
                'extension/nitrosearch/module/nitrosearch/' . $handlers[$order['code']]
            );
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
    public function uninstall()
    {
        $settings = new Settings($this->db);
        $settings->purge();

        // The queue is ours alone and describes nothing the shop needs. Left behind it
        // is an orphan table a merchant cannot identify or safely remove.
        $outbox = new Outbox($this->db);
        $outbox->drop();

        // The order-report queue goes with it, and its rows are worth a sentence: what
        // is lost here is any report not yet sent. That is deliberate — a merchant who
        // uninstalls has withdrawn consent for the thing those rows exist to do, and
        // leaving a table of order values behind on their database would be the wrong
        // answer to both questions. Built without a client, so this cannot send.
        $reports = new OrderReports($this->db);
        $reports->drop();

        // An event row outliving its handler is worse than useless: every product
        // save — and, for the storefront row, every page view — would try to call a
        // controller that no longer exists.
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
     *
     * @return string
     */
    private function shopUrl()
    {
        return ShopUrl::resolve();
    }
}
