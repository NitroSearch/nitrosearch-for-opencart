<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

// Heading
$_['heading_title']       = 'NitroSearch';

// Text
$_['text_extension']      = 'Extensions';
$_['text_edit']           = 'Configure NitroSearch';
$_['text_success']        = 'Success: You have modified NitroSearch.';
$_['text_not_connected']  = 'This shop is not connected to NitroSearch yet.';
$_['text_connected']      = 'This shop is connected to NitroSearch.';
$_['text_shop_url']       = 'Shop address';
$_['text_verify_url']     = 'Proof-of-control endpoint';
$_['text_verify_help']    = 'NitroSearch fetches this address from the outside to confirm you control this shop. Opening it should return JSON. If your shop is behind a password, a firewall or a staging lock, it will not be reachable and verification cannot complete — that is a hosting setting, not a fault in this module.';
$_['text_cron_url']       = 'Scheduled sync address';
$_['text_cron_help']      = 'Point your hosting control panel\'s cron at this address every few minutes so catalogue changes reach NitroSearch promptly. It is safe to call as often as you like — it does a fixed amount of work and stops. Without a cron the shop still syncs, but only when someone visits it, and more slowly. Keep the address private: anyone who has it can make your shop sync.';

// Privacy
$_['text_share_data']     = 'Share anonymous search usage';
$_['text_share_data_help'] = 'On by default. Sends what shoppers searched for, what they clicked, and whether a search found nothing — so your NitroSearch dashboard can show which searches work and which end in a dead end. It is not tied to a person: no names, no email addresses, no accounts, no cookies, and no order contents. Turn it off and your storefront search keeps working exactly as it does now; the dashboard simply stops filling in.';

// Appearance
$_['text_appearance']       = 'Appearance';
$_['text_appearance_help']  = 'How the search panel looks on your storefront. Changes apply the next time a page is loaded — there is nothing to rebuild and no cache to clear.';
$_['text_look']             = 'Result density';
$_['text_look_roomy']       = 'Roomy — large thumbnail, two-line names';
$_['text_look_compact']     = 'Compact — more results before scrolling';
$_['text_look_images']      = 'Image-led — largest thumbnail';
$_['text_look_text']        = 'Text only — no thumbnails';
$_['text_scheme']           = 'Colour scheme';
$_['text_scheme_light']     = 'Light';
$_['text_scheme_dark']      = 'Dark';
$_['text_scheme_auto']      = 'Match the shopper\'s device';
$_['text_corners']          = 'Corners';
$_['text_corners_rounded']  = 'Rounded';
$_['text_corners_soft']     = 'Slightly rounded';
$_['text_corners_square']   = 'Square';
$_['text_accent']           = 'Accent colour';
$_['text_accent_help']      = 'Used for prices, the selected result and the buttons inside the panel. Leave empty to use the default. Label text on the accent is chosen automatically for contrast.';
$_['text_width']            = 'Panel width';
$_['text_width_auto']       = 'Automatic';
$_['text_width_wide']       = 'Wide';
$_['text_width_match']      = 'Match the search box';
$_['text_filters']          = 'Filters';
$_['text_filters_auto']     = 'Automatic';
$_['text_filters_top']      = 'Always along the top';
$_['text_filters_off']      = 'Hidden';

// Storefront behaviour
$_['text_behaviour']        = 'Storefront behaviour';
$_['text_results_takeover'] = 'Use NitroSearch for the search results page';
$_['text_results_help']     = 'On by default. Your theme\'s own results page is replaced with instant, filterable results. Turn this off to keep your theme\'s page and use NitroSearch only for the drop-down while typing.';
$_['text_show_badge']       = 'Show “Powered by NitroSearch”';
$_['text_badge_help']       = 'Off by default — a credit on your storefront is your choice, not ours. Turning it on places a small line at the bottom of the search panel.';
$_['text_save']             = 'Save settings';
$_['text_saved']            = 'Settings saved.';
$_['text_api_url']          = 'Service address';
$_['text_api_url_help']     = 'Leave this alone unless NitroSearch support has asked you to change it.';

// Error
$_['error_permission']    = 'Warning: You do not have permission to modify NitroSearch.';

// Buttons
$_['text_connect']        = 'Connect this shop';
$_['text_refresh']        = 'Check status';
$_['text_disconnect']     = 'Disconnect';
$_['text_sync']           = 'Sync catalogue now';
