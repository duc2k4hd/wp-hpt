=== Staff Tracking ===
Version: 1.0.0
Requires at least: WordPress 6.0
Requires PHP: 8.0
License: GPLv2+

Lightweight internal staff traffic tracking plugin. Tracks sessions, identifies employees, exports CSV reports.

== Quick Install ==

1. Upload the `staff-tracking` folder to `/wp-content/plugins/`.
2. In WP Admin → Plugins → activate "Staff Tracking".
3. The plugin creates the database table automatically on activation.
4. Visit WP Admin → Staff Tracking to view the dashboard.

== Database Table ==

Created automatically on activation:

  wp_staff_tracking (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  VARCHAR(64),
    ip          VARCHAR(45),
    user_agent  TEXT,
    url         TEXT,
    page_path   VARCHAR(512),
    duration    SMALLINT UNSIGNED,
    user_name   VARCHAR(150),
    team        VARCHAR(150),
    is_valid    TINYINT(1),
    created_at  DATETIME
  )

Indexes: created_at, user_name, team, ip, session_id

== REST Endpoints ==

  POST /wp-json/tracking/v1/log         → frontend tracker (nonce required)
  GET  /wp-json/staff/v1/logs           → paginated logs   (admin only)
  GET  /wp-json/staff/v1/kpi            → KPI summary      (admin only)
  GET  /wp-admin/admin-post.php?action=st_export_csv  → CSV download (admin only)

== File Structure ==

  staff-tracking/
  ├── staff-tracking.php           Main plugin file
  ├── includes/
  │   ├── class-database.php       Schema, insert, read, KPI queries
  │   ├── class-rest-api.php       All REST route registration & handlers
  │   ├── class-admin.php          WP Admin pages & asset enqueue
  │   └── class-export.php         CSV streaming export
  └── assets/
      ├── js/tracker.js            Frontend tracking (deferred, ~4 KB)
      ├── css/tracker.css          Floating button + modal styles
      └── admin/
          ├── admin.js             Dashboard JS (AJAX, pagination, sort)
          └── admin.css            Admin page styles

== Performance Notes ==

- tracker.js loads with `strategy: defer` — zero render-blocking.
- Only ONE request is sent per valid session (≥ 30 s active time).
- Admin KPI results are cached in transients for 5 minutes.
- All admin table queries use LIMIT/OFFSET — no full-table dumps.
- Indexed columns: created_at, user_name, team, ip.

== Security ==

- All inputs sanitised via WordPress sanitisation functions.
- REST endpoints verify wp_rest nonce (X-WP-Nonce header).
- Admin export verifies check_admin_referer().
- Rate limit: 1 session per IP per 60 seconds.
- Bot detection via User-Agent pattern matching.
- All output escaped with esc_html(), esc_attr(), esc_url().

== Customisation ==

  // Change rate-limit window (seconds) in staff-tracking.php:
  define( 'ST_RATE_LIMIT', 60 );

  // Change valid-session threshold in staff-tracking.php → st_enqueue_frontend():
  'threshold' => 30,   // seconds

== Uninstall ==

Deactivate the plugin. To remove the database table manually:
  DROP TABLE IF EXISTS `wp_staff_tracking`;

== Changelog ==

= 1.0.0 =
* Initial release.
