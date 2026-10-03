<?php
/* Settings → NH Games */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () { add_options_page('NH Games', 'NH Games', 'manage_options', 'nh-games', 'nhg_settings_page'); });
add_action('admin_init', function () { register_setting('nhg', 'nhg_settings', ['sanitize_callback' => 'nhg_sanitize']); });

function nhg_sanitize($in) {
    static $done = null; if ($done !== null) return $done; // WP can call this twice on first save
    $old = get_option('nhg_settings', []);
    $txt = fn($k) => trim(sanitize_text_field($in[$k] ?? ''));
    $cls = fn($k) => trim(preg_replace('/[^A-Za-z0-9_\- ]/', '', $in[$k] ?? ''));
    $out = [
        'steam_on' => empty($in['steam_on']) ? 0 : 1,
        'psn_on'   => empty($in['psn_on']) ? 0 : 1,
        'steam_key' => $old['steam_key'] ?? '',
        'steam_id'  => $txt('steam_id'),
        'psn_id'    => $txt('psn_id'),
        'min_minutes' => max(0, (int) ($in['min_minutes'] ?? 0)),
        'now_hours'   => min(720, max(1, (int) ($in['now_hours'] ?? 48))),
        'per_page'    => min(200, max(6, (int) ($in['per_page'] ?? 24))),
        'page'        => esc_url_raw($in['page'] ?? ''),
        'accent'      => sanitize_hex_color($in['accent'] ?? '') ?: '#FFCC00',
        'scheme'      => in_array($in['scheme'] ?? '', ['dark', 'light'], true) ? $in['scheme'] : 'dark',
        'widget_class' => $cls('widget_class'),
        'widget_title_class' => $cls('widget_title_class'),
        'wipe' => empty($in['wipe']) ? 0 : 1,
    ];
    // Steam keys are exactly 32 hex characters — anything else is usually browser password autofill.
    $nk = strtoupper($txt('steam_key'));
    if ($nk !== '') {
        if (preg_match('/^[A-F0-9]{32}$/', $nk)) $out['steam_key'] = $nk;
        else add_settings_error('nhg_settings', 'steamkey', __('That Steam API key doesn\'t look right — it should be 32 characters (0–9, A–F). If the box was pre-filled, that was your browser\'s autofill.', 'nh-games'));
    }
    if ($out['steam_on'] && $out['steam_id'] && !preg_match('/^\d{17}$/', $out['steam_id'])) {
        if (preg_match('~/profiles/(\d{17})~', $out['steam_id'], $m)) $out['steam_id'] = $m[1];
        elseif ($out['steam_key']) {
            $sid = nhg_steam_resolve($out['steam_id'], $out['steam_key']);
            if ($sid) $out['steam_id'] = $sid;
            else add_settings_error('nhg_settings', 'steam', sprintf(__('Steam couldn\'t find a profile called "%s". Use the name from steamcommunity.com/id/<name> or your 17-digit SteamID64.', 'nh-games'), esc_html($out['steam_id'])));
        }
    }
    if ($out['psn_on'] && preg_match('/[A-Za-z0-9]{64}/', $in['npsso'] ?? '', $m)) {
        $r = nhg_psn_login($m[0]);
        if ($r === true) add_settings_error('nhg_settings', 'psn', __('PlayStation connected. The NPSSO was used once and discarded.', 'nh-games'), 'success');
        else add_settings_error('nhg_settings', 'psn', 'PlayStation: ' . $r);
    }
    if (!$out['steam_on']) delete_option('nhg_steam_error');
    if (!$out['psn_on']) delete_option('nhg_psn_error');
    delete_transient('nhg_stats'); delete_transient('nhg_steam_live');
    return $done = $out;
}

