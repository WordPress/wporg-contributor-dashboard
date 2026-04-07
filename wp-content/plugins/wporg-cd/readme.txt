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

* Import contributor events from CSV files or REST API
* Define custom event types and progression ladders
* Automatic status tracking (active/warning/inactive)
* Pre-rendered dashboard for fast page loads
* Heartbeat-based background processing
* Year-over-year comparisons and insights
* Ladder funnel visualization

= Architecture =

The plugin uses a three-tier data model where each layer caches the computation of the previous:

1. **Events** - Raw activity records (immutable after import)
2. **Profiles** - Aggregated per-user data computed asynchronously
3. **Dashboard** - Pre-rendered HTML cached in wp_options

= Status Thresholds =

* **Active** — Last activity within 30 days
* **Warning** — Last activity 30-90 days ago
* **Inactive** — No activity for 90+ days

Status is calculated relative to the reference date (newest event date), not "today", which handles delayed imports correctly.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wporg-cd/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to Contributors > Event Types to define your event types
4. Navigate to Contributors > Ladders to define progression ladders with requirements
5. Use Contributors > Import to import events from CSV
6. Navigate to Contributors > Profiles to generate contributor profiles

== Frequently Asked Questions ==

= What CSV format is expected? =

The CSV should have the following columns:
`ID,user_id,user_registered,event_type,date_recorded`

Batch size is 2,000 rows, and header row is auto-detected.

= How is status calculated? =

Status is calculated during profile generation relative to the reference date (the newest event date), not "today". This handles delayed imports correctly.

= How do I regenerate profiles? =

Navigate to Contributors > Profiles and click "Start Generation". This runs asynchronously via a heartbeat-based queue system.

= Is there a REST API? =

Yes. POST to `/wp-json/wporgcd/v1/events/import` with events array. Requires `manage_options` capability. Max 5,000 events per request.

= What does the Contributor Ladder represent? =

The ladder (Connect → Contribute → Engage → Perform → Lead) is behavior-based and describes patterns of participation over time. It does not rank contributors or imply that some contributions matter more than others.

== Changelog ==

= 1.0.0 =
* Initial release