<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Sync;

use NitroSearch\AdapterKit\Money;
use NitroSearch\Settings;

/**
 * Search → order attribution: the shopper-facing half.
 *
 * When the widget adds a product to the basket it marks the shop's OWN add-to-cart
 * request with `ns_search=1` and `ns_q=<term>`. OpenCart is mid-request at that
 * moment, so the product is noted against the shopper's session — nothing new leaves
 * the browser and nothing is sent anywhere. Later, the lines of an order that came
 * from a search make up the ATTRIBUTED SLICE, and its value is queued for the
 * heartbeat to send.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THIS FILE MAKES NO NETWORK CALL OF ANY KIND, AND THAT IS THE SAFETY PROPERTY
 * RATHER THAN A HAPPY ACCIDENT. Every method here runs on a merchant's checkout
 * path. The {@see OrderReports} it builds is constructed WITHOUT a client, so it is
 * not merely true that nothing here sends — the object that could send is not
 * reachable from here. Sending happens in {@see OrderReports::flush()}, from the
 * cron endpoint and the shutdown-deferred page tick, and from nowhere else.
 *
 * TWO PHASES, AND THE SPLIT IS FORCED BY OPENCART RATHER THAN CHOSEN.
 * `addOrder()` writes a row with `order_status_id` 0; the order only becomes a sale
 * when a payment extension records a confirmed status — and that call is very often
 * a server-to-server gateway callback with NO shopper session at all. So:
 *
 *   1. {@see orderCreated()} runs at `addOrder`/`editOrder`, in the shopper's own
 *      browser request, where the session marker is readable. It resolves the marker
 *      against the order's real lines and writes a DURABLE `pending` row.
 *   2. {@see orderConfirmed()} runs at the order-history hook and needs no session,
 *      because the row already exists. It only promotes.
 *
 * Reading the marker at confirmation instead — the obvious single-phase design —
 * would report every cash-on-delivery shop and nothing at all for every PayPal-style
 * one. Not a small sample: a biased one, silently.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT NEVER LEAVES THE SHOP: the real order id (hashed with this install's id
 * first), the customer, the address, the payment, the order total, and every line
 * the shopper did not reach through search. The wire carries a value, a currency, an
 * opaque reference, the ids of the attributed lines and the term that led to them.
 *
 * GATED ON THE MERCHANT'S ANALYTICS CHOICE. A merchant who declined to share search
 * usage has their revenue left alone too; the switch would otherwise mean less than
 * it says on the screen. The widget stops emitting the marker at the same moment,
 * so this is the second of two independent gates rather than the only one.
 */
final class OrderAttribution
{
    /** Where the marker lives inside OpenCart's own server-side session. */
    const SESSION_KEY = 'nitrosearch_attr';

    /**
     * How long a search stays responsible for a purchase: seven days.
     *
     * The same window every other connector uses, and the same one the service
     * assumes. A basket abandoned for a fortnight and completed later did not come
     * from that search in any sense a merchant would recognise.
     */
    const WINDOW_SECONDS = 604800;

    /**
     * Ceiling on marked products per session.
     *
     * NOT A PERFORMANCE GUARD — a bound on how much a hostile or broken client can
     * push into a session it partly controls, since the marker is written from a
     * request whose body the browser supplies. A shopper with twenty-five
     * search-driven items in one basket is already unusual; one with ten thousand is
     * not a shopper.
     */
    const MAX_TRACKED = 25;

    /** The wire accepts at most this many item ids per report. */
    const MAX_ITEM_IDS = 100;

    /** The wire refuses a value above this, as a hard error rather than a clamp. */
    const MAX_VALUE_CENTS = 100000000;

    /** @var object OpenCart's DB library — query()/escape(), same in both majors */
    private $db;

    /** @var Settings */
    private $settings;

    /**
     * OpenCart's session object, or null where there is none.
     *
     * TOUCHED ONLY THROUGH ITS PUBLIC `data` ARRAY, which is the same deliberate
     * exception `Settings` makes for `$db`: the property and its semantics are
     * identical in both majors, and taking the object beats reimplementing session
     * storage. Null is a real case, not a defensive one — the confirmation hook can
     * run in a gateway callback that has no session, and it needs none.
     *
     * @var object|null
     */
    private $session;

