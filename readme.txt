=== NH Games — Steam & PlayStation Shelf ===
Contributors: mohdneotech
Tags: steam, playstation, games, gaming, widget
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show the games you've played on Steam and/or PlayStation — playtime, achievements, trophies, a "Now playing" widget and a full games page.

== Description ==

Enable only the platforms you use and enter their details:

* Steam — official Steam Web API key + your profile.
* PlayStation — one-time NPSSO login (unofficial PSN mobile API; exchanged for a refresh token and never stored).

Includes a paginated [nh_games] page with filters, search and a horizontal "Now playing" rail, a [nh_now_playing] sidebar widget (also a classic widget), and a page per game with your own review and rating.

== Installation ==

1. Upload and activate.
2. Settings → NH Games: tick Steam and/or PlayStation and fill in the details.
3. Add [nh_games] to a page and [nh_now_playing] to your sidebar.

== Frequently Asked Questions ==

= Steam says no games / achievements are hidden =
Steam → Edit Profile → Privacy Settings: set "My profile" and "Game details" to Public.

= PlayStation stopped syncing =
The PSN login lasts about two months. Paste a fresh NPSSO in Settings → NH Games.

= How do I hide a game? =
Set it to Draft under Games. Syncing never republishes it.

== Changelog ==

= 1.0.0 =
* First public release: Steam + PlayStation sync, platform toggles, page, widget, single-game view, pagination, themeable colours.
