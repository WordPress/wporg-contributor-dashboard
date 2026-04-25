# WordPress Contributor Dashboard

A WordPress plugin for tracking contributor activity and visualizing engagement across progression ladders.

## Project Overview

The WordPress Contributor Dashboard responds to long-standing community requests for better visibility into contributor journeys—how people join, participate, and grow across Make teams. Contribution activity, especially non-code work, is spread across many tools and systems, making it difficult to recognize contributors, understand engagement over time, and identify where support is needed.

### Contributor Ladder Framework

The dashboard maps contributor activity into a shared framework, such as Connect, Contribute, Engage, etc.

The ladder is behavior-based and describes patterns of participation over time. It does not rank contributors or imply that some contributions matter more than others. All contribution types and all contributors matter.

Uses existing WordPress.org accounts and activity data, does not display personal or sensitive information.

## Architecture

The plugin uses a single-tier data model: raw events are the source of truth, and every view aggregates them live in PHP on each request. No HTML caching, no precomputed tables.

```
Events (raw data, immutable)
    ↓ live aggregation per request
Dashboard views (routed by ?view)
```

### Events

Raw activity records stored in `wp_wporgcd_events`. Each event has:

- `event_id` — Unique identifier (for deduplication)
- `contributor_id` — Username
- `event_type` — Activity type
- `event_created_date` — When it occurred
- `contributor_created_date` — Optional registration date
- `event_data` — Optional JSON metadata

Events are immutable once imported.

### Dashboard

The frontend dashboard is composed of multiple **views** (Overview, Ladder, Cohorts, …) selected via the `?view=` query param. Each view renders its own section of the page live on every request, aggregating the `events` table per contributor in PHP on every load — so newly imported events show up immediately and ladder edits in [config.php](wp-content/plugins/wporg-cd/config.php) take effect without any rebuild step. A shared layout provides the sidebar navigation, page header, filter bar, and footer.

## Status Thresholds

- **Active** — Last activity within 30 days
- **Warning** — Last activity 30-90 days ago
- **Inactive** — No activity for 90+ days

Status is calculated live by each view, relative to the **reference date** (the newest event date), not "today". This handles delayed imports correctly in case we take more time to import new events.

## Reference Date

All time-based calculations use `wporgcd_reference_end_date` (stored in wp_options) instead of the current date. It's refreshed from `MAX(event_created_date)` after each successful event import (see [`includes/events/import.php`](wp-content/plugins/wporg-cd/includes/events/import.php)).

This ensures that if you import December events in January, the status calculations use December as "now", not January.

## Dashboard Features

- Total contributions, contributor count, average per contributor
- One-time contributor count and drop-off risk analysis
- Ladder funnel with active/warning counts and average days per step
- First contribution type distribution
- Insights: avg time to first contribution, active %, 10+ contributors, new contributors

### Views

Views are selected via the `?view=` query param and registered in `wporgcd_get_views()` ([frontend/dashboard.php](wp-content/plugins/wporg-cd/frontend/dashboard.php)). Each view is a small render function returning HTML; the shared layout wraps it with the left nav sidebar, page header, right filter sidebar (when the view declares filters), and footer.

| View | URL | Description | Data source |
|------|-----|-------------|-------------|
| Overview | `?view=overview` (default) | Stats grid, key insights, first contribution breakdown | `events` |
| Ladder | `?view=ladder` | Contributor progression funnel, live-computed per request | `events` |
| Cohorts | `?view=cohorts` | Placeholder for cohort analysis | — |

Add a new view by creating a file under `frontend/views/`, defining a `wporgcd_render_<id>_view($filters)` function, requiring it from the plugin bootstrap, and adding an entry to `wporgcd_get_views()` with an optional `filters` schema.

### Filter system

Filters are declared per view in the view registry and rendered in a right-hand sidebar as a standard HTML form with an explicit **Apply** button (no JavaScript). Supported types today:

