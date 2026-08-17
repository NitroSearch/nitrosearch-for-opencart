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
use NitroSearch\Sync\Runner;

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
    /**
     * A NONCE THAT PROVES NOTHING, on purpose. The verify link exists so a merchant
     * can confirm from a browser that their own endpoint answers at all — that it is
     * not behind basic auth, a staging lock or a firewall. It is not a verification,
     * and this value is a placeholder of the right SHAPE so the endpoint accepts it
     * and returns JSON rather than `400 invalid_nonce`.
     */
    const SAMPLE_NONCE = '0123456789abcdef';

    /** @var Settings */
    private $settings;

    /** @var Client */
    private $client;

    /** @var Runner */
    private $runner;

    /** @var string this shop's storefront base url */
    private $siteUrl;

    /**
     * REQUIRED, not optional. It began as a nullable extra so the connect-only
     * callers would not have to build one, which bought a `$runner === null` branch
     * in every method that uses it and — on PHP 8.4 — an implicit-nullable
     * deprecation notice on every admin page load. OpenCart 4.1 runs on PHP 8.1+ and
     * merchants do reach 8.4, so a notice here is a notice in their error log
     * forever.
     */
    public function __construct(Settings $settings, $siteUrl, Runner $runner)
    {
        $this->settings = $settings;
        $this->client = new Client($settings, $siteUrl);
        $this->runner = $runner;
        $this->siteUrl = rtrim((string) $siteUrl, '/');
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
     * Queue the whole catalogue for sending.
     *
     * IT ONLY ENQUEUES. The walk marks every product dirty and returns immediately;
     * the drain empties the queue on its own schedule. Doing the sending here would
     * put an unbounded amount of work inside one admin request, which is the request
     * most likely to be behind a short PHP timeout and the one whose failure a
     * merchant reads as "the button is broken".
     *
     * Safe to press twice — a second press restarts the walk from the beginning, and
     * the outbox coalesces, so a product queued twice is still one row.
     *
     * @return array{ok: bool, queued: int, total: int, error: string}
     */
    public function startFullSync()
    {
        if (!$this->settings->isConnected()) {
            return array('ok' => false, 'queued' => 0, 'total' => 0, 'error' => 'not_connected');
        }

        $fullSync = $this->runner->fullSync();
        $fullSync->start();
        $step = $fullSync->step();

        return array(
            'ok' => true,
            'queued' => (int) $step['enqueued'],
            'total' => (int) $step['total'],
            'error' => '',
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
     * TWO VALUES SURVIVE, and both for reasons that only show up later:
     *
     *  - **The install id**, so a reconnect is recognisably the same shop rather than
     *    a new one.
     *  - **The cron token**, because it is embedded in the url the merchant already
     *    gave their host's scheduler. Purging it made that url answer `403 forbidden`
     *    permanently — nothing re-minted it — and re-minting would have been no better:
     *    the merchant's saved url would then be wrong rather than unauthorised, and
     *    either way their catalogue quietly stops syncing with no error on any screen.
     *    Found by disconnecting a shop in the sandbox and watching every subsequent
     *    cron tick refuse.
     *
     * @return array{ok: bool}
     */
    public function disconnect()
    {
        $installId = $this->settings->installId();
        $drainToken = $this->settings->drainToken();

        $this->settings->purge();
        $this->settings->update(array(
            'INSTALL_ID' => $installId,
            'DRAIN_TOKEN' => $drainToken,
        ));

        return array('ok' => true);
    }

    /**
     * Turn the anonymous usage beacon on or off.
     *
     * A DELIBERATE TOGGLE RATHER THAN A FORM FIELD, because this screen has no form
     * — every other control on it is an action that answers JSON and reloads. The
     * value is read from the request by the adapter and arrives here already cast,
     * so `src/` stays free of OpenCart's request object.
     *
     * @param bool $on
     *
     * @return array{ok: bool, share_search_data: bool}
     */
    public function setShareSearchData($on)
    {
        $this->settings->update(array('SHARE_SEARCH_DATA' => (bool) $on));

        return array('ok' => true, 'share_search_data' => (bool) $on);
    }

    /**
     * Save the storefront settings the Configure screen owns.
     *
     * TAKES A PLAIN ARRAY, never OpenCart's request object — `src/` is copied
     * verbatim into both archives and may not know what a request looks like. Each
     * adapter reads its own superglobal and hands the values here.
     *
     * ABSENT IS NOT THE SAME AS OFF, EXCEPT FOR A CHECKBOX. An unchecked checkbox
     * is simply not posted, so the two booleans are read as "present and truthy",
     * which is the only reading that lets a merchant turn one OFF. Every other key
     * is left alone when absent, so a partial POST — or a future form that renders
     * a subset — cannot silently reset a choice the merchant made earlier.
     *
     * VALUES ARE VALIDATED BY {@see \NitroSearch\Support\Design::normalise()} AND
     * REJECTED, NOT CLAMPED. These end up interpolated into CSS custom properties
     * on a live storefront; a value outside the offered list can only come from a
     * hand-made request, and writing a guess for it would be a worse answer than
     * keeping what was already there.
     *
     * @param array<string, mixed> $posted
     * @param bool                 $isFormPost whether the two checkboxes are meaningful
     *
     * @return array{ok: bool, saved: array<int, string>, rejected: array<int, string>}
     */
    public function saveSettings(array $posted, $isFormPost = true)
    {
        $values = array();
        $rejected = array();

        foreach (array_keys(\NitroSearch\Support\Design::choices()) as $key) {
            $field = strtolower($key);

            if (!array_key_exists($field, $posted)) {
                continue;
            }

            $clean = \NitroSearch\Support\Design::normalise($key, $posted[$field]);

            if ($clean === null) {
                $rejected[] = $field;
                continue;
            }

            $values[$key] = $clean;
        }

        if ($isFormPost) {
            $values['RESULTS_TAKEOVER'] = !empty($posted['results_takeover']);
            $values['SHOW_BADGE'] = !empty($posted['show_badge']);
        }

        // THE SERVICE ADDRESS, AND ONLY WHILE DISCONNECTED. This shop's key id,
        // secret, collection and scoped search key were all issued BY the service at
        // the current address; repointing a connected shop leaves every one of them
        // aimed at a host that has never heard of them, and the result looks
        // configured and cannot sync. Refusing here rather than in the template is
        // the half that matters — the template only decides what is easy to do.
        if (isset($posted['api_url']) && !$this->settings->isConnected()) {
            $url = trim((string) $posted['api_url']);

            // https only, and a host that is actually a host. This value is the
            // origin every signed request goes to; downgrading it to http would put
            // the sync secret on the wire in clear.
            if ($url !== '' && preg_match('~^https://[a-z0-9.-]+(:[0-9]+)?(/.*)?$~i', $url)) {
                $values['API_URL'] = rtrim($url, '/');
            } elseif ($url !== '') {
                $rejected[] = 'api_url';
            }
        }

        if ($values !== array()) {
            $this->settings->update($values);
        }

        return array(
            'ok' => $rejected === array(),
            'saved' => array_map('strtolower', array_keys($values)),
            'rejected' => $rejected,
        );
    }

    /**
     * The `<select>` contents for the appearance form, labelled from the shop's own
     * language file.
     *
     * DERIVED FROM {@see \NitroSearch\Support\Design::choices()}, so a preset added
     * there appears on the screen without either adapter being touched. That matters
     * more here than it looks: the option lists would otherwise be written out THREE
     * times — once per major, once in the resolver — and this module has already
     * shipped a release where a hand-written key list in one controller disagreed
     * with the template it fed, producing four unlabelled buttons in both archives.
     *
     * A VALUE WITH NO TRANSLATION FALLS BACK TO ITS OWN NAME rather than vanishing.
     * An option silently missing from a select is indistinguishable from a preset
     * that was never built, and the merchant cannot choose what they cannot see —
     * whereas `compact` rendered raw is ugly and obvious. Fail toward showing it.
     *
     * @param array<string, string> $lang the loaded language strings
     *
     * @return array<string, array<string, string>> template var => value => label
     */
    public static function designOptions(array $lang)
    {
        // key => the template variable and the `text_<x>_<value>` label prefix.
        $groups = array(
            'DESIGN_LOOK' => array('looks', 'look'),
            'DESIGN_SCHEME' => array('schemes', 'scheme'),
            'DESIGN_CORNERS' => array('corners', 'corners'),
            'DESIGN_WIDTH' => array('widths', 'width'),
            'DESIGN_FILTERS' => array('filters', 'filters'),
        );

        $out = array();

        foreach (\NitroSearch\Support\Design::choices() as $key => $allowed) {
            if ($allowed === array() || !isset($groups[$key])) {
                continue;   // the accent: free text, no options
            }

            list($var, $prefix) = $groups[$key];
            $out[$var] = array();

            foreach ($allowed as $value) {
                $stringKey = 'text_' . $prefix . '_' . $value;
                $out[$var][$value] = isset($lang[$stringKey]) ? $lang[$stringKey] : $value;
            }
        }

        return $out;
    }

    /**
     * The two urls the Configure screen shows a merchant.
     *
     * BUILT HERE RATHER THAN IN EACH ADAPTER, and that is [D-041]'s rule being
     * applied rather than quoted: both controllers were already assembling the verify
     * url by hand, identically, and a third url was about to be pasted into both. The
     * route form is the one thing the two majors DO agree on — one address per
     * capability — so there is nothing framework-shaped left for an adapter to hold.
     *
     * @return array{verify_url: string, cron_url: string}
     */
    public function urls()
    {
        $route = $this->siteUrl . '/index.php?route=extension/nitrosearch/module/nitrosearch/';

        return array(
            'verify_url' => $route . 'verify&nonce=' . self::SAMPLE_NONCE,
            // CARRIES THE CRON TOKEN, because the whole point is that a merchant can
            // copy this into their host's scheduler. Nothing else in this module hands
            // it to them — before this the address existed, was the only way a shop
            // could sync on a schedule, and appeared on no screen and in no document.
            'cron_url' => $route . 'cron&token=' . $this->settings->drainToken(),
        );
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
            'last_sync' => (string) $this->settings->get('LAST_SYNC'),
            'full_sync_active' => (bool) $this->settings->get('FULLSYNC_ACTIVE'),
            'pending' => $this->runner->outbox()->pendingCount(),
            // Rendered as the toggle's checked state. A control whose position is
            // not read back from what was stored is a control that can lie about
            // the setting it claims to show.
            'share_search_data' => (bool) $this->settings->get('SHARE_SEARCH_DATA'),

            // The storefront settings, read back for the same reason. DERIVED from
            // Design::choices() rather than listed again — the form, the save handler
            // and this display all read one list, so a preset added there appears on
            // the screen without a second edit. A control rendered from a hand-written
            // list is one rename away from showing a default while the shop runs
            // something else.
            'results_takeover' => (bool) $this->settings->get('RESULTS_TAKEOVER'),
            'show_badge' => (bool) $this->settings->get('SHOW_BADGE'),
            'api_url' => $this->settings->apiUrl(),
        ) + $this->designState();
    }

    /**
     * The appearance choices, as `design_look => 'roomy'` and so on.
     *
     * @return array<string, string>
     */
    private function designState()
    {
        $out = array();

        foreach (\NitroSearch\Support\Design::choices() as $key => $allowed) {
            $out[strtolower($key)] = (string) $this->settings->get($key);
        }

        return $out;
    }
}