    /**
     * @param object      $db
     * @param Settings    $settings
     * @param object|null $session OpenCart's session object
     */
    public function __construct($db, Settings $settings, $session = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->session = $session;
    }

    /**
     * Note that a product reached the basket from a search.
     *
     * Called from the add-to-cart request, which is the only moment at which the link
     * between a search term and a product exists — the basket itself has no memory of
     * how anything got into it.
     *
     * GATED ON `SHARE_SEARCH_DATA` ONLY, deliberately not on being connected. This
     * writes to the shop's own session and sends nothing; a shop that connects
     * tomorrow should be able to attribute a basket filled today, and refusing to
     * remember costs the merchant data for no benefit to anyone.
     *
     * A PER-PRODUCT MAP, `{productId: {q, t}}`, NOT ONE TIMESTAMP FOR THE SESSION.
     * The single-timestamp shape looks equivalent and is not: refreshed by every add
     * and expired as a whole, it keeps attributing a product added twenty days ago
     * for as long as any other search-driven add keeps happening. Per-product `t` is
     * what makes the seven-day window mean seven days.
     *
     * @param array<string, mixed> $post the add-to-cart request body
     */
    public function captureAdd(array $post)
    {
        try {
            if (!$this->settings->get('SHARE_SEARCH_DATA', true)) {
                return;
            }

            if (!isset($post['ns_search']) || (string) $post['ns_search'] !== '1') {
                return;
            }

            $productId = isset($post['product_id']) ? (int) $post['product_id'] : 0;
            if ($productId <= 0 || !$this->hasSession()) {
                return;
            }

            $marker = $this->marker();

            $marker[$productId] = array(
                'q' => isset($post['ns_q']) ? self::cleanQuery($post['ns_q']) : '',
                't' => time(),
            );

            if (count($marker) > self::MAX_TRACKED) {
                $marker = array_slice($marker, -self::MAX_TRACKED, null, true);
            }

            $this->session->data[self::SESSION_KEY] = $marker;
        } catch (\Exception $e) {
            // A checkout is not ours to break, and an attribution is worth nothing
            // next to one. See the class docblock for why this pair is on every
            // public method here as well as on both adapters' handlers.
        } catch (\Throwable $e) {
        }
    }

    /**
     * Resolve the marker into a durable row — inside the shopper's own request.
     *
     * THIS IS THE STEP THAT MAKES THE FEATURE WORK ON GATEWAY-DRIVEN SHOPS, and it is
     * the reason it runs here rather than at confirmation. See the class docblock.
     *
     * ⚠ EVERYTHING IS READ FROM THE DATABASE, never from the event's arguments. The
     * hooks that call this are public controller methods and therefore reachable
     * storefront urls; the order id is used ONLY as something to look up, so the worst
     * a forged call can do is re-assert what the shop's own tables already say.
     *
     * IT DOES NOT CLEAR THE MARKER, which is a real difference from the connectors
     * that consume it at this point and it is not an oversight. This method runs more
     * than once for one order on both majors: OpenCart 4's confirm controller calls
     * `editOrder` while the status is still 0, and OpenCart 3's calls `addOrder` again
     * on every render of the confirm page. A marker consumed on the first call would
     * make the second resolve to nothing and overwrite a correct row with an empty
     * one. The marker expires on its own clock instead.
     *
     * @param int $orderId
     */
    public function orderCreated($orderId)
    {
        try {
            $orderId = (int) $orderId;

            // NO ID, NO ROW. This is a real branch rather than defensive padding:
            // `(int) 0` hashes to one constant order reference, and a service that
            // de-duplicates on that reference would collapse every order in the shop
            // into a single one, forever. Writing nothing is recoverable; writing a
            // colliding id produces a number a merchant reads and believes.
            if ($orderId <= 0) {
                return;
            }

            if (!$this->settings->isConnected() || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
                return;
            }

            $reports = $this->reports();
            $marker = $this->marker();

            if (empty($marker)) {
                $reports->discardPending($orderId);

                return;
            }

            $order = $this->orderRow($orderId);
            if ($order === null) {
                $reports->discardPending($orderId);

                return;
            }

            $slice = $this->attributedSlice($orderId, $marker, (float) $order['currency_value']);

            if (empty($slice['ids'])) {
                $reports->discardPending($orderId);

                return;
            }

            $valueCents = $this->minorUnits($slice['value'], $order['currency_code']);

            // REFUSED RATHER THAN CLAMPED. The service rejects a value above this
            // outright, and trimming it to fit would replace a number nobody can send
            // with a wrong number the merchant would read as revenue. The refusal is
            // recorded where they can see it; the order itself is untouched.
            if ($valueCents > self::MAX_VALUE_CENTS) {
                $reports->discardPending($orderId);
                $this->note('order value above the reportable maximum; attribution skipped for this order');

                return;
            }

            $reports->queuePending($orderId, $valueCents, $order['currency_code'], $slice['ids'], $slice['q']);
        } catch (\Exception $e) {
            $this->note($e->getMessage());
        } catch (\Throwable $e) {
            $this->note($e->getMessage());
        }
    }

