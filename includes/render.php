<?php
/* Front end: assets, cards, shortcodes, widget, single-game view. */
if (!defined('ABSPATH')) exit;

/* ---------- Assets ---------- */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('nh-games', NHG_URL . 'assets/nh-games.css', [], NHG_VER);
    $acc = nhg_opt('accent', '#FFCC00');
    $vars = nhg_opt('scheme', 'dark') === 'light'
        ? '--nhg-text:#1d2327;--nhg-muted:#50575e;--nhg-surface:#fff;--nhg-surface-2:#f0f0f1;--nhg-border:#dcdcde;--nhg-on-accent:#111'
        : '--nhg-text:#e8ecf1;--nhg-muted:#9aa5b1;--nhg-surface:#151515;--nhg-surface-2:#222;--nhg-border:rgb(36,48,64);--nhg-on-accent:#111';
    wp_add_inline_style('nh-games', ".nhg-scope{--nhg-accent:$acc;$vars}");
}, 20);
// Load Font Awesome 6 only if the theme hasn't already.
add_action('wp_enqueue_scripts', function () {
    if (!apply_filters('nhg_load_fontawesome', true)) return;
    foreach (wp_styles()->queue as $h) if (preg_match('/font-?awesome|fontawesome|\bfa\b/i', $h . ' ' . (wp_styles()->registered[$h]->src ?? ''))) return;
    wp_enqueue_style('nhg-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css', [], '6.7.2');
}, 99);

/* ---------- Bits ---------- */
function nhg_stars($n) { $n = (int) $n; if (!$n) return ''; return '<span class="nhg-stars" title="' . esc_attr(sprintf(__('My rating %d/5', 'nh-games'), $n)) . '">' . str_repeat('<i class="fa-solid fa-star"></i>', $n) . str_repeat('<i class="fa-regular fa-star"></i>', 5 - $n) . '</span>'; }
function nhg_badge($now) {
    if (!$now) return '';
    return $now === 'live' ? '<span class="nhg-now live"><i class="fa-solid fa-circle"></i> ' . esc_html__('Live', 'nh-games') . '</span>' : '<span class="nhg-now"><i class="fa-solid fa-gamepad"></i> ' . esc_html__('Now playing', 'nh-games') . '</span>';
}
function nhg_section($title, $icon, $items, $fn, $cls = '') {
    if (!$items) return '';
    $o = '<section class="nhg-section ' . esc_attr($cls) . '"><h2 class="nhg-h2"><i class="' . esc_attr($icon) . '"></i> ' . esc_html($title) . ' <span class="nhg-count">' . count($items) . '</span></h2><div class="nhg-grid">';
    foreach ($items as $p) $o .= $fn($p);
    return $o . '</div></section>';
}

