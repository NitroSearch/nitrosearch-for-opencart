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
 * A REFUSED SAVE MUST SAY SO, on both majors.
 *
 * Both adapters' save() discarded saveSettings()'s result and redirected with
 * `saved=1` regardless, so an http:// service address (refused, correctly: the
 * sync secret goes to it) was dropped under a green "Settings saved.".
 *
 * The message itself is pure and tested directly. The wiring cannot be, since
 * this runner has no OpenCart, so it is checked STRUCTURALLY in all four files
 * that carry it (two controllers, two templates), comments stripped. A fix made
 * in one major and forgotten in the other is this module's most repeated mistake.
 */

require_once dirname(dirname(__DIR__)) . '/src/Support/SaveNotice.php';

use NitroSearch\Support\SaveNotice;

$root = dirname(dirname(__DIR__));
$lang = array('error_api_url' => 'API', 'error_not_saved' => 'OTHER');

/** @return string the file with PHP comments removed (Twig files are returned whole) */
$code = function ($path) {
    $source = (string) file_get_contents($path);
    if (substr($path, -4) !== '.php') {
        return $source;
    }
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
};

$controllers = array(
    'oc4' => $root . '/adapters/oc4/admin/controller/module/nitrosearch.php',
    'oc3' => $root . '/adapters/oc3/upload/admin/controller/extension/module/nitrosearch.php',
);
$templates = array(
    'oc4' => $root . '/adapters/oc4/admin/view/template/module/nitrosearch.twig',
    'oc3' => $root . '/adapters/oc3/upload/admin/view/template/extension/module/nitrosearch.twig',
);
$languages = array(
    'oc4' => $root . '/adapters/oc4/admin/language/en-gb/module/nitrosearch.php',
    'oc3' => $root . '/adapters/oc3/upload/admin/language/en-gb/extension/module/nitrosearch.php',
);

return array(
    'nothing refused means nothing to say' => function () use ($lang) {
        ns_is('empty encode', '', SaveNotice::encode(array()));
        ns_is('empty query', '', SaveNotice::message('', $lang));
        ns_is('non-string query', '', SaveNotice::message(array('api_url'), $lang));
    },

    'a refused service address is named, other refusals are summarised' => function () use ($lang) {
        ns_is('api only', 'API', SaveNotice::message(SaveNotice::encode(array('api_url')), $lang));
        ns_is('other only', 'OTHER', SaveNotice::message('design_corners', $lang));
        ns_is('both', 'API OTHER', SaveNotice::message('design_corners,api_url', $lang));
    },

    'only plain identifiers survive the query string' => function () use ($lang) {
        ns_is('encode filters', 'api_url', SaveNotice::encode(array('api_url', '<script>', 'api_url', 'x y')));
        ns_is('markup is not a field', '', SaveNotice::message('<b>,a b,"', $lang));
    },

    'both controllers report a refusal instead of always saying saved' => function () use ($controllers, $code) {
        foreach ($controllers as $major => $path) {
            $c = $code($path);
            ns_true("$major keeps the save result", (bool) preg_match('/\$result\s*=\s*\$actions->saveSettings\(/', $c));
            ns_true("$major redirects on the result", strpos($c, "\$result['ok'] ? '&saved=1'") !== false);
            ns_false("$major has no unconditional saved=1", (bool) preg_match("/user_token'\]\s*\.\s*'&saved=1'/", $c));
            ns_true("$major builds the message", strpos($c, 'SaveNotice::message(') !== false);
            ns_true("$major renders the session error", strpos($c, "\$data['session_error']") !== false);
        }
    },

    'both templates show the two errors, and both language files define them' => function () use ($templates, $languages) {
        foreach ($templates as $major => $path) {
            $t = (string) file_get_contents($path);
            ns_true("$major shows save_error", strpos($t, '{{ save_error }}') !== false);
            ns_true("$major shows session_error", strpos($t, '{{ session_error }}') !== false);
        }
        foreach ($languages as $major => $path) {
            $_ = array();
            include $path;
            ns_true("$major defines error_api_url", isset($_['error_api_url']));
            ns_true("$major defines error_not_saved", isset($_['error_not_saved']));
        }
    },
);