    /**
     * Promote a pending report once the order is really a sale.
     *
     * Runs at the order-history hook, which may be a server-to-server gateway callback
     * with no session — and needs none, because {@see orderCreated()} already wrote
     * the row. If no pending row exists this does nothing at all, which is also what
     * makes a forged call inert.
     *
     * ⚠ IT TAKES NO STATUS ARGUMENT, AND THAT IS THE POINT. The hook hands one over,
     * and this method deliberately cannot see it: the status is re-read from `oc_order`
     * for this order id, so a hand-crafted request to the handler's public url cannot
     * assert that an unpaid order was paid. A parameter that must not be trusted is
     * better removed than accepted and ignored — an argument that does not exist
     * cannot be quietly used by the next person to edit this file.
     *
     * WHAT COUNTS AS CONFIRMED is OpenCart's own definition: the union of the
     * merchant's `config_processing_status` and `config_complete_status` lists, which
     * is what the platform's own stock and fraud logic keys on. A shop whose gateway
     * sets some status outside both lists reports nothing, silently — there is no
     * screen anywhere that would say so, and it is named here because that is the
     * only warning it gets.
     *
     * `occurred_at` IS STAMPED HERE, ONCE, AND IN UTC — not taken from the order's own
     * date, and never recomputed afterwards. The service clamps a timestamp older than
     * eight days up to a moving cutoff, and that value is part of its de-duplication
     * key: a slow gateway plus the order date means every retry produces a different
     * clamped value and a fresh row of revenue. See {@see OrderReports}.
     *
     * @param int $orderId
     */
    public function orderConfirmed($orderId)
    {
        try {
            $orderId = (int) $orderId;
            if ($orderId <= 0) {
                return;
            }

            if (!$this->settings->get('SHARE_SEARCH_DATA', true)) {
                return;
            }

            $status = $this->orderStatusId($orderId);
            if ($status <= 0 || !in_array($status, $this->confirmedStatuses(), true)) {
                return;
            }

            $this->reports()->promote($orderId, gmdate('c'));
        } catch (\Exception $e) {
            $this->note($e->getMessage());
        } catch (\Throwable $e) {
            $this->note($e->getMessage());
        }
    }

    /**
     * The queue, built WITHOUT a client on purpose.
     *
     * Everything on this file's paths is a checkout path, so the object that can open
     * a socket is not handed one. That is the difference between "this code does not
     * send" and "this code cannot send", and only the second survives editing.
     *
     * @return OrderReports
     */
    private function reports()
    {
        return new OrderReports($this->db);
    }