/* ---------- Cards ---------- */
function nhg_card($p, $size = 'md', $live = '') {
    $m = fn($k) => get_post_meta($p->ID, "_nhg_$k", true);
    [$ic] = nhg_pf($p);
    $cover = $m('cover') ?: (has_post_thumbnail($p) ? get_the_post_thumbnail_url($p, 'medium') : '');
    $alt = $m('cover_alt');
    $now = nhg_is_now($p, $live);
    $pct = $m('pct');
    $plat = (int) (($m('tro') ?: [])['platinum'] ?? 0);
    $line = implode(' · ', array_filter([$m('device'), nhg_hours($m('minutes'))]));
    ob_start(); ?>
    <a class="nhg-card nhg-<?php echo $size; ?>" href="<?php echo esc_url(get_permalink($p)); ?>" data-p="<?php echo esc_attr($m('platform')); ?>" data-last="<?php echo (int) $m('last'); ?>" data-min="<?php echo (int) $m('minutes'); ?>" data-pct="<?php echo $pct === '' ? -1 : (int) $pct; ?>" data-t="<?php echo esc_attr(strtolower($p->post_title)); ?>">
      <span class="nhg-poster"><?php if ($cover): ?><img loading="lazy" src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($p->post_title); ?>"<?php if ($alt) echo ' onerror="this.onerror=null;this.src=\'' . esc_url($alt) . '\'"'; ?>><?php else: ?><i class="fa-solid fa-gamepad"></i><?php endif; ?><?php if ($size === 'md') echo nhg_badge($now); ?></span>
      <span class="nhg-meta">
        <span class="nhg-t"><?php echo esc_html($p->post_title); ?></span>
        <?php if ($size === 'sm' && $now) echo nhg_badge($now); ?>
        <span class="nhg-s"><i class="<?php echo $ic; ?>"></i> <?php echo esc_html($line); ?></span>
        <?php if ($m('last')): ?><span class="nhg-s"><i class="fa-regular fa-clock"></i> <?php echo esc_html(nhg_ago($m('last'))); ?></span><?php endif; ?>
        <?php if ($pct !== '' && (int) $m('ach_total')): ?>
          <span class="nhg-bar" title="<?php echo (int) $m('ach_got') . ' / ' . (int) $m('ach_total'); ?>"><span style="width:<?php echo (int) $pct; ?>%"></span></span>
          <span class="nhg-s nhg-ach"><i class="fa-solid fa-trophy"></i> <?php echo (int) $pct; ?>% · <?php echo (int) $m('ach_got') . '/' . (int) $m('ach_total'); ?><?php if ($plat) echo ' <i class="fa-solid fa-medal nhg-plat" title="Platinum"></i>'; ?></span>
        <?php endif; ?>
      </span>
    </a>
    <?php return nhg_tight(ob_get_clean());
}
function nhg_wide_card($p, $live = '') {
    $m = fn($k) => get_post_meta($p->ID, "_nhg_$k", true);
    [$ic, $pl] = nhg_pf($p);
    $steam = $m('platform') === 'steam';
    $img = $steam ? ($m('cover_alt') ?: $m('cover')) : ($m('cover') ?: (has_post_thumbnail($p) ? get_the_post_thumbnail_url($p, 'medium') : ''));
    $bg = $steam ? $m('hero') : $m('cover');
    $tot = (int) $m('ach_total'); $got = (int) $m('ach_got'); $pct = (int) $m('pct');
    $plat = (int) (($m('tro') ?: [])['platinum'] ?? 0);
    $o = '<a class="nhg-wide' . ($steam ? ' is-steam' : ' is-psn') . '" href="' . esc_url(get_permalink($p)) . '"' . ($bg ? ' style="--bg:url(' . esc_url($bg) . ')"' : '') . '>';
    $o .= '<span class="nhg-wide-img">' . ($img ? '<img loading="lazy" src="' . esc_url($img) . '" alt="">' : '<i class="fa-solid fa-gamepad"></i>') . nhg_badge(nhg_is_now($p, $live)) . '</span>';
    $o .= '<span class="nhg-wide-body"><span class="nhg-wide-t">' . esc_html($p->post_title) . '</span>';
    $o .= '<span class="nhg-wide-s"><i class="' . $ic . '"></i> ' . esc_html(implode(' · ', array_filter([$m('device') ?: $pl, nhg_hours($m('minutes')) ? sprintf(__('%s on record', 'nh-games'), nhg_hours($m('minutes'))) : '']))) . '</span>';
    if ($m('last')) $o .= '<span class="nhg-wide-s"><i class="fa-regular fa-clock"></i> ' . esc_html(sprintf(__('last played %1$s · %2$s', 'nh-games'), date_i18n('j M', (int) $m('last')), nhg_ago($m('last')))) . '</span>';
    if ($tot) $o .= '<span class="nhg-wide-ach"><span>' . esc_html($steam ? __('Achievement progress', 'nh-games') : __('Trophy progress', 'nh-games')) . ($plat ? ' <i class="fa-solid fa-medal nhg-plat" title="Platinum"></i>' : '') . '</span><b>' . $got . ' / ' . $tot . '</b></span><span class="nhg-bar"><span style="width:' . $pct . '%"></span></span>';
    return $o . '</span></a>';
}

