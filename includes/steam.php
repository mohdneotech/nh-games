<?php
/* Steam — official Steam Web API (key + SteamID64). */
if (!defined('ABSPATH')) exit;

function nhg_steam_api($path, $args, $timeout = 20, $key = null) {
    $args['key'] = $key ?? nhg_opt('steam_key');
    return nhg_http_json('https://api.steampowered.com/' . $path . '?' . http_build_query($args), ['timeout' => $timeout]);
}
function nhg_steam_resolve($v, $key = null) {
    $v = trim(preg_replace('~^.*/id/([^/?#]+).*$~', '$1', trim($v)), '/ ');
    $j = nhg_steam_api('ISteamUser/ResolveVanityURL/v1/', ['vanityurl' => $v], 10, $key);
    return ($j['response']['success'] ?? 0) == 1 ? (string) $j['response']['steamid'] : '';
}
/** Newer games keep art at hashed CDN paths; the legacy portrait/hero URLs return grey placeholders for them. */
function nhg_apply_art($id, $art) {
    update_post_meta($id, '_nhg_cover_alt', $art);
    if (preg_match('~/[0-9a-f]{40}/~', $art)) {
        update_post_meta($id, '_nhg_cover', $art);
        update_post_meta($id, '_nhg_hero', $art);
    }
}
function nhg_steam_sync() {
    $sid = nhg_opt('steam_id');
    if (!nhg_opt('steam_key') || !$sid) { update_option('nhg_steam_error', __('Add your Steam Web API key and profile in Settings → NH Games.', 'nh-games'), false); return 0; }
    if (!preg_match('/^\d{17}$/', $sid)) {
        $r = nhg_steam_resolve($sid);
        if (!$r) { update_option('nhg_steam_error', sprintf(__('Couldn\'t resolve Steam profile "%s" — check the name or use your SteamID64.', 'nh-games'), $sid), false); return 0; }
        $o = get_option('nhg_settings', []); $o['steam_id'] = $sid = $r;
        remove_all_filters('sanitize_option_nhg_settings'); update_option('nhg_settings', $o);
    }
    $j = nhg_steam_api('IPlayerService/GetOwnedGames/v1/', ['steamid' => $sid, 'include_appinfo' => 1, 'include_played_free_games' => 1]);
    $games = $j['response']['games'] ?? null;
    if ($games === null) { update_option('nhg_steam_error', __('Steam returned no games — check the API key, SteamID64, and that Privacy → Game details is Public.', 'nh-games'), false); return 0; }
    delete_option('nhg_steam_error');
    $min = (int) nhg_opt('min_minutes', 0);
    $cdn = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/';
    $ids = [];
    foreach ($games as $g) {
        if ((int) $g['playtime_forever'] <= $min) continue;
        $a = (int) $g['appid'];
        $id = nhg_upsert("steam:$a", $g['name'], [
            'platform' => 'steam', 'device' => 'PC', 'appid' => $a, 'minutes' => (int) $g['playtime_forever'],
            'last' => (int) ($g['rtime_last_played'] ?? 0), 'cover' => "{$cdn}$a/library_600x900.jpg",
            'cover_alt' => "{$cdn}$a/header.jpg", 'hero' => "{$cdn}$a/library_hero.jpg", 'link' => "https://store.steampowered.com/app/$a/",
        ]);
        if (!$id) continue;
        $ids[$id] = (int) ($g['rtime_last_played'] ?? 0);
        if ($art = get_post_meta($id, '_nhg_art', true)) nhg_apply_art($id, $art);
    }
    // Real header art — one store lookup per game, 30 per run.
    $budget = 30;
    foreach (array_keys($ids) as $id) {
        if ($budget <= 0) break;
        if (get_post_meta($id, '_nhg_art_checked', true)) continue;
        $budget--;
        $a = get_post_meta($id, '_nhg_appid', true);
        $j = nhg_http_json("https://store.steampowered.com/api/appdetails?appids=$a&l=english", ['timeout' => 12]);
        if ($j === null) continue;
        if ($h = $j[$a]['data']['header_image'] ?? '') { $h = preg_replace('/\?t=\d+$/', '', $h); update_post_meta($id, '_nhg_art', $h); nhg_apply_art($id, $h); }
        update_post_meta($id, '_nhg_art_checked', time());
    }
    // Achievements — only games played since the last check, 40 per run.
    arsort($ids);
    $budget = 40;
    foreach ($ids as $id => $last) {
        if ($budget <= 0) break;
        $chk = (int) get_post_meta($id, '_nhg_ach_checked', true);
        if ($chk && $last < $chk) continue;
        $budget--;
        $r = nhg_steam_api('ISteamUserStats/GetPlayerAchievements/v1/', ['steamid' => $sid, 'appid' => get_post_meta($id, '_nhg_appid', true)], 12);
        if ($r === null) continue;
        if (empty($r['playerstats']['success']) && stripos($r['playerstats']['error'] ?? '', 'not public') !== false) {
            update_option('nhg_steam_error', __('Games synced, but achievements are hidden — set Steam Privacy → "Game details" to Public (not Friends Only).', 'nh-games'), false);
            break;
        }
        $list = $r['playerstats']['achievements'] ?? [];
        $tot = count($list);
        $got = count(array_filter($list, fn($x) => !empty($x['achieved'])));
        update_post_meta($id, '_nhg_ach_total', $tot);
        update_post_meta($id, '_nhg_ach_got', $got);
        update_post_meta($id, '_nhg_pct', $tot ? (int) round($got * 100 / $tot) : '');
        update_post_meta($id, '_nhg_ach_checked', time());
    }
    return count($ids);
}
/** Current in-game appid (Steam presence), cached 5 minutes. */
function nhg_steam_live() {
    if (!nhg_on('steam')) return '';
    $c = get_transient('nhg_steam_live');
    if ($c !== false) return $c;
    $live = '';
    if (nhg_opt('steam_key') && preg_match('/^\d{17}$/', nhg_opt('steam_id'))) {
        $j = nhg_steam_api('ISteamUser/GetPlayerSummaries/v2/', ['steamids' => nhg_opt('steam_id')], 4);
        $live = (string) ($j['response']['players'][0]['gameid'] ?? '');
    }
    set_transient('nhg_steam_live', $live, 5 * MINUTE_IN_SECONDS);
    return $live;
}