    /**
     * The attributed lines of an order, and what they came to.
     *
     * ⚠ READ IN `order_product_id` ORDER, AND THAT ORDERING IS LOAD-BEARING rather
     * than tidy. The reported term is the one attached to the FIRST attributed line,
     * so an unordered read would make the same order report a different term on a
     * retry or after a database restore. It is the one place a per-product marker is
     * weaker than a session-wide term, and fixing the iteration order is what closes
     * the gap.
     *
     * THE VALUE IS TAX-INCLUSIVE — what the shopper actually paid, which is what a
     * figure labelled revenue has to mean. `oc_order_product.total` is price × quantity
     * EXCLUDING tax and `tax` is PER UNIT, both in the shop's DEFAULT currency;
     * `oc_order.currency_value` is the rate the order was taken at. So the line is
     * `(total + tax × quantity) × rate`, and the result is in the order's own currency.
     *
     * @param int                                 $orderId
     * @param array<int, array{q: string, t: int}> $marker
     * @param float                               $rate
     *
     * @return array{ids: array<int, string>, value: float, q: string}
     */
    private function attributedSlice($orderId, array $marker, $rate)
    {
        if ($rate <= 0) {
            // A missing or nonsensical rate means the order is in the shop's default
            // currency, which is what a rate of 1 says. Multiplying by zero would
            // report every such order as worth nothing.
            $rate = 1.0;
        }

        $result = $this->db->query(
            "SELECT `product_id`, `quantity`, `total`, `tax` FROM `" . DB_PREFIX . "order_product` "
            . "WHERE `order_id` = " . (int) $orderId . " ORDER BY `order_product_id` ASC"
        );

        $ids = array();
        $value = 0.0;
        $q = '';

        foreach ($result->rows as $row) {
            $productId = (int) $row['product_id'];

            if (!isset($marker[$productId])) {
                continue;
            }

            $line = ((float) $row['total'] + (float) $row['tax'] * (int) $row['quantity']) * $rate;
            if ($line > 0) {
                $value += $line;
            }

            // ONE ID PER PRODUCT, however many lines it occupies. The same product
            // bought twice with different options is two rows here and one product
            // to anyone reading the report; both lines count toward the value, which
            // is what the shopper paid.
            if (!in_array((string) $productId, $ids, true)) {
                $ids[] = (string) $productId;
            }

            if ($q === '' && $marker[$productId]['q'] !== '') {
                $q = $marker[$productId]['q'];
            }
        }

        // The marker is already bounded well below this, so the slice is belt and
        // braces against a future change to that bound rather than a live limit —
        // but the wire refuses a longer list outright, and losing every report on a
        // large order would be a silent failure rather than a truncated one.
        if (count($ids) > self::MAX_ITEM_IDS) {
            $ids = array_slice($ids, 0, self::MAX_ITEM_IDS);
        }

        return array('ids' => $ids, 'value' => $value, 'q' => $q);
    }

    /**
     * A decimal amount as integer minor units of its own currency.
     *
     * ⚠ NEVER `× 100`. That line is right for dollars, euros and pounds and wrong for
     * about fifty currencies: a shop priced in yen would report a hundred times its
     * real revenue and one in Kuwaiti dinar a tenth of it, in both cases with no
     * error anywhere and a dashboard that looks plausible. The vendored kit is asked
     * for the currency's exponent for exactly this reason, and the same call already
     * prices this module's catalogue — a second, hand-rolled answer here would be a
     * copy that disagrees silently, on the currencies nobody tests.
     *
     * ROUNDED ONCE, AT THE END, rather than per line: OpenCart computes an order's own
     * totals from unrounded line values too, so rounding each line first would produce
     * a figure that disagrees with the shop's own arithmetic by a minor unit or two on
     * long orders.
     *
     * @param float  $amount
     * @param string $currency
     *
     * @return int
     */
    private function minorUnits($amount, $currency)
    {
        $amount = (float) $amount;
        if ($amount < 0) {
            $amount = 0.0;
        }

        $places = Money::ofMinor(1, $currency)->exponent();

        return Money::fromDecimalString(number_format($amount, $places, '.', ''), $currency)->minor();
    }

    /**
     * The order's currency, as it was taken.
     *
     * The code is validated here rather than left to the kit's exception, because a
     * shop with a broken currency row should skip one attribution rather than write a
     * `LAST_ERROR` on every order it takes.
     *
     * @param int $orderId
     *
     * @return array{currency_code: string, currency_value: float}|null
     */
    private function orderRow($orderId)
    {
        $result = $this->db->query(
            "SELECT `currency_code`, `currency_value` FROM `" . DB_PREFIX . "order` "
            . "WHERE `order_id` = " . (int) $orderId . " LIMIT 1"
        );

        if (!isset($result->row['currency_code'])) {
            return null;
        }

        $code = strtoupper(trim((string) $result->row['currency_code']));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return null;
        }