/* ---------- Sidebar widget ---------- */
function nhg_widget_html($n = 3) {
    if (!nhg_enabled_platforms()) return '';
    $q = get_queried_object();
    if (is_singular() && $q instanceof WP_Post && has_shortcode($q->post_content, 'nh_games')) return ''; // the games page already shows it all
    $live = nhg_steam_live();
    $now = nhg_now($n, $live);
    $list = $now ?: nhg_q('', $n);
    $o = '<section class="nhg-scope nhg-widget ' . esc_attr(nhg_opt('widget_class')) . '"><h2 class="nhg-widget-t ' . esc_attr(nhg_opt('widget_title_class')) . '"><i class="fa-solid fa-gamepad" aria-hidden="true"></i><span>' . esc_html($now ? __('Now playing', 'nh-games') : __('Recently played', 'nh-games')) . '</span></h2>';
    foreach ($list as $p) $o .= nhg_card($p, 'sm', $live);
    if (!$list) $o .= '<p class="nhg-empty">' . esc_html__('Controller\'s resting.', 'nh-games') . '</p>';
    $s = nhg_stats();
    if ($s['games']) $o .= '<p class="nhg-sub"><i class="fa-solid fa-chart-simple"></i> ' . esc_html(sprintf(__('%1$s games · %2$s h logged', 'nh-games'), number_format_i18n($s['games']), number_format_i18n($s['hours']))) . '</p>';
    return $o . '<a class="nhg-more-link" href="' . esc_url(nhg_opt('page', home_url('/playing/'))) . '">' . esc_html__('Everything I\'ve played', 'nh-games') . ' <i class="fa-solid fa-arrow-right"></i></a></section>';
}
add_shortcode('nh_now_playing', fn($a) => nhg_widget_html((int) (shortcode_atts(['count' => 3], $a)['count'])));
add_action('widgets_init', function () { register_widget('NHG_Widget'); });
class NHG_Widget extends WP_Widget {
    function __construct() { parent::__construct('nhg_now_playing', 'NH Games — Now playing', ['description' => __('Your current / recent games from Steam and PlayStation.', 'nh-games')]); }
    function widget($args, $inst) { $h = nhg_widget_html((int) ($inst['count'] ?? 3) ?: 3); if ($h) echo $args['before_widget'] . $h . $args['after_widget']; }
    function form($inst) { $c = (int) ($inst['count'] ?? 3) ?: 3; printf('<p><label>%s <input type="number" min="1" max="10" name="%s" value="%d" style="width:4em"></label></p>', esc_html__('Games to show:', 'nh-games'), $this->get_field_name('count'), $c); }
    function update($n, $o) { return ['count' => min(10, max(1, (int) $n['count']))]; }
}

