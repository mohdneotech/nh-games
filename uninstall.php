<?php
// Runs when the plugin is deleted from wp-admin.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$o = get_option('nhg_settings', []);
if (!empty($o['wipe'])) {
    $ids = get_posts(['post_type' => 'nh_game', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
    foreach ($ids as $id) wp_delete_post($id, true);
}
foreach (['nhg_settings', 'nhg_psn_tokens', 'nhg_last_sync', 'nhg_steam_error', 'nhg_psn_error'] as $k) delete_option($k);
foreach (['nhg_stats', 'nhg_steam_live'] as $k) delete_transient($k);
wp_clear_scheduled_hook('nhg_sync');