| Type | Schema keys | URL params |
|------|-------------|------------|
| `date_range` | `type`, `label`, `column`, `default_days`, optional `default_start_offset_days`, optional `max_days` | `<id>_start`, `<id>_end` (both `YYYY-MM-DD`) |
| `checkbox` | `type`, `label`, `default` | `<id>=1` when on |

`date_range` extras:

- `default_start_offset_days` — when set, the default range **starts** at `reference_end - offset` and spans forward by `default_days` (capped at `reference_end`). Without it, the default range ends at `reference_end` and spans back by `default_days`.
- `max_days` — maximum allowed range width. Enforced on the resolver (clamping the end date if a wider range is submitted; the filter surfaces a `was_clamped` flag used to render a notice) and via the end input's `max` attribute.

`wporgcd_resolve_filters($view_key)` reads `$_GET`, validates, falls back to defaults, applies `max_days` clamping, and returns a typed array that's passed into the view's render function. Each view applies the filter values directly to its own `events`-table query — there is no shared SQL filter layer.

Current filters per view:

- **Overview** — `registered_date` (`date_range` on `events.contributor_created_date`, default: last 90 days starting one year ago, max 90-day range), `include_inactive` (`checkbox`, default: off — applied in PHP after aggregating events per contributor).
- **Ladder** — `registered_date` (same shape as Overview), `contribution_date` (`date_range` on `events.event_created_date`, default: last 365 days, max 365-day range), `include_inactive`.

### Query Params

- `?view=<id>` — Select a view (default `overview`)
- `?registered_date_start=YYYY-MM-DD&registered_date_end=YYYY-MM-DD` — User-registered-date filter (Overview, Ladder; max range: 90 days)
- `?contribution_date_start=YYYY-MM-DD&contribution_date_end=YYYY-MM-DD` — Contribution-date filter (Ladder; max range: 365 days)
- `?include_inactive=1` — Include inactive contributors (Overview, Ladder)

## Configuration

Event types and ladders are defined in [wp-content/plugins/wporg-cd/config.php](wp-content/plugins/wporg-cd/config.php) — plain PHP arrays returned from helper functions. Edit it to add, rename, or remove entries; ladders are evaluated in declaration order. Changes take effect on the next page load.

### Excluding event types from analytics

`wporgcd_get_excluded_event_types()` returns a list of slugs that should be treated as noise. Listed types are still imported and stored in the events table, but every analytics view filters them out via `wporgcd_get_event_type_filter_sql()`. The default is `[ 'updated_profile' ]` (auto-generated on every login, would otherwise distort engagement stats).

The helper compiles to an `event_type IN (...)` SQL fragment — a positive predicate that can use B-tree indexes, unlike the negated forms (`!=`, `NOT IN`) it replaces.

**Behavioral note for unknown event types:** because the helper builds an allow-list from `wporgcd_get_event_types()` minus the exclusion list, events whose `event_type` slug is **not** registered in `wporgcd_get_event_types()` will not appear in analytics views. To make a new event type count in views, register it in `wporgcd_get_event_types()`; to register but treat as noise, also add it to `wporgcd_get_excluded_event_types()`.

## Admin Interface

- **Contributors** — Recent events (last 30 days), link to public dashboard

## REST API

### Import Events
```
POST /wp-json/wporgcd/v1/events/import
```

Requires `manage_options` capability. Max 5,000 events per request.

**Request body:**
```json
{
  "events": [
    {
      "event_id": "unique-id",
      "contributor_id": "username",
      "contributor_created_date": "2024-01-15",
      "event_type": "support_reply",
      "event_created_date": "2024-06-20"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "imported": 100,
  "skipped": 5,
  "errors": []
}
```

## Get Involved

- **Slack**: [#contributor-dashboard](https://wordpress.slack.com/archives/C0AHJA81PDE)
- **Project**: [Handbook](https://make.wordpress.org/handbook/contributor-dashboard/)

## License

GPL-2.0-or-later
