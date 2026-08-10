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

use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * The durable queue of search-attributed orders, and the only place this feature
 * ever opens a socket.
 *
 * IT IS A SECOND TABLE RATHER THAN A SECOND KIND OF {@see Outbox} ROW, and the
 * reason is the lifecycle rather than tidiness. An outbox row describes an object
 * that can be re-serialised from the catalogue at any later moment — lose it and
 * the next full walk repairs it. An order report cannot be reconstructed from
 * anything: the link between a search term and a line only exists in the shopper's
 * session, for the length of one visit. Lose the row and the revenue is gone with
 * no repair path, so it gets a table with its own states, its own retry counter and
 * its own sweep.
 *
 * TWO STATES, AND THE TRANSITION BETWEEN THEM IS THE WHOLE DESIGN:
 *
 *  - `pending` — written INSIDE the shopper's own request, at order creation, while
 *    the session that carries the marker still exists. Not sendable: OpenCart writes
 *    an order row with `order_status_id` 0 and it is not a sale yet.
 *  - `ready`  — promoted when a payment extension records a confirmed status. That
 *    call is frequently a server-to-server gateway callback with NO shopper session
 *    at all, which is exactly why the row has to already exist by then. Promotion
 *    reads nothing from the session; it only flips a state and stamps a time.
 *
 * `occurred_at` IS A VARCHAR HOLDING THE EXACT STRING THAT WILL BE SENT, and that
 * is deliberate to the point of being the reason the column is not a DATETIME. The
 * service's idempotency key is (store, order reference, occurred_at) — so a value
 * RE-DERIVED at send time, from a DATETIME or from the order's own date, produces a
 * different string on a retry that crosses a daylight-saving boundary or a timezone
 * change, misses the collision, and lands as a SECOND conversion row for one order.
 * Doubled revenue is the worst possible failure here, because it is a number a
 * merchant reads and believes. Store the bytes; re-send the bytes.
 *
 * ENGINE=InnoDB EXPLICITLY, for the reason already written on {@see Outbox::schema()}
 * and with more force: OpenCart 3 still creates MyISAM tables, whose table-level
 * write lock would make every concurrent checkout on a busy shop queue behind every
 * other one. This table is written on the checkout path. It is the one place where
 * getting the engine wrong breaks a merchant's shop as a performance cliff rather
 * than as an error anyone would see.
 *
 * THE SEVEN-DAY SWEEP IS NOT HOUSEKEEPING. The service clamps a report whose
 * `occurred_at` is older than eight days up to a MOVING "eight days ago" — and since
 * that value is in the idempotency key, a report sent on day nine and again on day
 * ten is two rows for one order. Expiring at seven days guarantees that everything
 * this module ever sends is inside the un-clamped window, or deleted. The sweep also
 * does the un-glamorous half of the job: OpenCart 3's confirm controller calls
 * `addOrder` unconditionally on every render of the confirm page, so a shopper who
 * reloads it five times leaves five order rows at status 0 — and five `pending`
 * reports that will never be promoted. Without the sweep this table grows forever on
 * every OpenCart 3 shop.
 */
final class OrderReports
{
    /**
     * How long a report may live here, sent or not.
     *
     * SEVEN DAYS, NOT FOURTEEN. See the class docblock: eight is where the service
     * starts rewriting the timestamp that its own de-duplication depends on, and any
     * value at or past it turns a retry into double-counted revenue. Seven is the
     * only number that makes "this module cannot double-count" a property rather
     * than a hope. It costs the reports of a shop that has been offline for over a
     * week, which is the cheaper side of the trade by a wide margin.
     */
    const TTL_SECONDS = 604800;

    /**
     * Attempts before a report is abandoned.
     *
     * A ceiling exists because the flush stops at the first retryable failure — so a
     * single row that the service will never accept, for a reason the classification
     * in {@see Client::reportOrder()} could not foresee, would otherwise sit at the
     * head of the queue and block every later order behind it forever. Eight ticks
     * of the cron is a long time to keep trying and a short time to be blocked.
     */
    const MAX_ATTEMPTS = 8;

    /** @var object OpenCart's DB library — query()/escape(), same in both majors */
    private $db;

    /** @var Client|null the wire; absent when this object exists only to install or drop the table */
    private $client;

    /** @var Settings|null */
    private $settings;