/* ---------- Full page ---------- */
add_shortcode('nh_games', function () {
    $pfs = nhg_enabled_platforms();
    if (!$pfs) return current_user_can('manage_options') ? '<p>' . wp_kses_post(sprintf(__('NH Games: turn on Steam or PlayStation in <a href="%s">Settings → NH Games</a>.', 'nh-games'), admin_url('options-general.php?page=nh-games'))) . '</p>' : '';
    $live = nhg_steam_live();
    $s = nhg_stats();
    $both = count($pfs) > 1;
    $o = '<div class="nhg-scope nhg-page"><div class="nhg-stats">'
        . '<div><i class="fa-solid fa-gamepad"></i><b>' . number_format_i18n($s['games']) . '</b><span>' . esc_html__('games played', 'nh-games') . '</span></div>'
        . '<div><i class="fa-regular fa-clock"></i><b>' . number_format_i18n($s['hours']) . '</b><span>' . esc_html__('hours logged', 'nh-games') . '</span></div>';
    if (nhg_on('steam')) $o .= '<div><i class="fa-brands fa-steam"></i><b>' . number_format_i18n($s['ach']) . '</b><span>' . esc_html(sprintf(__('Steam achievements · %d games', 'nh-games'), $s['steam'])) . '</span></div>';
    if (nhg_on('psn')) $o .= '<div><i class="fa-brands fa-playstation"></i><b>' . number_format_i18n($s['tro']) . '</b><span>' . esc_html(sprintf(__('PSN trophies · %d platinum', 'nh-games'), $s['plat'])) . '</span></div>';
    $o .= '</div>';
    $now = nhg_now(8, $live);
    if (count($now) < 4) { $seen = wp_list_pluck($now, 'ID'); foreach (nhg_q('', 8) as $p) { if (count($now) >= 4) break; if (!in_array($p->ID, $seen)) $now[] = $p; } }
    if ($now) {
        $o .= '<section class="nhg-section nhg-rail-wrap"><h2 class="nhg-h2"><i class="fa-solid fa-circle-play"></i> ' . esc_html(nhg_is_now($now[0], $live) ? __('Now playing', 'nh-games') : __('Recently played', 'nh-games')) . '</h2>'
            . '<div class="nhg-rail-nav"><button type="button" data-d="-1" aria-label="' . esc_attr__('Scroll left', 'nh-games') . '"><i class="fa-solid fa-chevron-left"></i></button><button type="button" data-d="1" aria-label="' . esc_attr__('Scroll right', 'nh-games') . '"><i class="fa-solid fa-chevron-right"></i></button></div><div class="nhg-rail">';
        foreach ($now as $p) $o .= nhg_wide_card($p, $live);
        $o .= '</div></section>';
    }
    $o .= '<div class="nhg-tools">';
    if ($both) $o .= '<button data-f="all" class="on">' . esc_html__('All', 'nh-games') . '</button><button data-f="steam"><i class="fa-brands fa-steam"></i> Steam</button><button data-f="psn"><i class="fa-brands fa-playstation"></i> PlayStation</button>';
    $o .= '<select class="nhg-sort" aria-label="' . esc_attr__('Sort', 'nh-games') . '"><option value="last">' . esc_html__('Recently played', 'nh-games') . '</option><option value="min">' . esc_html__('Most played', 'nh-games') . '</option><option value="pct">' . esc_html__('Completion', 'nh-games') . '</option><option value="t">A–Z</option></select>'
        . '<input class="nhg-find" type="search" placeholder="' . esc_attr__('Find a game…', 'nh-games') . '" aria-label="' . esc_attr__('Find a game', 'nh-games') . '"></div>';
    $o .= '<div class="nhg-all">' . nhg_section(__('Played', 'nh-games'), 'fa-solid fa-list', nhg_q(), fn($p) => nhg_card($p, 'md', $live)) . '</div><nav class="nhg-pager" aria-label="' . esc_attr__('Games pages', 'nh-games') . '"></nav>';
    $prof = [];
    if (nhg_on('psn') && ($id = nhg_opt('psn_id'))) $prof[] = '<a href="https://profile.playstation.com/' . rawurlencode($id) . '" target="_blank" rel="noopener"><i class="fa-brands fa-playstation"></i> ' . esc_html($id) . '</a>';
    if (nhg_on('steam') && preg_match('/^\d{17}$/', $id = nhg_opt('steam_id'))) $prof[] = '<a href="https://steamcommunity.com/profiles/' . $id . '" target="_blank" rel="noopener"><i class="fa-brands fa-steam"></i> ' . esc_html__('Steam profile', 'nh-games') . '</a>';
    $l = get_option('nhg_last_sync', []);
    if (!empty($l['time'])) $prof[] = '<i class="fa-solid fa-rotate"></i> ' . esc_html(sprintf(__('synced %s ago', 'nh-games'), human_time_diff(strtotime($l['time']), current_time('timestamp'))));
    if ($prof) $o .= '<p class="nhg-foot">' . implode(' · ', $prof) . '</p>';
    $o .= '<script>(()=>{const r=document.currentScript.closest(".nhg-page")||document.querySelector(".nhg-page");if(!r)return;const per=' . (int) nhg_opt('per_page', 24) . ';'
        . 'const rl=r.querySelector(".nhg-rail");r.querySelectorAll(".nhg-rail-nav button").forEach(b=>b.onclick=()=>rl&&rl.scrollBy({left:+b.dataset.d*rl.clientWidth*.85,behavior:"smooth"}));'
        . 'const g=r.querySelector(".nhg-all .nhg-grid");if(!g)return;const cards=[...g.querySelectorAll(".nhg-card")];const pg=r.querySelector(".nhg-pager");let f="all",q="",page=1;'
        . 'const apply=s=>{const vis=cards.filter(c=>(f==="all"||c.dataset.p===f)&&(!q||c.dataset.t.includes(q)));const pages=Math.max(1,Math.ceil(vis.length/per));page=Math.min(page,pages);'
        . 'cards.forEach(c=>c.style.display="none");vis.slice((page-1)*per,page*per).forEach(c=>c.style.display="");const b=r.querySelector(".nhg-all .nhg-count");if(b)b.textContent=vis.length;'
        . 'let h="";if(pages>1){const btn=(p,l,d,on)=>`<button type="button" data-p="${p}"${d?" disabled":""}${on?" class=on":""}>${l}</button>`;h+=btn(page-1,"<i class=\'fa-solid fa-chevron-left\'></i>",page===1);'
        . 'let last=0;for(let i=1;i<=pages;i++){if(i===1||i===pages||Math.abs(i-page)<=1){if(i-last>1)h+="<span>…</span>";h+=btn(i,i,false,i===page);last=i}}'
        . 'h+=btn(page+1,"<i class=\'fa-solid fa-chevron-right\'></i>",page===pages);h+=`<small>${(page-1)*per+1}–${Math.min(page*per,vis.length)} / ${vis.length}</small>`}pg.innerHTML=h;'
        . 'if(s)r.querySelector(".nhg-tools").scrollIntoView({behavior:"smooth",block:"start"})};'
        . 'pg.onclick=e=>{const b=e.target.closest("button[data-p]");if(!b||b.disabled)return;page=+b.dataset.p;apply(true)};'
        . 'r.querySelectorAll(".nhg-tools button").forEach(b=>b.onclick=()=>{r.querySelectorAll(".nhg-tools button").forEach(x=>x.classList.remove("on"));b.classList.add("on");f=b.dataset.f;page=1;apply()});'
        . 'r.querySelector(".nhg-find").oninput=e=>{q=e.target.value.trim().toLowerCase();page=1;apply()};'
        . 'r.querySelector(".nhg-sort").onchange=e=>{const k=e.target.value;cards.sort((a,b)=>k==="t"?a.dataset.t.localeCompare(b.dataset.t):(+b.dataset[k])-(+a.dataset[k])).forEach(c=>g.appendChild(c));page=1;apply()};apply()})()</script>';
    return $o . '</div>';
});

