<?php
/* wp-admin: game meta box, list columns, notices. */
if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function () { add_meta_box('nhg_game', __('Game details', 'nh-games'), 'nhg_box', 'nh_game', 'side', 'high'); });
function nhg_box($p) {
    wp_nonce_field('nhg_save', 'nhg_nonce');
    $m = fn($k) => get_post_meta($p->ID, "_nhg_$k", true);
    echo '<p><label><input type="checkbox" name="nhg_pin" value="1"' . checked($m('pin'), '1', false) . '> ' . wp_kses_post(__('Pin as <b>Now playing</b>', 'nh-games')) . '</label></p>';
    echo '<p><label>' . esc_html__('My rating', 'nh-games') . '</label><select name="nhg_rating" style="width:100%">';
    foreach (['' => '—', 1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★'] as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($m('rating'), $k, false), $l);
    echo '</select></p>';
    if ($m('key')) {
        echo '<p class="description">' . wp_kses_post(sprintf(__('Synced from %s — stats refresh automatically; your review, excerpt and rating are never touched. Set to <b>Draft</b> to hide it.', 'nh-games'), $m('platform') === 'steam' ? 'Steam' : 'PlayStation')) . '</p>';
        echo '<p>' . esc_html(implode(' · ', array_filter([$m('device'), nhg_hours($m('minutes')), $m('last') ? nhg_ago($m('last')) : '']))) . '</p>';
        return;
    }
    echo '<p><label>' . esc_html__('Platform', 'nh-games') . '</label><select name="nhg_platform" style="width:100%"><option value="psn"' . selected($m('platform'), 'psn', false) . '>PlayStation</option><option value="steam"' . selected($m('platform'), 'steam', false) . '>Steam</option></select></p>';
    foreach (['device' => [__('Device', 'nh-games'), 'PS5', $m('device')], 'hours' => [__('Hours played', 'nh-games'), '', $m('minutes') ? round($m('minutes') / 60, 1) : ''], 'cover' => [__('Cover image URL', 'nh-games'), '', $m('cover')]] as $k => [$l, $ph, $val])
        echo '<p><label>' . esc_html($l) . '</label><input style="width:100%" name="nhg_' . $k . '" value="' . esc_attr($val) . '" placeholder="' . esc_attr($ph) . '"></p>';
    echo '<p><label>' . esc_html__('Last played', 'nh-games') . '</label><input type="date" style="width:100%" name="nhg_lastdate" value="' . esc_attr($m('last') ? gmdate('Y-m-d', (int) $m('last')) : '') . '"></p>';
}
add_action('save_post_nh_game', function ($id) {
    if (!isset($_POST['nhg_nonce']) || !wp_verify_nonce($_POST['nhg_nonce'], 'nhg_save') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id)) return;
    update_post_meta($id, '_nhg_pin', empty($_POST['nhg_pin']) ? '' : '1');
    update_post_meta($id, '_nhg_rating', min(5, max(0, (int) ($_POST['nhg_rating'] ?? 0))) ?: '');
    if (!get_post_meta($id, '_nhg_key', true)) { // manual entries
        update_post_meta($id, '_nhg_platform', ($_POST['nhg_platform'] ?? '') === 'steam' ? 'steam' : 'psn');
        update_post_meta($id, '_nhg_device', sanitize_text_field(wp_unslash($_POST['nhg_device'] ?? '')));
        update_post_meta($id, '_nhg_minutes', (int) round((float) ($_POST['nhg_hours'] ?? 0) * 60));
        update_post_meta($id, '_nhg_last', !empty($_POST['nhg_lastdate']) ? strtotime($_POST['nhg_lastdate'] . ' 12:00') : 0);
        update_post_meta($id, '_nhg_cover', esc_url_raw(wp_unslash($_POST['nhg_cover'] ?? '')));
    }
    delete_transient('nhg_stats');
});
add_filter('manage_nh_game_posts_columns', fn($c) => array_slice($c, 0, 2) + ['nhg_pf' => __('Platform', 'nh-games'), 'nhg_time' => __('Playtime', 'nh-games'), 'nhg_last' => __('Last played', 'nh-games'), 'nhg_pct' => __('Completion', 'nh-games')] + $c);
add_action('manage_nh_game_posts_custom_column', function ($col, $id) {
    $m = fn($k) => get_post_meta($id, "_nhg_$k", true);
    if ($col === 'nhg_pf') echo esc_html(($m('platform') === 'steam' ? 'Steam' : 'PlayStation') . ($m('device') ? ' · ' . $m('device') : '')) . ($m('pin') ? ' 📌' : '');
    if ($col === 'nhg_time') echo esc_html(nhg_hours($m('minutes')));
    if ($col === 'nhg_last') echo esc_html(nhg_ago($m('last')));
    if ($col === 'nhg_pct') echo $m('pct') !== '' ? (int) $m('pct') . '%' : '—';
}, 10, 2);
add_action('admin_notices', function () {
    $sc = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!current_user_can('manage_options') || ($sc && $sc->id === 'settings_page_nh-games')) return;
    $url = esc_url(admin_url('options-general.php?page=nh-games'));
    if (!nhg_enabled_platforms() && $sc && in_array($sc->id, ['plugins', 'edit-nh_game'], true)) echo '<div class="notice notice-info"><p><b>NH Games:</b> ' . esc_html__('choose Steam and/or PlayStation to start syncing.', 'nh-games') . ' <a href="' . $url . '">' . esc_html__('Set up', 'nh-games') . '</a></p></div>';
    if (nhg_on('psn') && ($e = get_option('nhg_psn_error'))) echo '<div class="notice notice-warning"><p><b>NH Games:</b> ' . esc_html($e) . ' <a href="' . $url . '">' . esc_html__('Fix', 'nh-games') . '</a></p></div>';
});
