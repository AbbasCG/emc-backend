# Users Management hub — analytics API

Read-only aggregates for the tabs of `/dashboard/super-admin/crud/users` (`?tab=analytics|activity|acquisition`).
Nothing here writes data, and no response contains an email, phone number, IP address or user agent.

## Endpoints

| Endpoint | Permission | Tab |
|---|---|---|
| `GET /api/admin/users-analytics/overview` | `users.analytics.view` | التحليلات |
| `GET /api/admin/users-analytics/activity` | `users.activity.view` | النشاط |
| `GET /api/admin/users-analytics/last-logins?page&per_page` | `users.activity.view` | النشاط (names + last login, paginated, ≤ 50) |
| `GET /api/admin/users-analytics/acquisition` | `users.acquisition.view` | الاستقطاب |

Common query: `from=YYYY-MM-DD&to=YYYY-MM-DD&compare=1`. No range = the last 30 days. `from`/`to` must be given together, `from ≤ to`,
`to` not in the future, at most 366 days. Presets (today, yesterday, 7/30/90 days) are resolved by the client into `from`/`to`.

* Day boundaries, the daily series and the weekday×hour matrix use the **reporting zone** (`config/users-analytics.php`,
  default `Europe/Amsterdam`); the database is UTC. DST changes inside the window are honoured.
* `compare=1` adds the immediately preceding window of the same length (`previous`, `change_pct` per KPI; `change_pct` is `null`
  when the previous value is 0).
* Responses are cached 5 minutes (`USERS_ANALYTICS_CACHE_TTL`, `0` disables) — they hold no per-viewer data. Rate limit: 30 requests
  per minute per user (`USERS_ANALYTICS_RATE_LIMIT`, named limiter `users_analytics`).

## Authorization

The route's `permission:` middleware, **per tab**. `super_admin` and `tech_admin` pass it (and hold the keys through
`Permissions::all()`); every other role needs an explicit grant in Access Control → الأدوار (group «المستخدمون»). No role has them by
default — `admin` can see the users table (`view_users`) but none of these tabs.

## Definitions (what the numbers mean)

| Figure | Definition |
|---|---|
| **المستخدمون الذين سجلوا الدخول** | distinct users with ≥ 1 `audit_logs` row `action = 'LOGIN'` in the window. **Not** "active users". |
| Login events | count of those rows. One row per successful password login (`AuthController::login`). |
| New / returning | account created inside the window / before it (account age; login history may be partial). |
| Users back on another day | logged-in users who came on ≥ 2 distinct local days in the window. |
| Inactive 30/60/90 | enabled accounts whose `last_login_at` is older than N days. **Cumulative**, not window-bound. |
| Never logged in | enabled accounts with `last_login_at` null. Cumulative. |
| Accounts | non-deleted users. Deleted accounts are reported once, in `by_status.deleted`. |
| Country | what people typed at registration (`country_code`, else `country`); `coverage_pct` says how much of the base answered. |

**Limits that the UI must state:** sessions that continue without a new login produce no event and are not counted; history starts at
`meta.login_history.first_event_at` (`partial = true` when the window starts earlier); `last_login_at` is never used to count logins.

## Acquisition — what is and is not available

* `self_reported`: accounts created in the window grouped by `how_did_you_hear_about_us` (trimmed, case-insensitive free text).
  Empty = `unknown`; it is **never** presented as «direct».
* `utm_visits`: page views that carried UTM parameters (`analytics_events`), only from visitors who accepted analytics cookies,
  and only on the page whose URL had the parameters. They are **visits, not accounts** (`linked_to_accounts: false`).
* `unavailable`: referral codes, direct registration, UTM→account link and **conversion rate** are declared unavailable with a reason.
  No conversion figure is computed anywhere. See `USER_ATTRIBUTION_TRACKING_DESIGN.md` for what would make them possible.

## Performance (measured, scratch MariaDB 10.4, synthetic data — not production)

Data: 200 000 users, 1 000 000 LOGIN rows over a year (≈ 2 700/day) among 1.5 M audit rows, 600 000 page views. Each report is a fixed
number of grouped queries (13–15 with `compare=1`) returning a handful of rows, so memory does not grow with the data
(a full-year window runs inside PHP's default 128 MB). Uncached wall time with `compare=1`:

| Window | overview | activity | acquisition |
|---|---|---|---|
| 7 days | 0.7 s | 0.6 s | 0.5 s |
| 30 days | 1.5 s | 2.0 s | 2.1 s |
| 90 days | 4.0 s | 7.5 s | 3.5 s |
| 365 days | 9–10 s | 22–30 s | 8–9 s |

Existing indexes are used (`audit_logs (action, created_at)`, `analytics_events (type, created_at)`). Candidate indexes were measured and
**rejected**: a covering `audit_logs (action, created_at, user_id, user_role)` made the role query slower (3.1 s vs 2.4 s — the cost is
`COUNT(DISTINCT)` and grouping, not row lookups), and `users (created_at)` / `users (last_login_at)` saved ≤ 30 ms on 200 000 users. **No
migration is included.** If production volume turns out to be of this order, the next step is a daily rollup table, not more indexes.

Every figure was cross-checked against direct SQL on MariaDB (events, unique users, per-day totals, heatmap, roles, new/returning,
accounts created/growth, inactivity) and against an independent PHP per-day recount using the zone's real offsets.

## Deployment

1. Deploy the code (no migration).
2. Create the permission rows so the keys can be granted from Access Control (idempotent; touches only these 3 rows and the
   super_admin / tech_admin roles — root passes the middleware without rows, so the API works before this step):
   ```bash
   php artisan db:seed --class=UsersAnalyticsPermissionSeeder
   php artisan optimize:clear
   ```
3. Grant a key to another role only on purpose (Access Control → الأدوار → المستخدمون).

## Read-only checks to run on production before relying on the figures (aggregates only, no personal data)

```sql
-- how far back login history goes, and how many events
SELECT MIN(created_at) AS first_login_event, COUNT(*) AS login_events FROM audit_logs WHERE action = 'LOGIN';
SELECT COUNT(*) AS archived_login_events, MIN(original_created_at) AS first_archived FROM audit_logs_archive WHERE action = 'LOGIN';
-- volume per month (≤ ~12 rows)
SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS logins FROM audit_logs WHERE action = 'LOGIN' GROUP BY month ORDER BY month;
-- table sizes
SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL) AS deleted,
       (SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL) AS with_last_login,
       (SELECT COUNT(*) FROM analytics_events) AS analytics_events,
       (SELECT COUNT(*) FROM analytics_events WHERE utm_source IS NOT NULL) AS events_with_utm;
-- completeness of the self-reported source
SELECT SUM(TRIM(COALESCE(how_did_you_hear_about_us, '')) <> '') AS answered, COUNT(*) AS total FROM users;
```
