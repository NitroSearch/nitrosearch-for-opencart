<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

/**
 * Loader for the module's shared classes.
 *
 * DELIBERATELY NOT OPENCART'S AUTOLOADER, on either major. OpenCart 4 registers
 * `Opencart\System\Library\Extension\<Code>` at runtime for installed extensions
 * and OpenCart 3 has no namespace registration at all, so relying on either would
 * mean two different loading strategies for the same classes — and on OpenCart 4
 * it would also mean the shared code is unavailable until the extension is
 * registered in the database, which is precisely when install-time code needs it.
 *
 * A `require_once` keyed on this file's own directory works identically in both,
 * before either framework has finished booting, and cannot be affected by an
 * extension's registration state.
 *
 * `spl_autoload_register` rather than a list of requires: the shared tree grows,
 * and a missed entry in a list fails at runtime on the one path that needed it.
 */

spl_autoload_register(function ($class) {
    $prefix = 'NitroSearch\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
