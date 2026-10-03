<?php
/* Core: post type, schedule, storage, queries, formatting helpers. */
if (!defined('ABSPATH')) exit;

function nhg_opt($k, $d = '') { $o = get_option('nhg_settings', []); return ($o[$k] ?? '') !== '' ? $o[$k] : $d; }
function nhg_on($platform) { return (bool) nhg_opt($platform . '_on', 0); }

function nhg_register_cpt() {
    register_post_type('nh_game', [
        'label' => __('Games', 'nh-games'), 'public' => true, 'menu_icon' => 'dashicons-games',
        'has_archive' => false, 'rewrite' => ['slug' => apply_filters('nhg_cpt_slug', 'played')], 'show_in_rest' => true,
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail'],
        'labels' => ['name' => __('Games', 'nh-games'), 'singular_name' => __('Game', 'nh-games'), 'add_new_item' => __('Add game', 'nh-games'), 'edit_item' => __('Edit game', 'nh-games')],
    ]);
}
add_action('init', function () {
    nhg_register_cpt();
    if (!wp_next_scheduled('nhg_sync')) wp_schedule_event(time() + 120, 'nhg_3h', 'nhg_sync');
});
add_filter('cron_schedules', function ($s) { $s['nhg_3h'] = ['interval' => 3 * HOUR_IN_SECONDS, 'display' => 'Every 3 hours']; return $s; });
add_action('nhg_sync', 'nhg_sync_all');

/** Create or update a synced game. Your title edits, review, excerpt, rating and Draft status are never touched. */
function nhg_upsert($key, $title, $meta) {
    $q = get_posts(['post_type' => 'nh_game', 'post_status' => 'any', 'meta_key' => '_nhg_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids']);
    if ($q) $id = $q[0];
    else {
        $id = wp_insert_post(['post_type' => 'nh_game', 'post_status' => 'publish', 'post_title' => wp_strip_all_tags($title)]);
        if (!$id || is_wp_error($id)) return 0;
        update_post_meta($id, '_nhg_key', $key);
    }
    foreach ($meta as $k => $v) update_post_meta($id, "_nhg_$k", $v);
    return $id;
}
function nhg_sync_all() {
    @set_time_limit(300);
    $s = nhg_on('steam') ? nhg_steam_sync() : 0;
    $p = nhg_on('psn') ? nhg_psn_sync() : 0;
    update_option('nhg_last_sync', ['time' => current_time('mysql'), 'steam' => $s, 'psn' => $p], false);
    delete_transient('nhg_stats');
    return [$s, $p];
}

/* ---------- Queries ---------- */
function nhg_enabled_platforms() { return array_values(array_filter(['steam', 'psn'], 'nhg_on')); }
function nhg_q($platform = '', $n = -1) {
    $a = ['post_type' => 'nh_game', 'posts_per_page' => $n, 'meta_key' => '_nhg_last', 'orderby' => 'meta_value_num', 'order' => 'DESC'];
    $pf = $platform ? [$platform] : nhg_enabled_platforms();
    $a['meta_query'] = [['key' => '_nhg_platform', 'value' => $pf ?: ['__none__'], 'compare' => 'IN']];
    return get_posts($a);
}
/** false | true (played in the last N hours, or pinned) | 'live' (in-game on Steam right now) */
function nhg_is_now($p, $live = '') {
    $m = fn($k) => get_post_meta($p->ID, "_nhg_$k", true);
    if ($live && $m('platform') === 'steam' && (string) $m('appid') === (string) $live) return 'live';
    if ($m('pin')) return true;
    return (int) $m('last') && (time() - (int) $m('last')) < (int) nhg_opt('now_hours', 48) * HOUR_IN_SECONDS;
}
function nhg_now($n = 3, $live = '') {
    $pins = get_posts(['post_type' => 'nh_game', 'posts_per_page' => $n, 'meta_key' => '_nhg_pin', 'meta_value' => '1']);
    $out = [];
    foreach (array_merge($pins, nhg_q('', 12)) as $p) {
        if (count($out) >= $n) break;
        if (!isset($out[$p->ID]) && in_array(get_post_meta($p->ID, '_nhg_platform', true), nhg_enabled_platforms(), true) && nhg_is_now($p, $live)) $out[$p->ID] = $p;
    }
    uasort($out, fn($a, $b) => (nhg_is_now($b, $live) === 'live') <=> (nhg_is_now($a, $live) === 'live'));
    return array_values($out);
}
function nhg_stats() {
    $c = get_transient('nhg_stats');
    if (is_array($c)) return $c;
    $s = ['games' => 0, 'min' => 0, 'steam' => 0, 'psn' => 0, 'ach' => 0, 'tro' => 0, 'plat' => 0];
    foreach (nhg_q() as $p) {
        $m = fn($k) => get_post_meta($p->ID, "_nhg_$k", true);
        $s['games']++; $s['min'] += (int) $m('minutes');
        if ($m('platform') === 'steam') { $s['steam']++; $s['ach'] += (int) $m('ach_got'); }
        else { $s['psn']++; $s['tro'] += (int) $m('ach_got'); $s['plat'] += (int) (($m('tro') ?: [])['platinum'] ?? 0); }
    }
    $s['hours'] = (int) round($s['min'] / 60);
    set_transient('nhg_stats', $s, HOUR_IN_SECONDS);
    return $s;
}

/* ---------- Formatting ---------- */
function nhg_hours($m) {
    $m = (int) $m;
    if (!$m) return '';
    return $m < 60 ? $m . ' min' : number_format_i18n($m / 60, $m < 600 ? 1 : 0) . ' h';
}
function nhg_ago($t) { $t = (int) $t; return $t ? (time() - $t < HOUR_IN_SECONDS ? __('just now', 'nh-games') : sprintf(__('%s ago', 'nh-games'), human_time_diff($t))) : ''; }
function nhg_pf($p) { return get_post_meta($p->ID, '_nhg_platform', true) === 'steam' ? ['fa-brands fa-steam', 'Steam'] : ['fa-brands fa-playstation', 'PlayStation']; }
function nhg_tight($h) { return trim(preg_replace('/>\s+</', '><', preg_replace('/\s*\n\s*/', ' ', $h))); }
function nhg_http_json($url, $args = []) {
    $r = wp_remote_get($url, $args + ['timeout' => 20]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) >= 500) return null;
    return json_decode(wp_remote_retrieve_body($r), true);
}