/* ---------- Single-game helpers (for a custom single-nh_game template) ---------- */
add_shortcode('nh_game_crumbs', function ($a) {
    $a = shortcode_atts(['class' => 'nhg-crumbs'], $a);
    $page = nhg_opt('page', home_url('/playing/'));
    $slug = trim((string) wp_parse_url($page, PHP_URL_PATH), '/') ?: 'games';
    return '<p class="' . esc_attr($a['class']) . '"><span class="pr">$ cd</span> <a href="' . esc_url(home_url('/')) . '">~</a><span class="s">/</span><a href="' . esc_url($page) . '">' . esc_html($slug) . '</a><span class="s">/</span><span class="f">' . esc_html(get_post_field('post_name', get_queried_object_id())) . '</span></p>';
});
add_shortcode('nh_game_more', function () {
    $id = get_queried_object_id();
    if (get_post_type($id) !== 'nh_game') return '';
    $pf = get_post_meta($id, '_nhg_platform', true);
    $more = array_slice(array_values(array_filter(nhg_q($pf, 9), fn($p) => $p->ID !== $id)), 0, 8);
    if (!$more) return '';
    $live = nhg_steam_live();
    $o = '<section class="nhg-scope nhg-more"><h2 class="nhg-h2"><i class="' . nhg_pf(get_post($id))[0] . '"></i> ' . esc_html(sprintf(__('More I\'ve played on %s', 'nh-games'), $pf === 'steam' ? 'Steam' : 'PlayStation')) . '</h2><div class="nhg-rail">';
    foreach ($more as $p) $o .= nhg_card($p, 'md', $live);
    return $o . '</div><a class="nhg-more-link" href="' . esc_url(nhg_opt('page', home_url('/playing/'))) . '"><i class="fa-solid fa-arrow-left"></i> ' . esc_html__('All games', 'nh-games') . '</a></section>';
});