    /**
     * THE WIRE AND THE SETTINGS ARE OPTIONAL because install and uninstall build this
     * object for {@see schema()} and {@see drop()} alone, in a request that has no
     * credentials resolved and no business opening a socket. {@see flush()} refuses
     * to send without them rather than assuming they are there.
     *
     * ⚠ NEITHER IS TYPE-HINTED, and that is a version floor rather than an oversight.
     * A hinted-and-defaulted `Client $client = null` is implicitly nullable, which
     * PHP 8.4 deprecates with a notice printed on every request that constructs one —
     * and two of this module's endpoints answer with JSON and nothing else, where a
     * notice in front of the payload corrupts it. The explicit `?Client` form that
     * replaces it does not exist before PHP 7.1. This module is installed by upload on
     * whatever PHP a merchant's host happens to run, so it takes neither side of that
     * and documents the types here, exactly as `$db` already does throughout `src/`.
     *
     * @param object        $db
     * @param Client|null   $client
     * @param Settings|null $settings
     */
    public function __construct($db, $client = null, $settings = null)
    {
        $this->db = $db;
        $this->client = $client;
        $this->settings = $settings;
    }

    /**
     * @return string the fully-prefixed table name
     */
    public static function table()
    {
        return DB_PREFIX . 'nitrosearch_order_report';
    }

