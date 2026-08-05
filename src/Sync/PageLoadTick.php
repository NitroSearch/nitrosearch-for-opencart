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

/**
 * The no-cron fallback: a storefront page view, occasionally, moves the sync on.
 *
 * NEITHER MAJOR HAS A WORKER, so the module's real engine is a merchant's cron
 * hitting the token-gated drain url. Three files in this module already described
 * a page-load fallback for shops that never set one up — and until this class
 * existed there was none, so those shops queued changes forever and nothing ever
 * sent them. That is the worst possible failure mode: silent, total, and invisible
 * from the Configure screen, which would report a healthy queue growing.
 *
 * IT HANGS ON THE STOREFRONT WIDGET HOOK, which is the only thing this module runs
 * on every page. That is deliberate reuse rather than a new hook: an event row is a
 * cost a merchant pays on every request whether it does anything or not.
 *
 * FOUR PROPERTIES, AND EACH ONE IS WHAT KEEPS THIS OFF A SHOPPER'S CRITICAL PATH:
 *
 *  - **It claims the interval BEFORE doing any work.** Several page loads land in
 *    the same second on any real shop; without this they would all decide it was
 *    their turn and stampede the service with the same batch.
 *  - **It checks for work before scheduling any**, so the common case — an idle
 *    catalogue — costs one already-cached settings read and one COUNT.
 *  - **It runs after the response**, behind `fastcgi_finish_request` where the
 *    host has it, so the shopper's page is already on its way.
 *  - **It cannot throw into a shopper's request.** A sync fault is ours; a 500 on
 *    a product page is the merchant's, and they are not the same thing.
 *
 * IT SHARES `DRAIN_RAN_AT` WITH THE CRON ENDPOINT, on purpose. A shop with working
 * cron never reaches the body of this method, because the cron keeps the stamp
 * fresh — so the fallback costs a properly configured shop nothing at all.
 */
final class PageLoadTick
{
    /**
     * Seconds between fallback ticks.
     *
     * A COMPROMISE, AND WORTH NAMING AS ONE. Short enough that a shop with no cron
     * still feels roughly live; long enough that a busy storefront is not doing our
     * work on a meaningful fraction of its page views. A shop that wants faster
     * sync sets up cron, which is what the Configure screen tells them.
     */
    const INTERVAL = 90;

    /**
     * Items per fallback tick.
     *
     * SMALLER THAN THE CRON'S BUDGET, deliberately. The cron endpoint has a request
     * to itself and can spend twenty seconds; this is riding a shopper's page and
     * must be able to finish inside whatever the host's remaining time limit is.
     */
    const BUDGET_SECONDS = 5;

    /** @var Runner */
    private $runner;

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * Schedule a tick if one is due and there is anything to do.
     *
     * Returns whether work was scheduled, which is for tests and for the caller's
     * benefit; no caller is expected to act on it.
     *
     * @return bool
     */
    public function maybeRun()
    {
        $settings = $this->runner->settings();

        if (!$settings->isConnected()) {
            return false;
        }

        $lastRan = (int) $settings->get('DRAIN_RAN_AT', 0);
        if ($lastRan > 0 && (time() - $lastRan) < self::INTERVAL) {
            return false;
        }

        // Claim the interval BEFORE looking for work, let alone doing any. Two page
        // loads a millisecond apart both read the same stale stamp otherwise.
        $settings->update(array('DRAIN_RAN_AT' => time()));

        // A stalled full walk is work even when the outbox is momentarily empty:
        // the walk is what refills it. Checking only the queue would stop a
        // catalogue mid-import and never restart it.
        $hasWork = $this->runner->outbox()->pendingCount() > 0
            || (bool) $settings->get('FULLSYNC_ACTIVE');

        if (!$hasWork) {
            return false;
        }

        $runner = $this->runner;

        register_shutdown_function(function () use ($runner) {
            self::runDeferred($runner);
        });

        return true;
    }

    /**
     * One tick, after the shopper's page has been handed over.
     *
     * `fastcgi_finish_request` closes the connection first where it exists, so the
     * browser is not held open by our sync at all. Where it does not — mod_php, which
     * is what a lot of OpenCart shops run — the work still happens after the page
     * content is complete, which is the best available and still invisible.
     */
    private static function runDeferred(Runner $runner)
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            // Same order as the cron endpoint: keep the walk moving first, because
            // enumeration is what feeds the queue the drain then empties. Reversing
            // them makes one tick drain an empty queue and stop.
            $runner->fullSync()->resumeIfStalled();
            $runner->drain()->run(self::BUDGET_SECONDS);
        } catch (\Exception $e) {
            self::note($runner, $e->getMessage());
        } catch (\Throwable $e) {
            // A fatal here lands in a shopper's request. There is nothing useful to
            // do with it but record it where the merchant will see it.
            self::note($runner, $e->getMessage());
        }
    }

    /**
     * @param string $message
     */
    private static function note(Runner $runner, $message)
    {
        try {
            $runner->settings()->update(array(
                'LAST_ERROR' => substr('page-load tick: ' . (string) $message, 0, 500),
            ));
        } catch (\Exception $e) {
            // Recording the failure failed. There is genuinely nowhere left to go.
        } catch (\Throwable $e) {
        }
    }
}