function nhg_settings_page() {
    if (isset($_POST['nhg_sync_now']) && check_admin_referer('nhg_sync')) {
        [$s, $p] = nhg_sync_all();
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Sync done — Steam: %1$d games, PlayStation: %2$d games.', 'nh-games'), $s, $p)) . '</p></div>';
    }
    if (isset($_POST['nhg_psn_logout']) && check_admin_referer('nhg_sync')) { delete_option('nhg_psn_tokens'); echo '<div class="notice notice-info"><p>' . esc_html__('PlayStation tokens removed.', 'nh-games') . '</p></div>'; }
    foreach (['steam' => 'Steam', 'psn' => 'PlayStation'] as $k => $l) if (nhg_on($k) && ($e = get_option("nhg_{$k}_error"))) echo '<div class="notice notice-error"><p><b>' . $l . ':</b> ' . esc_html($e) . '</p></div>';
    $t = get_option('nhg_psn_tokens', []);
    $last = get_option('nhg_last_sync', []);
    $ck = fn($k) => checked(nhg_opt($k, 0), 1, false);
    $v = fn($k, $d = '') => esc_attr(nhg_opt($k, $d)); ?>
    <div class="wrap nhg-admin"><h1>NH Games</h1>
    <p><?php esc_html_e('Show the games you\'ve played. Turn on the platforms you use — you only need details for those.', 'nh-games'); ?></p>
    <form method="post" action="options.php"><?php settings_fields('nhg'); ?>
    <h2><?php esc_html_e('Platforms', 'nh-games'); ?></h2>
    <p class="nhg-toggles">
      <label><input type="checkbox" name="nhg_settings[steam_on]" value="1" data-nhg-toggle="steam" <?php echo $ck('steam_on'); ?>> <span class="dashicons dashicons-games"></span> <b>Steam</b></label>
      <label><input type="checkbox" name="nhg_settings[psn_on]" value="1" data-nhg-toggle="psn" <?php echo $ck('psn_on'); ?>> <span class="dashicons dashicons-games"></span> <b>PlayStation</b></label>
    </p>

    <div data-nhg-section="steam"><h2>Steam</h2><table class="form-table">
      <tr><th><?php esc_html_e('Steam Web API key', 'nh-games'); ?></th><td><input type="text" class="regular-text code" name="nhg_settings[steam_key]" value="" placeholder="<?php echo nhg_opt('steam_key') ? esc_attr('✓ saved (…' . substr(nhg_opt('steam_key'), -4) . ') — leave blank to keep') : esc_attr__('32-character key', 'nh-games'); ?>" autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore>
        <p class="description"><?php printf(esc_html__('Get one free at %s (any domain name works).', 'nh-games'), '<a href="https://steamcommunity.com/dev/apikey" target="_blank" rel="noopener">steamcommunity.com/dev/apikey</a>'); ?></p></td></tr>
      <tr><th><?php esc_html_e('Steam profile', 'nh-games'); ?></th><td><input class="regular-text" name="nhg_settings[steam_id]" value="<?php echo $v('steam_id'); ?>" placeholder="<?php esc_attr_e('profile URL, custom name, or SteamID64', 'nh-games'); ?>">
        <p class="description"><?php esc_html_e('Steam → Edit Profile → Privacy Settings: set "My profile" and "Game details" to Public.', 'nh-games'); ?></p></td></tr>
    </table></div>

    <div data-nhg-section="psn"><h2>PlayStation</h2><table class="form-table">
      <tr><th><?php esc_html_e('PSN Online ID', 'nh-games'); ?></th><td><input class="regular-text" name="nhg_settings[psn_id]" value="<?php echo $v('psn_id'); ?>"><p class="description"><?php esc_html_e('Optional — used for the profile link on the games page.', 'nh-games'); ?></p></td></tr>
      <tr><th><?php esc_html_e('Status', 'nh-games'); ?></th><td><?php
        if (!empty($t['refresh'])) printf('<span style="color:#1a7f37">● %s</span> — %s', esc_html__('Connected', 'nh-games'), esc_html(sprintf(__('login valid for ~%d more days; it renews itself until then.', 'nh-games'), max(0, round(($t['refresh_exp'] - time()) / DAY_IN_SECONDS)))));
        else echo '<span style="color:#b32d2e">● ' . esc_html__('Not connected', 'nh-games') . '</span>'; ?></td></tr>
      <tr><th><?php esc_html_e('NPSSO token', 'nh-games'); ?></th><td><input type="password" class="regular-text" name="nhg_settings[npsso]" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?php esc_attr_e('paste the 64-character npsso (or the whole JSON)', 'nh-games'); ?>">
        <p class="description"><?php echo wp_kses_post(__('1. Sign in at <a href="https://www.playstation.com" target="_blank" rel="noopener">playstation.com</a>. 2. In the same browser open <code>https://ca.account.sony.com/api/v1/ssocookie</code>. 3. Paste the <code>npsso</code> value here and Save.<br>It\'s exchanged for a refresh token straight away and never stored. Treat it like a password. This uses Sony\'s unofficial mobile API, which may change.', 'nh-games')); ?></p></td></tr>
    </table></div>

    <h2><?php esc_html_e('Display', 'nh-games'); ?></h2><table class="form-table">
      <tr><th><?php esc_html_e('Games page URL', 'nh-games'); ?></th><td><input class="regular-text" name="nhg_settings[page]" value="<?php echo $v('page', home_url('/playing/')); ?>"><p class="description"><?php esc_html_e('The page where you placed [nh_games]. Widgets link here.', 'nh-games'); ?></p></td></tr>
      <tr><th><?php esc_html_e('Hide games under', 'nh-games'); ?></th><td><input type="number" min="0" style="width:6em" name="nhg_settings[min_minutes]" value="<?php echo $v('min_minutes', 0); ?>"> <?php esc_html_e('minutes played (0 = anything launched)', 'nh-games'); ?></td></tr>
      <tr><th><?php esc_html_e('"Now playing" window', 'nh-games'); ?></th><td><input type="number" min="1" max="720" style="width:6em" name="nhg_settings[now_hours]" value="<?php echo $v('now_hours', 48); ?>"> <?php esc_html_e('hours since last played', 'nh-games'); ?></td></tr>
      <tr><th><?php esc_html_e('Games per page', 'nh-games'); ?></th><td><input type="number" min="6" max="200" style="width:6em" name="nhg_settings[per_page]" value="<?php echo $v('per_page', 24); ?>"></td></tr>
      <tr><th><?php esc_html_e('Colours', 'nh-games'); ?></th><td><input type="color" name="nhg_settings[accent]" value="<?php echo $v('accent', '#FFCC00'); ?>"> <?php esc_html_e('accent', 'nh-games'); ?> &nbsp;
        <select name="nhg_settings[scheme]"><option value="dark" <?php selected(nhg_opt('scheme', 'dark'), 'dark'); ?>><?php esc_html_e('Dark site', 'nh-games'); ?></option><option value="light" <?php selected(nhg_opt('scheme'), 'light'); ?>><?php esc_html_e('Light site', 'nh-games'); ?></option></select></td></tr>
      <tr><th><?php esc_html_e('Widget CSS classes', 'nh-games'); ?></th><td><input class="regular-text code" name="nhg_settings[widget_class]" value="<?php echo $v('widget_class'); ?>" placeholder="wrapper classes"> <input class="code" name="nhg_settings[widget_title_class]" value="<?php echo $v('widget_title_class'); ?>" placeholder="title classes">
        <p class="description"><?php esc_html_e('Optional — add your theme\'s sidebar widget classes so the box matches your other widgets.', 'nh-games'); ?></p></td></tr>
      <tr><th><?php esc_html_e('On uninstall', 'nh-games'); ?></th><td><label><input type="checkbox" name="nhg_settings[wipe]" value="1" <?php echo $ck('wipe'); ?>> <?php esc_html_e('Also delete all synced games and your reviews', 'nh-games'); ?></label></td></tr>
    </table><?php submit_button(); ?></form>

    <form method="post"><?php wp_nonce_field('nhg_sync'); ?><button class="button button-primary" name="nhg_sync_now" value="1" <?php disabled(!nhg_enabled_platforms()); ?>><?php esc_html_e('Sync now', 'nh-games'); ?></button>
    <?php if (!empty($t['refresh'])): ?> <button class="button" name="nhg_psn_logout" value="1"><?php esc_html_e('Disconnect PlayStation', 'nh-games'); ?></button><?php endif; ?></form>
    <p><?php if (!empty($last['time'])) echo esc_html(sprintf(__('Last sync: %1$s (Steam %2$d, PlayStation %3$d).', 'nh-games'), $last['time'], $last['steam'], $last['psn'])) . ' '; esc_html_e('Syncs automatically every 3 hours.', 'nh-games'); ?></p>
    <h2><?php esc_html_e('Shortcodes', 'nh-games'); ?></h2>
    <p><code>[nh_games]</code> <?php esc_html_e('full page', 'nh-games'); ?> · <code>[nh_now_playing]</code> <?php esc_html_e('sidebar widget (also under Appearance → Widgets)', 'nh-games'); ?> · <code>[nh_game_more]</code> <?php esc_html_e('"more games" strip for a single-game template', 'nh-games'); ?> · <code>[nh_game_crumbs class="…"]</code></p>
    <style>.nhg-toggles label{display:inline-flex;gap:.35rem;align-items:center;margin-right:1.5rem;font-size:14px;padding:.6rem 1rem;border:1px solid #c3c4c7;border-radius:6px;background:#fff}</style>
    <script>document.querySelectorAll('[data-nhg-toggle]').forEach(c=>{const s=document.querySelector('[data-nhg-section="'+c.dataset.nhgToggle+'"]');const f=()=>s.style.display=c.checked?'':'none';c.addEventListener('change',f);f()})</script>
    </div>
<?php }
