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
 *  - **It checks for something to do before scheduling anything**, so the common
 *    case — an idle catalogue with a fresh status stamp — costs two already-cached
 *    settings reads and one COUNT. "Something to do" is sync work OR a due
 *    heartbeat, and the two are deliberately separate: gating the heartbeat on
 *    there being sync work would starve exactly the shops that need it.
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

    /**
     * Order reports per fallback tick.
     *
     * A COUNT RATHER THAN A TIME BUDGET, because each report is one small signed POST
     * with a short timeout and the queue is measured in orders rather than in
     * products. Three is chosen against the same constraint as {@see BUDGET_SECONDS}
     * — this rides a shopper's page and must not be the reason a host's time limit is
     * reached — and against the service's own per-shop rate limit, which a shop
     * ticking every 90 seconds cannot approach at this size.
     */
    const REPORT_BUDGET = 3;

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
            || (bool) $settings->get('FULLSYNC_ACTIVE')
            // ⚠ AN ORDER REPORT IS WORK EVEN ON A SHOP WHOSE CATALOGUE NEVER CHANGES,
            // and leaving this term out is the quiet way to ship attribution that does
            // nothing. A settled catalogue with an empty outbox is the steady state of
            // every healthy shop — and the shops with no cron, which is the only reason
            // this class exists, are exactly the ones with nowhere else to send from.
            // Without this the reports queue up correctly, forever.
            || $this->reportsWaiting();

        // ⚠ THE HEARTBEAT IS A SECOND, INDEPENDENT REASON TO RUN, and collapsing the
        // two into one condition is the bug this arrangement exists to prevent. A
        // shop with an empty outbox and no active walk is not an idle shop worth
        // skipping — it is the steady state of every healthy catalogue, and exactly
        // the shop whose scoped search key quietly expires. Gating the status poll
        // behind `$hasWork` would mean the shops that most need it are the only ones
        // that never get it.
        //
        // `isDue()` is one already-cached settings read, so asking costs nothing on
        // the overwhelming majority of page views where the answer is no.
        $statusDue = $this->runner->resyncCheck()->isDue();

        if (!$hasWork && !$statusDue) {
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
            // The heartbeat goes FIRST, and only partly because it is cheap. If the
            // service is asking this shop to re-send, the poll is what starts the
            // walk — so running it before the two lines below means the same tick
            // that picks the request up also makes progress on it, instead of
            // seeding a walk and then waiting another interval to advance it.
            //
            // It has its own clock and its own try/catch, so a service that is slow
            // or unreachable delays nothing here beyond its own timeout, and cannot
            // stop the sync below from running.
            $runner->resyncCheck()->maybeRun();

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

        // ── Order reports ────────────────────────────────────────────────────────
        //
        // ⚠ ITS OWN TRY, OUTSIDE THE ONE ABOVE, AND THAT IS THE POINT OF WRITING IT
        // TWICE. A catalogue drain that throws — one unreadable product, one host that
        // times out mid-batch — would otherwise take every order report on the shop
        // with it, on every tick, for as long as the catalogue fault lasted. The two
        // queues have nothing to do with each other and a fault in one must not
        // silence the other. Revenue attribution is also the harder failure to notice:
        // an unsynced product is visible in the shop, an unsent report is visible
        // nowhere at all.
        //
        // THREE PER TICK, against the cron endpoint's ten. This is riding a shopper's
        // page load and has to finish inside whatever time limit the host has left;
        // the cron request has one to itself. A backlog drains over several ticks
        // rather than in one, which is the correct trade on the path that is not ours.
        //
        // `flush()` DOES NOT THROW BY ITS OWN CONTRACT — this pair is here because a
        // contract is a promise about today's code and this runs on a merchant's live
        // storefront.
        try {
            $runner->orderReports()->flush(self::REPORT_BUDGET);
        } catch (\Exception $e) {
            self::note($runner, $e->getMessage());
        } catch (\Throwable $e) {
            self::note($runner, $e->getMessage());
        }
    }

    /**
     * Are there order reports waiting to go out?
     *
     * ⚠ SEALED, AND NOT AS A FORMALITY. This is asked BEFORE the shutdown handler is
     * registered, so it runs while the shopper's page is still being assembled — the
     * one question in {@see maybeRun()} that touches a table which may not exist yet.
     * A shop that upgraded the module without reinstalling it has the new code and the
     * old schema, and an uncaught "table doesn't exist" there is a storefront error on
     * a page the merchant is selling from. False is the honest answer in that state:
     * nothing can be waiting in a table that is not there.
     *
     * @return bool
     */
    private function reportsWaiting()
    {
        try {
            return $this->runner->orderReports()->readyCount() > 0;
        } catch (\Exception $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
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
