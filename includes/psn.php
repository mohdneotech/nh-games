<?php
/*
 * PlayStation — the PSN mobile API (unofficial; the same endpoints used by psn-api / psnawp).
 * You paste a one-time NPSSO cookie; it's exchanged for a refresh token and discarded.
 * The refresh token lasts ~2 months and renews access tokens automatically.
 */
if (!defined('ABSPATH')) exit;

const NHG_PSN_AUTH = 'https://ca.account.sony.com/api/authz/v3/oauth';
const NHG_PSN_CLIENT = '09515159-7237-4370-9b40-3806e67c0891';
const NHG_PSN_BASIC = 'MDk1MTUxNTktNzIzNy00MzcwLTliNDAtMzgwNmU2N2MwODkxOnVjUGprYTV0bnRCMktxc1A=';
const NHG_PSN_REDIRECT = 'com.scee.psxandroid.scecompcall://redirect';
const NHG_PSN_SCOPE = 'psn:mobile.v2.core psn:clientapp';

function nhg_psn_login($npsso) {
    $q = http_build_query(['access_type' => 'offline', 'client_id' => NHG_PSN_CLIENT, 'redirect_uri' => NHG_PSN_REDIRECT, 'response_type' => 'code', 'scope' => NHG_PSN_SCOPE]);
    $r = wp_remote_get(NHG_PSN_AUTH . '/authorize?' . $q, ['redirection' => 0, 'timeout' => 15, 'headers' => ['Cookie' => 'npsso=' . $npsso]]);
    if (is_wp_error($r)) return $r->get_error_message();
    $loc = (array) wp_remote_retrieve_header($r, 'location');
    if (!preg_match('/[?&]code=([^&]+)/', implode(' ', $loc), $m)) return __('Sony did not return an access code — the NPSSO has probably expired. Sign in again on playstation.com and grab a fresh one.', 'nh-games');
    return nhg_psn_token(['code' => urldecode($m[1]), 'redirect_uri' => NHG_PSN_REDIRECT, 'grant_type' => 'authorization_code', 'token_format' => 'jwt']);
}
function nhg_psn_token($body) {
    $old = get_option('nhg_psn_tokens', []);
    $r = wp_remote_post(NHG_PSN_AUTH . '/token', ['timeout' => 15, 'headers' => ['Authorization' => 'Basic ' . NHG_PSN_BASIC, 'Content-Type' => 'application/x-www-form-urlencoded'], 'body' => $body]);
    if (is_wp_error($r)) return $r->get_error_message();
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (empty($j['access_token'])) return 'token exchange failed (' . ($j['error_description'] ?? $j['error'] ?? 'HTTP ' . wp_remote_retrieve_response_code($r)) . ')';
    update_option('nhg_psn_tokens', [
        'access' => $j['access_token'], 'access_exp' => time() + (int) $j['expires_in'] - 60,
        'refresh' => $j['refresh_token'] ?? ($old['refresh'] ?? ''),
        'refresh_exp' => isset($j['refresh_token_expires_in']) ? time() + (int) $j['refresh_token_expires_in'] : ($old['refresh_exp'] ?? 0),
    ], false);
    delete_option('nhg_psn_error');
    return true;
}
function nhg_psn_access() {
    $t = get_option('nhg_psn_tokens', []);
    if (!empty($t['access']) && $t['access_exp'] > time()) return $t['access'];
    if (empty($t['refresh']) || $t['refresh_exp'] < time()) { update_option('nhg_psn_error', empty($t) ? __('PlayStation not connected yet — paste an NPSSO in Settings → NH Games.', 'nh-games') : __('PlayStation login expired — paste a fresh NPSSO in Settings → NH Games.', 'nh-games'), false); return null; }
    $ok = nhg_psn_token(['refresh_token' => $t['refresh'], 'grant_type' => 'refresh_token', 'token_format' => 'jwt', 'scope' => NHG_PSN_SCOPE]);
    if ($ok !== true) { update_option('nhg_psn_error', 'Refresh failed: ' . $ok, false); return null; }
    return get_option('nhg_psn_tokens')['access'];
}
function nhg_psn_get($path, $tok) {
    return nhg_http_json('https://m.np.playstation.com/api' . $path, ['timeout' => 25, 'headers' => ['Authorization' => 'Bearer ' . $tok, 'Accept-Language' => 'en-US']]);
}
function nhg_iso_min($d) {
    if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?/', (string) $d, $m)) return 0;
    return (int) ($m[1] ?? 0) * 1440 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
}
function nhg_norm($s) {
    $s = preg_replace('/[^a-z0-9]/', '', strtolower(remove_accents(html_entity_decode((string) $s))));
    return preg_replace('/(ps4|ps5|trophies|trophy|remastered|thefinalcut)+$/', '', $s);
}
function nhg_psn_sync() {
    $tok = nhg_psn_access();
    if (!$tok) return 0;
    $titles = []; $off = 0;
    do {
        $j = nhg_psn_get("/gamelist/v2/users/me/titles?categories=ps4_game,ps5_native_game&limit=200&offset=$off", $tok);
        if (!isset($j['titles'])) break;
        $titles = array_merge($titles, $j['titles']);
        $off = $j['nextOffset'] ?? null;
    } while ($off && count($titles) < 3000);
    $tro = []; $off = 0;
    do {
        $j = nhg_psn_get("/trophy/v1/users/me/trophyTitles?limit=800&offset=$off", $tok);
        if (!isset($j['trophyTitles'])) break;
        $tro = array_merge($tro, $j['trophyTitles']);
        $off = $j['nextOffset'] ?? null;
    } while ($off && count($tro) < 5000);
    if (!$titles && !$tro) { update_option('nhg_psn_error', __('PSN returned no games — the API may have changed or the token lacks access.', 'nh-games'), false); return 0; }
    delete_option('nhg_psn_error');

    $g = []; // merge PS4 + PS5 versions of the same game by concept
    foreach ($titles as $t) {
        $k = !empty($t['concept']['id']) ? 'c' . $t['concept']['id'] : 't' . $t['titleId'];
        $dev = ($t['category'] ?? '') === 'ps5_native_game' ? 'PS5' : 'PS4';
        $last = strtotime($t['lastPlayedDateTime'] ?? '') ?: 0;
        $first = strtotime($t['firstPlayedDateTime'] ?? '') ?: 0;
        if (!isset($g[$k])) $g[$k] = ['name' => $t['name'], 'dev' => [], 'min' => 0, 'last' => 0, 'first' => $first, 'plays' => 0, 'img' => '', 'cid' => $t['concept']['id'] ?? ''];
        $x = &$g[$k];
        $x['dev'][$dev] = 1; $x['min'] += nhg_iso_min($t['playDuration'] ?? ''); $x['plays'] += (int) ($t['playCount'] ?? 0);
        if ($last >= $x['last']) { $x['last'] = $last; $x['name'] = $t['name']; $x['img'] = $t['imageUrl'] ?? ($t['concept']['media']['images'][0]['url'] ?? $x['img']); }
        if ($first && (!$x['first'] || $first < $x['first'])) $x['first'] = $first;
        unset($x);
    }
    $byName = [];
    foreach ($tro as $i => $t) $byName[nhg_norm($t['trophyTitleName'])][] = $i;
    $used = []; $n = 0;
    foreach ($g as $k => $x) {
        if ($x['min'] <= (int) nhg_opt('min_minutes', 0) && !$x['plays']) continue;
        $match = [];
        foreach ($byName[nhg_norm($x['name'])] ?? [] as $i) if (!isset($used[$i])) { $used[$i] = 1; $match[] = $tro[$i]; }
        ksort($x['dev']);
        $meta = ['platform' => 'psn', 'device' => implode(' · ', array_keys($x['dev'])), 'minutes' => $x['min'], 'last' => $x['last'], 'first' => $x['first'],
                 'plays' => $x['plays'], 'cover' => $x['img'], 'link' => $x['cid'] ? 'https://store.playstation.com/concept/' . $x['cid'] : ''];
        if ($match) $meta += nhg_tro_meta($match);
        if (nhg_upsert("psn:$k", $x['name'], $meta)) $n++;
    }
    foreach ($tro as $i => $t) { // trophy-only titles: PS3 / Vita / anything the game list misses
        if (isset($used[$i])) continue;
        $plat = str_replace(['PSVITA', 'PSPC', ','], ['Vita', 'PC', ' · '], $t['trophyTitlePlatform'] ?? '');
        if (preg_match('/PS[45]/', $plat) && !array_sum($t['earnedTrophies'] ?? [])) continue;
        $meta = ['platform' => 'psn', 'device' => $plat, 'last' => strtotime($t['lastUpdatedDateTime'] ?? '') ?: 0, 'cover' => $t['trophyTitleIconUrl'] ?? ''] + nhg_tro_meta([$t]);
        if (nhg_upsert('psnt:' . $t['npCommunicationId'], $t['trophyTitleName'], $meta)) $n++;
    }
    return $n;
}
function nhg_tro_meta($list) {
    $e = ['platinum' => 0, 'gold' => 0, 'silver' => 0, 'bronze' => 0]; $d = $e;
    foreach ($list as $t) foreach ($e as $k => $_) { $e[$k] += (int) ($t['earnedTrophies'][$k] ?? 0); $d[$k] += (int) ($t['definedTrophies'][$k] ?? 0); }
    $got = array_sum($e); $tot = array_sum($d);
    return ['tro' => $e, 'tro_def' => $d, 'ach_got' => $got, 'ach_total' => $tot, 'pct' => $tot ? (int) round(max(array_column($list, 'progress') ?: [0])) : ''];
}
