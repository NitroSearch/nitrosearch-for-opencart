<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Support;

/**
 * What the Configure screen says when a save refused something.
 *
 * WHY THIS EXISTS. Both adapters' save() threw away Actions::saveSettings()'s
 * result and redirected with `saved=1` regardless, so a refused value was
 * reported as "Settings saved.". The case a merchant actually meets is the
 * service address. It must be https (the sync secret travels to it), and an
 * http:// address was dropped with a green confirmation above it. The shop then
 * kept connecting to the old address and nothing on the screen said why.
 *
 * The refused field NAMES travel in the redirect's query string, as `saved`
 * already does, and are filtered here to plain identifiers before anything reads
 * them. Only identifiers ever cross; the message is built from the language file.
 */
final class SaveNotice
{
    /**
     * @param array<int, string> $rejected field names from saveSettings()
     *
     * @return string the query-string value, '' when nothing was refused
     */
    public static function encode(array $rejected)
    {
        return implode(',', self::clean($rejected));
    }

    /**
     * @param mixed                 $query the `rejected` query-string value
     * @param array<string, string> $lang  the module's language strings
     *
     * @return string the message to show, '' when nothing was refused
     */
    public static function message($query, array $lang)
    {
        $fields = self::clean(explode(',', is_string($query) ? $query : ''));
        if ($fields === array()) {
            return '';
        }

        $parts = array();
        if (in_array('api_url', $fields, true)) {
            $parts[] = $lang['error_api_url'] ?? 'The service address was not saved: it must start with https://.';
        }
        if (array_diff($fields, array('api_url')) !== array()) {
            $parts[] = $lang['error_not_saved'] ?? 'Some choices were not recognised and were left unchanged.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int, mixed> $fields
     *
     * @return array<int, string>
     */
    private static function clean(array $fields)
    {
        $out = array();
        foreach ($fields as $field) {
            if (is_string($field) && preg_match('/^[a-z_]{1,40}$/', $field) && !in_array($field, $out, true)) {
                $out[] = $field;
            }
        }

        return $out;
    }
}