    /**
     * The CREATE TABLE this module installs.
     *
     * `order_id` IS THE PRIMARY KEY rather than a surrogate with a unique index on
     * it, because it is the natural key: one order has one report. That is what makes
     * the write at order creation an upsert, which matters because OpenCart 4's
     * confirm controller calls `editOrder` on the same order while its status is
     * still 0 — the basket can change after the row is written, and the row must be
     * rewritten rather than duplicated.
     *
     * @return string
     */
    public static function schema()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `order_id` INT UNSIGNED NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'pending\',
            `value_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `currency` VARCHAR(3) NOT NULL DEFAULT \'\',
            `item_ids` TEXT NOT NULL,
            `q` VARCHAR(128) NOT NULL DEFAULT \'\',
            `occurred_at` VARCHAR(32) NOT NULL DEFAULT \'\',
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`order_id`),
            KEY `sweep` (`status`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
    }

    /** Remove the table — used by uninstall. */
    public function drop()
    {
        $this->db->query("DROP TABLE IF EXISTS `" . self::table() . "`");
    }

    /**
     * Write, or rewrite, the pending report for one order.
     *
     * ⚠ IT ONLY OVERWRITES A ROW THAT IS STILL `pending`, which is why the update
     * clause is a row of `IF()`s rather than plain `VALUES()`. Once a row has been
     * promoted it carries an `occurred_at` that has possibly already been sent, and
     * an extension that edits an order after payment — a status-driven discount, a
     * back-office correction — must not be able to reset it to unsendable or change
     * the value underneath a report already on the wire. A late edit is ignored;
     * nothing is lost that was not already recorded.
     *
     * @param int                $orderId
     * @param int                $valueCents
     * @param string             $currency   ISO 4217
     * @param array<int, string> $itemIds
     * @param string             $q
     */
    /** Set once a request has ensured the table, so a multi-line order pays for one DDL. */
    private static $schemaEnsured = false;

    /**
     * Create the table if a shop upgraded into this feature rather than installing it.
     *
     * ⚠ WITHOUT THIS, EVERY EXISTING SHOP GETS THE DEFECT THIS RELEASE FIXES. The
     * table is created by the adapters' `install()`, and OpenCart runs `install()`
     * when a module is INSTALLED — never when one is upgraded. There is no module
     * upgrade hook on either major. So a shop that already had the module would take
     * the new files, write to a table that was never created, and lose the
     * attribution — silently, because every checkout-path entry point is sealed in
     * `catch (\Throwable)` and must be. A fresh install works perfectly, which is
     * exactly why this would have passed an install-from-archive check.
     *
     * `CREATE TABLE IF NOT EXISTS` rather than a `SHOW TABLES` probe: one statement
     * instead of two, and no window between the check and the create. Guarded to once
     * per request because this sits on the checkout path — a shopper's order must not
     * pay for a DDL round trip on every line it writes.
     *
     * Found on 2026-08-10 while answering "does uninstall preserve settings?", before
     * the release was tagged. The sibling PrestaShop module carries the same guard for
     * the same reason.
     */
    private function ensureSchema()
    {
        if (self::$schemaEnsured) {
            return;
        }

        self::$schemaEnsured = true;

        try {
            $this->db->query(self::schema());
        } catch (\Exception $e) {
            // A shop whose database user cannot CREATE is not a shop we break at
            // checkout. The write below fails, the seal catches it, and the merchant
            // keeps their order.
            $this->note('could not ensure the report table: ' . $e->getMessage());
        }
    }

    public function queuePending($orderId, $valueCents, $currency, array $itemIds, $q)
    {
        $this->ensureSchema();

        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return;
        }

        $ids = array();
        foreach ($itemIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = (string) $id;
            }
        }

        $set = "`order_id` = " . $orderId . ", "
            . "`status` = 'pending', "
            . "`value_cents` = " . max(0, (int) $valueCents) . ", "
            . "`currency` = '" . $this->db->escape(strtoupper((string) $currency)) . "', "
            . "`item_ids` = '" . $this->db->escape(implode(',', $ids)) . "', "
            . "`q` = '" . $this->db->escape((string) $q) . "', "
            . "`occurred_at` = '', "
            . "`attempts` = 0, "
            . "`created_at` = NOW()";

        $this->db->query(
            "INSERT INTO `" . self::table() . "` SET " . $set . " "
            . "ON DUPLICATE KEY UPDATE "
            . "`value_cents` = IF(`status` = 'pending', VALUES(`value_cents`), `value_cents`), "
            . "`currency` = IF(`status` = 'pending', VALUES(`currency`), `currency`), "
            . "`item_ids` = IF(`status` = 'pending', VALUES(`item_ids`), `item_ids`), "
            . "`q` = IF(`status` = 'pending', VALUES(`q`), `q`), "
            . "`created_at` = IF(`status` = 'pending', VALUES(`created_at`), `created_at`)"
        );
    }

    /**
     * Drop a report that no longer has anything to say.
     *
     * ONLY WHILE IT IS STILL `pending`. An order whose search-attributed lines were
     * all removed before confirmation has nothing to report; one that was already
     * promoted has a report in flight, and deleting that would lose revenue the
     * merchant earned.
     *
     * @param int $orderId
     */
    public function discardPending($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return;
        }

        $this->db->query(
            "DELETE FROM `" . self::table() . "` WHERE `order_id` = " . $orderId . " AND `status` = 'pending'"
        );
    }

    /**
     * Make a pending report sendable, exactly once.
     *
     * ⚠ `WHERE status = 'pending'` IS NOT AN OPTIMISATION AND REMOVING IT DOUBLES A
     * MERCHANT'S REVENUE. The hook that calls this is the order-history hook, and it
     * fires again on every later transition — an admin marking an order shipped, a
     * refund, a completion. Without the predicate each of those re-stamps
     * `occurred_at`, and because that string is part of the service's idempotency key
     * the same order lands as a fresh conversion row every time somebody touches it
     * in the back office. There is no error, no warning, and no surface anywhere that
     * would show it; the only signal is a revenue figure that is quietly too big.
     *
     * The timestamp is passed IN rather than taken here so that this class has
     * exactly one opinion about time — the caller stamps it once, and every later
     * read, including every retry, uses the stored string.
     *
     * @param int    $orderId
     * @param string $occurredAt ISO-8601, UTC — the literal bytes that will be sent
     *
     * @return bool whether this call is the one that promoted it
     */
    public function promote($orderId, $occurredAt)
    {
        $orderId = (int) $orderId;
        $occurredAt = (string) $occurredAt;

        if ($orderId <= 0 || $occurredAt === '') {
            return false;
        }

        $this->db->query(
            "UPDATE `" . self::table() . "` SET `status` = 'ready', "
            . "`occurred_at` = '" . $this->db->escape($occurredAt) . "' "
            . "WHERE `order_id` = " . $orderId . " AND `status` = 'pending'"
        );

        return $this->db->countAffected() > 0;
    }

    /**
     * How many reports are waiting to be sent.
     *
     * Asked by the page-load fallback before it schedules anything. Without it a shop
     * with an empty catalogue queue would decide it had nothing to do and never send
     * a report at all — the whole feature working perfectly and reporting nothing.
     *
     * @return int
     */
    public function readyCount()
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS `n` FROM `" . self::table() . "` WHERE `status` = 'ready'"
        );

        return isset($result->row['n']) ? (int) $result->row['n'] : 0;
    }

    /**
     * Send what is ready. THE ONLY NETWORK CALL THIS FEATURE EVER MAKES.
     *
     * Reached from two places, both unattended and neither on a checkout: the
     * merchant's cron endpoint, and the shutdown-deferred page tick. Nothing on the
     * shopper's path — capture, resolution and promotion — can reach it.
     *
     * IT STOPS AT THE FIRST RETRYABLE FAILURE rather than working through the queue,
     * which is the same head-of-line back-off {@see Drain} uses and for the same
     * reason: a service that refused one report is usually refusing all of them, and
     * pressing on turns one shop's bad minute into a retry storm. The row keeps its
     * place and the next tick tries again.
     *
     * IT NEVER THROWS. One of its two call sites runs inside a shopper's request.
     *
     * @param int $limit
     *
     * @return array{sent: int, stopped: string, ready: int}
     */
    public function flush($limit = 10)
    {
        $result = array('sent' => 0, 'stopped' => 'empty', 'ready' => 0);

        try {
            // The sweep runs first and runs unconditionally — a shop that disconnected,
            // or a merchant who switched sharing off, still needs its orphaned rows
            // cleared rather than kept forever against a send that will never happen.
            $this->expireStale();

            if ($this->client === null || $this->settings === null) {
                $result['stopped'] = 'not_wired';

                return $result;
            }

            if (!$this->settings->isConnected()) {
                $result['stopped'] = 'not_connected';

                return $result;
            }

            // GATED ON THE MERCHANT'S ANALYTICS CHOICE, not only on being connected.
            // Order values are usage data; a merchant who declined to share search
            // usage must not have their revenue reported either, or the switch means
            // less than the screen says it does.
            if (!$this->settings->get('SHARE_SEARCH_DATA', true)) {
                $result['stopped'] = 'opted_out';

                return $result;
            }

            $rows = $this->readyRows(max(1, (int) $limit));

            foreach ($rows as $row) {
                $outcome = $this->sendOne($row);

                if ($outcome === 'sent') {
                    $result['sent']++;
                    $result['stopped'] = 'done';
                    continue;
                }

                $result['stopped'] = 'error';
                break;
            }

            $result['ready'] = $this->readyCount();
        } catch (\Exception $e) {
            $this->note($e->getMessage());
            $result['stopped'] = 'error';
        } catch (\Throwable $e) {
            $this->note($e->getMessage());
            $result['stopped'] = 'error';
        }

        return $result;
    }

    /**
     * Send one row and decide what becomes of it.
     *
     * ⚠ `occurred_at` IS READ FROM THE ROW AND NEVER RECOMPUTED — that is the whole
     * point of storing it as a string, and the single line most worth guarding in
     * this file. See the class docblock.
     *
     * @param array<string, mixed> $row
     *
     * @return string 'sent' or 'stop'
     */
    private function sendOne(array $row)
    {
        $orderId = (int) $row['order_id'];

        $outcome = $this->client->reportOrder(array(
            'order_id' => $orderId,
            'value_cents' => (int) $row['value_cents'],
            'currency' => (string) $row['currency'],
            'occurred_at' => (string) $row['occurred_at'],
            'item_ids' => array_values(array_filter(explode(',', (string) $row['item_ids']), 'strlen')),
            'q' => (string) $row['q'],
        ));

        if (!empty($outcome['done'])) {
            $this->db->query(
                "DELETE FROM `" . self::table() . "` WHERE `order_id` = " . $orderId
            );

            return 'sent';
        }

        $attempts = (int) $row['attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Abandoned rather than retried forever. It is one order's attribution
            // against every later order in the queue, and this is already the eighth
            // unattended attempt.
            $this->db->query(
                "DELETE FROM `" . self::table() . "` WHERE `order_id` = " . $orderId
            );
            $this->note('order report abandoned after ' . $attempts . ' attempts: ' . (string) $outcome['error']);

            return 'stop';
        }

        $this->db->query(
            "UPDATE `" . self::table() . "` SET `attempts` = " . $attempts . " "
            . "WHERE `order_id` = " . $orderId
        );
        $this->note('order report deferred (HTTP ' . (int) $outcome['status'] . '): ' . (string) $outcome['error']);

        return 'stop';
    }

    /**
     * The oldest ready reports first.
     *
     * ⚠ ORDERED BY `created_at`, WHICH IS WHEN THE ORDER WAS PLACED, not by
     * `occurred_at`, which is when it was confirmed. Those differ by however long the
     * shopper's gateway took, and ordering by the confirmation would let a slow bank
     * transfer overtake a card payment placed hours earlier. Oldest order first is
     * also the order in which reports approach the seven-day cliff.
     *
     * NOTHING IS CLAIMED OR LOCKED, UNLIKE {@see Outbox::claim()}, and the difference
     * is worth naming because it looks like an omission. Two flushes overlapping — a
     * cron tick that overruns into the next one, a cron and a page tick together —
     * can send the same report twice, and that is harmless BY CONSTRUCTION rather
     * than by luck: both attempts carry byte-identical `order_ref` and `occurred_at`,
     * which is the service's idempotency key, so the second one collides with the
     * first and records nothing. That property is the reason `occurred_at` is stored
     * rather than derived; a `sending` state here would be a second mechanism
     * defending something already safe.
     *
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    private function readyRows($limit)
    {
        $result = $this->db->query(
            "SELECT `order_id`, `value_cents`, `currency`, `item_ids`, `q`, `occurred_at`, `attempts` "
            . "FROM `" . self::table() . "` WHERE `status` = 'ready' "
            . "ORDER BY `created_at` ASC LIMIT " . (int) $limit
        );

        return is_array($result->rows) ? $result->rows : array();
    }

    /**
     * Delete everything too old to be worth keeping.
     *
     * TWO DELETES BECAUSE THE TWO STATES AGE ON DIFFERENT CLOCKS, and using one for
     * both is wrong in both directions. A `pending` row has an EMPTY `occurred_at` —
     * it is stamped at promotion — so sweeping it on that column would either match
     * everything or nothing depending on the comparison, and the OpenCart 3 orphans
     * this exists to clear are exactly the rows that never get stamped. A `ready` row
     * has to be judged on the timestamp that will actually be sent, because that is
     * the value the service clamps.
     *
     * @return int rows removed
     */
    private function expireStale()
    {
        $removed = 0;

        // Orphans: an order created but never confirmed. On OpenCart 3 this is not an
        // edge case — its confirm controller calls `addOrder` again on every render of
        // the confirm page, so one indecisive shopper leaves several.
        $this->db->query(
            "DELETE FROM `" . self::table() . "` WHERE `status` = 'pending' "
            . "AND `created_at` < DATE_SUB(NOW(), INTERVAL " . (int) (self::TTL_SECONDS / 86400) . " DAY)"
        );
        $removed += (int) $this->db->countAffected();

        // Reports whose timestamp is about to fall outside the window the service
        // accepts verbatim. Sending one is worse than dropping it: it would be
        // rewritten to a moving cutoff and counted again on every later attempt.
        //
        // A STRING COMPARISON, WHICH IS SOUND HERE AND WOULD NOT BE IN GENERAL: every
        // value in this column was written by the same `gmdate('c')` call, so all of
        // them carry the same `+00:00` offset and the same fixed-width layout, and
        // lexical order is chronological order. A column that mixed offsets could not
        // be compared this way at all.
        $this->db->query(
            "DELETE FROM `" . self::table() . "` WHERE `status` = 'ready' AND `occurred_at` <> '' "
            . "AND `occurred_at` < '" . $this->db->escape(gmdate('c', time() - self::TTL_SECONDS)) . "'"
        );
        $removed += (int) $this->db->countAffected();

        return $removed;
    }

    /**
     * Record the most recent failure where the merchant will see it.
     *
     * ONE SLOT, SHARED WITH THE CATALOGUE DRAIN, and prefixed so the two are
     * distinguishable. A growing log in a settings row is a table nothing prunes.
     *
     * @param string $message
     */
    private function note($message)
    {
        if ($this->settings === null) {
            return;
        }

        try {
            $this->settings->update(array(
                'LAST_ERROR' => substr('order attribution: ' . (string) $message, 0, 500),
            ));
        } catch (\Exception $e) {
            // Recording the failure failed. There is genuinely nowhere left to go.
        } catch (\Throwable $e) {
        }
    }
}
