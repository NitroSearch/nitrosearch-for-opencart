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

// Error
$_['error_permission']    = 'Warning: You do not have permission to modify NitroSearch.';

// Buttons
$_['text_connect']        = 'Connect this shop';
$_['text_refresh']        = 'Check status';
$_['text_disconnect']     = 'Disconnect';
$_['text_sync']           = 'Sync catalogue now';
