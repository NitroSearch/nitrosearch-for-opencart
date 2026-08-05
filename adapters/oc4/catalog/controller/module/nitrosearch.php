<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace Opencart\Catalog\Controller\Extension\Nitrosearch\Module;

require_once DIR_EXTENSION . 'nitrosearch/system/library/nitrosearch/autoload.php';

use NitroSearch\Sync\Runner;

/**
 * The storefront event handler — OpenCart 4 build.
 *
 * WHY THIS IS A CLASS AND OPENCART 3'S IS A METHOD, again. This major splits an
 * action route on its LAST DOT: `extension/nitrosearch/module/nitrosearch.onStorefront`
 * resolves to `…\Module\Nitrosearch::onStorefront()`, so the handler needs a
 * `Nitrosearch` controller sitting beside the `Nitrosearch/` directory that holds
 * `Verify` and `Cron`. OpenCart 3 pops path segments from the right instead, which
 * lands every one of its storefront entry points on the same single file.
 *
 * A file and a directory of the same name is not a conflict on either the
 * filesystem or in the namespace: `Module\Nitrosearch` is this class,
 * `Module\Nitrosearch\Verify` is the one in the directory.
 *
 * NOTHING HERE IS SHARED WITH THE VERIFY OR CRON CONTROLLERS, deliberately: those
 * emit JSON and `exit`, this one modifies a page and must return. Making them a
 * common base class would put an `exit` one inheritance step away from a shopper's
 * storefront.
 */
class Nitrosearch extends \Opencart\System\Engine\Controller
{
    /**
     * Put the storefront widget into the page, and keep the sync moving.
     *
     *   trigger  catalog/view/common/header/after
     *   action   extension/nitrosearch/module/nitrosearch.onStorefront
     *
     * THE OUTPUT IS MODIFIED IN PLACE AND NOTHING IS RETURNED. This major's event
     * dispatcher discards every handler's return value — `Event::trigger()` ends in
     * `return ''` — so a returned string would simply be thrown away and the page
     * would ship without the widget, with nothing anywhere to say why.
     *
     * ⚠ EVERY PARAMETER HAS A DEFAULT, AND ON THIS MAJOR THAT IS LOAD-BEARING.
     * An event handler is a public controller method, so it is also a reachable
     * storefront url. This major's dispatcher does not check the argument count
     * before calling: it invokes the method with none, and a required parameter
     * therefore produces an uncaught `ArgumentCountError` rendered as a page
     * carrying the fatal, the absolute file path and a full backtrace.
     *
     * That is not speculation and it is not our bug — OpenCart's own bundled
     * handlers do it. Requesting `?route=event/activity.addCustomer` on a stock
     * 4.1.0.3 store answers **HTTP 200** with `Error: Too few arguments to function
     * Opencart\Catalog\Controller\Event\Activity::addCustomer()` and the server's
     * directory layout underneath. Defaults plus the guard below turn that into an
     * empty 200, which is what an unrouted internal handler should be. OpenCart 3
     * refuses the call itself and needs no such care.
     *
     * IT CANNOT THROW. This runs while a shopper's page is being assembled: an
     * exception escaping here is a blank storefront, which is a far worse outcome
     * than a missing search box.
     *
     * @param string $route
     * @param array  $args
     * @param string $output the rendered header markup, modified in place
     */
    public function onStorefront(&$route = null, &$args = null, &$output = null): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        try {
            $runner = new Runner($this->db);

            $output = $runner->storefront()->injectInto($output);

            // The no-cron fallback. It returns immediately unless the interval has
            // elapsed AND there is work, and defers the sending until after the
            // shopper's page has been flushed — so this costs a page view nothing.
            $runner->pageLoadTick()->maybeRun();
        } catch (\Exception $e) {
            // Nothing to do and nowhere safe to say it: a storefront page is not
            // ours to interrupt, and the Configure screen's error slot is written by
            // the paths that can reach a database.
        } catch (\Throwable $e) {
        }
    }
}
