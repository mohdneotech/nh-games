<?php
/**
 * Plugin Name:       NH Games â€” Steam & PlayStation Shelf
 * Plugin URI:        https://github.com/mohdneotech/nh-games
 * Description:       Show every game you've played on Steam and/or PlayStation â€” playtime, last played, achievements & trophies, a "Now playing" sidebar widget and a full games page. Enable only the platforms you use.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Mohd Nordin Hussain
 * Author URI:        https://mohdneotech.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nh-games
 */
if (!defined('ABSPATH')) exit;

define('NHG_VER', '1.0.1');
define('NHG_FILE', __FILE__);
define('NHG_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/steam.php';
require_once __DIR__ . '/includes/psn.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook(__FILE__, function () {
    nhg_register_cpt();
    flush_rewrite_rules();
    if (!wp_next_scheduled('nhg_sync')) wp_schedule_event(time() + 120, 'nhg_3h', 'nhg_sync');
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('nhg_sync');
    flush_rewrite_rules();
});
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($l) {
    array_unshift($l, '<a href="' . esc_url(admin_url('options-general.php?page=nh-games')) . '">Settings</a>');
    return $l;
});