/* ---------- Single game view ---------- */
add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') === 'core/post-featured-image' && is_singular('nh_game')) return '';
    return $html;
}, 10, 2);
add_filter('post_thumbnail_html', fn($h) => is_singular('nh_game') && in_the_loop() ? '' : $h);
add_filter('the_content', function ($c) {
    if (!is_singular('nh_game') || !in_the_loop() || !is_main_query()) return $c;
    $p = get_post();
    $m = fn($k) => get_post_meta($p->ID, "_nhg_$k", true);
    [$ic, $pl] = nhg_pf($p);
    $steam = $m('platform') === 'steam';
    $tro = $m('tro') ?: [];
    $trophies = '';
    foreach (['platinum' => 'pt', 'gold' => 'au', 'silver' => 'ag', 'bronze' => 'cu'] as $k => $cl) if (!empty($tro[$k])) $trophies .= '<span class="nhg-tr ' . $cl . '" title="' . ucfirst($k) . '"><i class="fa-solid fa-trophy"></i> ' . (int) $tro[$k] . '</span> ';
    $rows = [
        __('Platform', 'nh-games') => $pl . ($m('device') ? ' · ' . $m('device') : ''),
        __('Playtime', 'nh-games') => nhg_hours($m('minutes')),
        __('Last played', 'nh-games') => $m('last') ? date_i18n('j M Y', (int) $m('last')) . ' (' . nhg_ago($m('last')) . ')' : '',
        __('First played', 'nh-games') => $m('first') ? date_i18n('j M Y', (int) $m('first')) : '',
        __('Sessions', 'nh-games') => (int) $m('plays') ? number_format_i18n((int) $m('plays')) : '',
        ($steam ? __('Achievements', 'nh-games') : __('Trophies', 'nh-games')) => (int) $m('ach_total') ? (int) $m('ach_got') . ' / ' . (int) $m('ach_total') . ' (' . (int) $m('pct') . '%)' : '',
    ];
    $bg = $m('hero') ?: $m('cover');
    $cover = $m('cover') ?: (has_post_thumbnail($p) ? get_the_post_thumbnail_url($p, 'large') : '');
    $o = '<div class="nhg-scope nhg-hero"' . ($bg ? ' style="--bg:url(' . esc_url($bg) . ')"' : '') . '><div class="nhg-hero-in">';
    if ($cover) $o .= '<img class="nhg-hero-img" src="' . esc_url($cover) . '" alt=""' . ($m('cover_alt') ? ' onerror="this.onerror=null;this.src=\'' . esc_url($m('cover_alt')) . '\'"' : '') . '>';
    $o .= '<div class="nhg-hero-body">' . nhg_badge(nhg_is_now($p, nhg_steam_live())) . nhg_stars($m('rating')) . ($p->post_excerpt ? '<p class="nhg-blurb">' . esc_html($p->post_excerpt) . '</p>' : '');
    if ((int) $m('ach_total')) $o .= '<span class="nhg-bar lg"><span style="width:' . (int) $m('pct') . '%"></span></span>';
    if ($trophies) $o .= '<p class="nhg-trs">' . $trophies . '</p>';
    $o .= '<dl class="nhg-dl">';
    foreach ($rows as $k => $v) if ($v) $o .= '<dt>' . esc_html($k) . '</dt><dd>' . esc_html($v) . '</dd>';
    $o .= '</dl>' . ($m('link') ? '<a class="nhg-btn" target="_blank" rel="noopener" href="' . esc_url($m('link')) . '"><i class="' . $ic . '"></i> ' . esc_html($steam ? __('View on Steam', 'nh-games') : __('View on PlayStation Store', 'nh-games')) . '</a>' : '') . '</div></div></div>';
    return $o . (trim($c) ? '<h3 class="nhg-h3"><i class="fa-solid fa-pen-nib"></i> ' . esc_html__('My take', 'nh-games') . '</h3><div class="nhg-review">' . $c . '</div>' : '');
});