        return array(
            'currency_code' => $code,
            'currency_value' => (float) $result->row['currency_value'],
        );
    }

    /**
     * @param int $orderId
     *
     * @return int
     */
    private function orderStatusId($orderId)
    {
        $result = $this->db->query(
            "SELECT `order_status_id` FROM `" . DB_PREFIX . "order` "
            . "WHERE `order_id` = " . (int) $orderId . " LIMIT 1"
        );

        return isset($result->row['order_status_id']) ? (int) $result->row['order_status_id'] : 0;
    }

    /**
     * The statuses this shop treats as a real sale.
     *
     * READ STRAIGHT FROM `setting`, WHICH WORKS ON BOTH MAJORS. Both spell these keys
     * identically and both store the list as a JSON array in a `serialized = 1` row,
     * so a direct read needs no framework call and no per-major branch. The
     * `unserialize` fallback is for shops upgraded from OpenCart 2, which wrote PHP
     * serialised values into the same column and left them there; `allowed_classes`
     * is off, so it can only ever produce scalars and arrays.
     *
     * ⚠ NOT FILTERED BY `store_id`. A multi-store shop keeps a row per storefront, and
     * this module holds one set of credentials for the whole catalogue — so a status
     * that means "paid" on any of a merchant's storefronts is taken to mean paid. The
     * alternative is reading store 0's list and silently reporting nothing for every
     * order taken on a second storefront.
     *
     * @return array<int, int>
     */
    private function confirmedStatuses()
    {
        $result = $this->db->query(
            "SELECT `value` FROM `" . DB_PREFIX . "setting` "
            . "WHERE `key` IN ('config_processing_status', 'config_complete_status')"
        );

        $statuses = array();

        foreach ($result->rows as $row) {
            foreach (self::decodeList($row['value']) as $status) {
                $status = (int) $status;
                if ($status > 0 && !in_array($status, $statuses, true)) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }

    /**
     * @param mixed $value
     *
     * @return array<int, mixed>
     */
    private static function decodeList($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return array();
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            $decoded = @unserialize($value, array('allowed_classes' => false));
        }

        if (is_array($decoded)) {
            return array_values($decoded);
        }

        return is_numeric($value) ? array($value) : array();
    }

    /**
     * The marker, with expired entries already gone.
     *
     * @return array<int, array{q: string, t: int}> keyed by product id
     */
    private function marker()
    {
        if (!$this->hasSession() || !isset($this->session->data[self::SESSION_KEY])) {
            return array();
        }

        $stored = $this->session->data[self::SESSION_KEY];
        if (!is_array($stored)) {
            return array();
        }

        $cutoff = time() - self::WINDOW_SECONDS;
        $marker = array();

        foreach ($stored as $productId => $entry) {
            $productId = (int) $productId;
            if ($productId <= 0 || !is_array($entry)) {
                continue;
            }

            $t = isset($entry['t']) ? (int) $entry['t'] : 0;
            if ($t < $cutoff) {
                continue;
            }

            $marker[$productId] = array(
                'q' => isset($entry['q']) ? (string) $entry['q'] : '',
                't' => $t,
            );
        }

        return $marker;
    }

    /**
     * @return bool
     */
    private function hasSession()
    {
        return is_object($this->session) && isset($this->session->data) && is_array($this->session->data);
    }

    /**
     * A search term fit to store and eventually send.
     *
     * IT ARRIVES FROM THE BROWSER, so it is treated as text and nothing else: control
     * characters out, whitespace collapsed, length capped. The cap is on CHARACTERS
     * rather than bytes — a byte cap can split a multi-byte character in half and
     * leave an invalid sequence in the middle of a JSON body, which the service would
     * refuse for the whole report rather than for the term.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function cleanQuery($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
        if ($q === null) {
            // A term that is not valid UTF-8 at all. There is nothing to salvage and
            // nothing worth risking on the wire for it.
            return '';
        }

        $q = trim(preg_replace('/\s+/u', ' ', $q));

        if (function_exists('mb_substr')) {
            return mb_substr($q, 0, 128, 'UTF-8');
        }

        // No mbstring. Cut on a character boundary rather than a byte one.
        return preg_replace('/^(.{0,128}).*$/us', '$1', $q);
    }

    /**
     * Record a failure where the merchant will see it.
     *
     * ⚠ THIS IS THE ONLY THING ANY CATCH IN THIS FILE DOES, and it writes to a
     * settings row — never to the response, never to output. A checkout page is not a
     * place to report that an analytics feature had a bad moment.
     *
     * @param string $message
     */
    private function note($message)
    {
        try {
            $this->settings->update(array(
                'LAST_ERROR' => substr('order attribution: ' . (string) $message, 0, 500),
            ));
        } catch (\Exception $e) {
            // Recording the failure failed. There is genuinely nowhere left to go,
            // and a checkout is still in progress.
        } catch (\Throwable $e) {
        }
    }
}
