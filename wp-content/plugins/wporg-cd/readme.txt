=== WordPress Contributor Dashboard ===
Contributors: wordpressdotorg
Tags: contributors, dashboard, analytics, community
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin for tracking contributor activity and visualizing engagement across progression ladders.

== Description ==

The WordPress Contributor Dashboard responds to long-standing community requests for better visibility into contributor journeys—how people join, participate, and grow across Make teams.

= Contributor Ladder Framework =

The dashboard maps contributor activity into a shared framework.

The ladder is behavior-based and describes patterns of participation over time. It does not rank contributors or imply that some contributions matter more than others.

= Features =

* Import contributor events via REST API
* Configure event types and progression ladders in PHP
* Automatic status tracking (active/warning/inactive)
* Ladder funnel visualization

= Architecture =

The plugin is a single-tier model: raw events are the source of truth, and every dashboard view aggregates them live in PHP on each request. Events are immutable after import, and ladder placement is recomputed on every page load — there is no precomputed profile table or background queue.

= Status Thresholds =

* **Active** — Last activity within 30 days
* **Warning** — Last activity 30-90 days ago
* **Inactive** — No activity for 90+ days

Status is calculated relative to the reference date (newest event date), not "today", which handles delayed imports correctly.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wporg-cd/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Edit `wp-content/plugins/wporg-cd/config.php` to define event types and ladders
4. Send events to `/wp-json/wporgcd/v1/events/import` (see REST API below)
5. Visit the front-end dashboard or **Contributors** in wp-admin

== Frequently Asked Questions ==

= How is status calculated? =

Status is calculated live by each view, relative to the reference date (the newest event date), not "today". This handles delayed imports correctly.

= How do I change the ladders? =

Edit the array returned by `wporgcd_get_ladders()` in `wp-content/plugins/wporg-cd/config.php`. Changes take effect on the next page load — there's nothing to regenerate.

= Is there a REST API? =

Yes. POST to `/wp-json/wporgcd/v1/events/import` with an events array. Requires `manage_options` capability. Max 5,000 events per request.

= What does the Contributor Ladder represent? =

The ladder (Connect → Contribute → Engage → Perform → Lead) is behavior-based and describes patterns of participation over time. It does not rank contributors or imply that some contributions matter more than others.

== Changelog ==

= 1.0.0 =
* Initial release