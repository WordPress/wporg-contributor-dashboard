# WP.org Contributor Dashboard

A WordPress plugin for tracking contributor activity and visualizing engagement across progression ladders.

## Project Overview

The Contributor Dashboard Pilot responds to long-standing community requests for better visibility into contributor journeys—how people join, participate, and grow across Make teams. Contribution activity, especially non-code work, is spread across many tools and systems, making it difficult to recognize contributors, understand engagement over time, and identify where support is needed.

### Contributor Ladder Framework

The dashboard maps contributor activity into a shared framework, such as Connect, Contribute, Engage, etc.

The ladder is behavior-based and describes patterns of participation over time. It does not rank contributors or imply that some contributions matter more than others. All contribution types and all contributors matter.

Uses existing WordPress.org accounts and activity data, does not display personal or sensitive information.

## Architecture

The plugin uses a three-tier data model where each layer caches the computation of the previous:

```
Events (raw data)
    ↓ profile generation
Profiles (aggregated per-user)
    ↓ wporgcd_profiles_generated action
Dashboard HTML (pre-rendered, cached)
```

### Tier 1: Events

Raw activity records stored in `wp_wporgcd_events`. Each event has:

- `event_id` — Unique identifier (for deduplication)
- `contributor_id` — Username
- `event_type` — Activity type
- `event_created_date` — When it occurred
- `contributor_created_date` — Optional registration date
- `event_data` — Optional JSON metadata

Events are immutable once imported. New event types are auto-created during import.

### Tier 2: Profiles

Aggregated data per contributor in `wp_wporgcd_profiles`. Computed from events via heartbeat-based queue:

- Event counts by type
- Current ladder stage
- Activity status (active/warning/inactive)
- Ladder journey history
- First/last activity dates

Profile generation runs asynchronously. When complete, fires `wporgcd_profiles_generated`.

### Tier 3: Dashboard

The complete frontend HTML (including CSS) is pre-generated and stored in `wp_options` as cache entries. Cache is regenerated only when `wporgcd_profiles_generated` fires. Frontend requests serve the cached HTML directly—no database queries on page load.

## Status Thresholds

- **Active** — Last activity within 30 days
- **Warning** — Last activity 30-90 days ago
- **Inactive** — No activity for 90+ days

Status is calculated during profile generation relative to the **reference date** (the newest event date), not "today". This handles delayed imports correctly in case we take more time to import new events.

## Reference Date

All time-based calculations use `wporgcd_reference_end_date` (stored in wp_options) instead of the current date. This is set automatically from `MAX(event_created_date)` when profile generation starts.

This ensures that if you import December events in January, the status calculations use December as "now", not January.

## Dashboard Features

- Total contributions, contributor count, average per contributor
- One-time contributor count and drop-off risk analysis
- Ladder funnel with active/warning counts and average days per step
- Year-over-year comparison (last 90 days vs same period last year)
- First contribution type distribution
- Insights: avg time to first contribution, active %, 10+ contributors, new contributors

### Date Range Filters
- Last 30 days
- Last 90 days
- Last 6 months
- Last year
- All time

### Admin Options
- `?preview` — Bypass cache for testing
- `?all` — Include inactive contributors

## Admin Interface

- **Contributors** — Link to public dashboard
- **Event Types** — Define event types (CRUD, JSON import/export)
- **Ladders** — Define progression ladders with requirements
- **Profiles** — Start/stop profile generation, view stats
- **Import** — CSV import, clear events

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

## CSV Import Format

```csv
ID,user_id,user_registered,event_type,date_recorded
unique-id-123,username,2024-01-15,support_reply,2024-06-20
```

- Batch size: 2,000 rows
- Header row auto-detected
- Uses INSERT IGNORE for deduplication
- Files stored in `wp-content/uploads/wpcd-imports/`

## Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `wporgcd_profiles_generated` | Action | Fires after profile generation completes; triggers dashboard cache rebuild |
| `wporgcd_process_queue` | Action | Process queue work (priority 10: import, 20: profiles) |
| `wporgcd_has_pending_work` | Filter | Report pending work for heartbeat |

## Queue System

The plugin uses a heartbeat-based AJAX queue instead of WP-Cron for more responsive processing:
- Polls every 2 seconds while admin pages are open
- Rate-limited to prevent overlapping runs
- Modules hook via `wporgcd_process_queue` action

## Get Involved

- **Slack**: [#contributor-dashboard](https://wordpress.slack.com/archives/C0AHJA81PDE)
- **Project**: [Handbook](https://make.wordpress.org/handbook/contributor-dashboard/)

## License

GPL-2.0-or-later
