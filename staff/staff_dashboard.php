<?php
session_start();
include_once '../config.php';

// Redirect non-staff roles (e.g. student) before any HTML output — doing
// this only inside staff_sidebar.php's own guard is too late here, since
// this page already prints <head>/<style> before including it, and a
// header() redirect after output has started fails silently (with a
// "headers already sent" warning) instead of actually redirecting.
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: ../login");
    exit();
}
guard_password_change('staff_change_password');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_staff.css?v=<?= filemtime(__DIR__ . '/../css/css_staff.css') ?>">
    <style>
        /* ── Dashboard supplemental styles ────────────────────────────────
           css_staff.css already defines .staff-stat-grid / .staff-stat-card /
           .staff-stat-label / .staff-stat-value / .warn / .notice / .notice-info
           / .panel / .td-meta / .empty-state — everything here only adds new
           pieces (icons, trend chart, activity feed, capacity
           bars) without overriding those base styles. */
        .staff-stat-card {
            position:relative;
            border-radius:14px;
            box-shadow:0 1px 3px rgba(30,77,59,0.06);
        }
        .staff-stat-icon {
            width:34px; height:34px; border-radius:9px;
            background:var(--brand-accent-soft, #EAF3EE); color:var(--brand-primary, #1E4D3B);
            display:flex; align-items:center; justify-content:center;
            margin-bottom:10px;
        }
        .staff-stat-icon svg { width:18px; height:18px; }
        .staff-stat-card.warn-card .staff-stat-icon { background:#FFF4E6; color:#C06A10; }

        .staff-section-label {
            font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em;
            color:#8A8A9A; margin:2rem 0 .75rem;
        }
        .staff-section-label:first-child { margin-top:0; }

        /* Panels row: trend chart full width, then activity + capacity side by side */
        .staff-panel-block {
            border:0.5px solid #E4E4EC; border-radius:16px; padding:18px 20px; background:#fff;
            box-shadow:0 1px 3px rgba(30,77,59,0.06);
        }
        .staff-panel-title {
            font-size:13px; font-weight:600; color:#1A1A2E; margin-bottom:14px;
        }
        .staff-dash-split { display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; }
        @media (max-width: 900px) { .staff-dash-split { grid-template-columns: 1fr; } }

        /* Trend chart (dependency-free CSS bar chart) */
        .staff-trend-chart { display:flex; align-items:flex-end; gap:10px; height:130px; padding-top:22px; }
        .staff-trend-bar-wrap {
            flex:1; display:flex; flex-direction:column; align-items:center;
            height:100%; justify-content:flex-end; position:relative;
        }
        .staff-trend-value { position:absolute; top:-20px; font-size:11px; font-weight:600; color:#5A5A72; }
        .staff-trend-bar {
            width:100%; max-width:30px; background:var(--brand-primary, #1E4D3B);
            border-radius:5px 5px 2px 2px; min-height:4px; transition:height .2s;
        }
        .staff-trend-label { margin-top:6px; font-size:10.5px; color:#8A8A9A; white-space:nowrap; }

        /* Recent activity feed */
        .staff-activity-row { display:flex; gap:10px; padding:9px 0; border-bottom:0.5px solid #F5F5F7; }
        .staff-activity-row:last-child { border-bottom:none; }
        .staff-activity-icon {
            flex:0 0 auto; width:30px; height:30px; border-radius:9px;
            background:var(--brand-accent-soft, #EAF3EE); color:var(--brand-primary, #1E4D3B);
            display:flex; align-items:center; justify-content:center;
        }
        .staff-activity-icon svg { width:15px; height:15px; }
        .staff-activity-text { font-size:12.5px; color:#1A1A2E; }
        .staff-activity-time { font-size:11px; color:#8A8A9A; margin-top:1px; }

        /* Section capacity bars */
        .staff-capacity-row { padding:8px 0; border-bottom:0.5px solid #F5F5F7; }
        .staff-capacity-row:last-child { border-bottom:none; }
        .staff-capacity-top { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:5px; }
        .staff-capacity-name { font-size:12.5px; font-weight:500; color:#1A1A2E; }
        .staff-capacity-count { font-size:11.5px; color:#8A8A9A; }
        .staff-capacity-bar { height:6px; border-radius:20px; background:#F0F0F5; overflow:hidden; }
        .staff-capacity-fill { height:100%; border-radius:20px; }
        .staff-capacity-fill.ok   { background:#1A7A5E; }
        .staff-capacity-fill.high { background:#C06A10; }
        .staff-capacity-fill.full { background:#C0392B; }
    </style>
</head>
<body class="staff-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="../login">log in</a> to access the dashboard.</p>

<?php else: ?>
  <?php
    // staff_sidebar.php resolves $department, $roleLabel, $navItems, and
    // $statusRows for the signed-in staff account — reused below so the
    // dashboard's stat cards match the sidebar exactly,
    // with no duplicate queries. It also defines the $ico_* icon variables
    // used both here and in the sidebar nav.
    include_once 'staff_sidebar.php';

    $dash_uid = (int) ($_SESSION['user_id'] ?? 0);

    // A rejected/"X" icon — not defined in staff_sidebar.php, only needed here.
    $ico_x = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9l-6 6m0-6l6 6m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';

    // Map each department's stat-row labels to one of the icons already
    // defined in staff_sidebar.php, so a stat card's icon always matches
    // the kind of thing it's counting. Falls back to $ico_dashboard for
    // any label not covered.
    $stat_icon_map = [
        'Enrolled'             => $ico_enroll,
        'Pending enrollments'  => $ico_pending,
        'Awaiting review'      => $ico_pending,
        'Documents pending'    => $ico_docreview,
        'Reviewed today'       => $ico_approvals,
        'Enrolled (paid)'      => $ico_enroll,
        'Awaiting invoice'     => $ico_invoice,
        'Payments today'       => $ico_payment,
        'Pending approvals'    => $ico_approvals,
        'Active sections'      => $ico_sections,
        'Unscheduled sections' => $ico_schedule,
        'Active subjects'      => $ico_curriculum,
    ];

    $dept_icon_map = [
        'registrar'   => $ico_enroll,
        'records'     => $ico_admission,
        'treasury'    => $ico_payment,
        'coordinator' => $ico_approvals,
        'scheduler'   => $ico_schedule,
    ];
    $welcome_icon = $dept_icon_map[$department] ?? $ico_dashboard;

    // ── Small helpers (dashboard-local; nothing else includes this file) ───

    // Runs a query defensively: on PHP 8.1+, mysqli throws on error by
    // default instead of returning false, so a schema mismatch on any of
    // the new widget queries below degrades to an empty section instead of
    // a fatal error.
    function sb_dash_rows($conn, $sql) {
        try {
            $res = mysqli_query($conn, $sql);
            return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // Sums same-date ['d'=>..,'c'=>..] rows from multiple query results into one series.
    function sb_dash_merge_counts(array $rowsets) {
        $sums = [];
        foreach ($rowsets as $rows) {
            foreach ($rows as $r) {
                $d = $r['d'];
                $sums[$d] = ($sums[$d] ?? 0) + (int) $r['c'];
            }
        }
        $out = [];
        foreach ($sums as $d => $c) $out[] = ['d' => $d, 'c' => $c];
        return $out;
    }

    // Fills a 7-day window (oldest -> newest, including today) from sparse ['d','c'] rows.
    function sb_dash_trend_series(array $rows, $days = 7) {
        $map = [];
        foreach ($rows as $r) $map[$r['d']] = (int) $r['c'];
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i day"));
            $series[] = ['label' => date('D n/j', strtotime($date)), 'value' => $map[$date] ?? 0];
        }
        return $series;
    }

    function sb_dash_time_ago($datetime) {
        if (!$datetime) return '';
        $ts = strtotime($datetime);
        $diff = time() - $ts;
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j', $ts);
    }

    // Merges section/subject review rows (each needs 'name','status','ts','kind'),
    // sorts newest first, and caps the result.
    function sb_dash_sort_feed(array $items, $limit = 6) {
        usort($items, fn($a, $b) => strtotime($b['ts']) <=> strtotime($a['ts']));
        return array_slice($items, 0, $limit);
    }

    // ── Recent activity + 7-day trend, per department ───────────────────────
    $activity_feed  = [];
    $trend_title    = '';
    $trend_series   = [];

    if ($department === 'registrar') {

        $trend_title = 'New enrollments (last 7 days)';
        $trend_series = sb_dash_trend_series(sb_dash_rows($conn, "
            SELECT DATE(enrollment_date) AS d, COUNT(*) AS c FROM enrollments
            WHERE enrollment_date >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(enrollment_date)
        "));

        foreach (sb_dash_rows($conn, "
            SELECT e.status, e.enrollment_date, s.family_name, s.given_name
            FROM enrollments e JOIN students s ON s.student_id = e.student_id
            ORDER BY e.enrollment_date DESC LIMIT 6
        ") as $r) {
            $activity_feed[] = [
                'icon' => $ico_enroll,
                'text' => htmlspecialchars($r['family_name'] . ', ' . $r['given_name']) . ' — ' . htmlspecialchars(ucfirst($r['status'])),
                'time' => sb_dash_time_ago($r['enrollment_date']),
            ];
        }

    } elseif ($department === 'records') {

        // Based on enrollment_requirements.reviewed_at, set whenever a
        // requirement document's status is changed from
        // records/document_review.php — the only real per-review timestamp
        // this schema has (enrollments has no equivalent "approved_at"-style
        // column left after the old admission_status-gated pipeline was
        // retired).
        $trend_title = 'Documents reviewed (last 7 days)';
        $trend_series = sb_dash_trend_series(sb_dash_rows($conn, "
            SELECT DATE(reviewed_at) AS d, COUNT(*) AS c FROM enrollment_requirements
            WHERE reviewed_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(reviewed_at)
        "));

        foreach (sb_dash_rows($conn, "
            SELECT er.status, er.reviewed_at, rt.requirement_name, s.family_name, s.given_name
            FROM enrollment_requirements er
            JOIN enrollments e ON e.enrollment_id = er.enrollment_id
            JOIN students s ON s.student_id = e.student_id
            JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
            WHERE er.reviewed_at IS NOT NULL
            ORDER BY er.reviewed_at DESC LIMIT 6
        ") as $r) {
            $submitted = $r['status'] === 'submitted';
            $activity_feed[] = [
                'icon' => $submitted ? $ico_approvals : $ico_x,
                'text' => htmlspecialchars($r['family_name'] . ', ' . $r['given_name']) . ' — '
                    . htmlspecialchars($r['requirement_name']) . ($submitted ? ' confirmed' : ' reverted to pending'),
                'time' => sb_dash_time_ago($r['reviewed_at']),
            ];
        }

    } elseif ($department === 'treasury') {

        $trend_title = 'Payments received (last 7 days)';
        $trend_series = sb_dash_trend_series(sb_dash_rows($conn, "
            SELECT DATE(paid_at) AS d, COUNT(*) AS c FROM payments
            WHERE paid_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(paid_at)
        "));

        // NOTE: assumes payments has enrollment_id + amount columns (not
        // confirmed elsewhere in the codebase) — degrades to an empty feed
        // via sb_dash_rows()'s try/catch if that's not the actual schema.
        foreach (sb_dash_rows($conn, "
            SELECT p.amount, p.paid_at, s.family_name, s.given_name
            FROM payments p
            JOIN enrollments e ON e.enrollment_id = p.enrollment_id
            JOIN students s ON s.student_id = e.student_id
            ORDER BY p.paid_at DESC LIMIT 6
        ") as $r) {
            $activity_feed[] = [
                'icon' => $ico_payment,
                'text' => htmlspecialchars($r['family_name'] . ', ' . $r['given_name']) . ' paid ₱' . number_format((float) $r['amount'], 2),
                'time' => sb_dash_time_ago($r['paid_at']),
            ];
        }

    } elseif ($department === 'coordinator') {

        $trend_title = 'Approvals processed (last 7 days)';
        $trend_series = sb_dash_trend_series(sb_dash_merge_counts([
            sb_dash_rows($conn, "SELECT DATE(reviewed_at) AS d, COUNT(*) AS c FROM sections WHERE reviewed_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(reviewed_at)"),
            sb_dash_rows($conn, "SELECT DATE(approved_at) AS d, COUNT(*) AS c FROM subjects WHERE approved_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(approved_at)"),
        ]));

        $feed_items = [];
        foreach (sb_dash_rows($conn, "SELECT section_name AS name, approval_status AS status, reviewed_at AS ts FROM sections WHERE reviewed_at IS NOT NULL ORDER BY reviewed_at DESC LIMIT 6") as $r) {
            $feed_items[] = array_merge($r, ['kind' => 'Section']);
        }
        foreach (sb_dash_rows($conn, "SELECT subject_name AS name, review_status AS status, approved_at AS ts FROM subjects WHERE approved_at IS NOT NULL ORDER BY approved_at DESC LIMIT 6") as $r) {
            $feed_items[] = array_merge($r, ['kind' => 'Subject']);
        }
        foreach (sb_dash_sort_feed($feed_items) as $r) {
            $approved = $r['status'] === 'approved';
            $activity_feed[] = [
                'icon' => $approved ? $ico_approvals : $ico_x,
                'text' => $r['kind'] . ' "' . htmlspecialchars($r['name']) . '" ' . ($approved ? 'approved' : 'rejected'),
                'time' => sb_dash_time_ago($r['ts']),
            ];
        }

    } elseif ($department === 'scheduler') {

        $trend_title = 'Your submissions reviewed (last 7 days)';
        $trend_series = sb_dash_trend_series(sb_dash_merge_counts([
            sb_dash_rows($conn, "SELECT DATE(reviewed_at) AS d, COUNT(*) AS c FROM sections WHERE requested_by = $dash_uid AND reviewed_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(reviewed_at)"),
            sb_dash_rows($conn, "SELECT DATE(approved_at) AS d, COUNT(*) AS c FROM subjects WHERE proposed_by = $dash_uid AND approved_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(approved_at)"),
        ]));

        $feed_items = [];
        foreach (sb_dash_rows($conn, "SELECT section_name AS name, approval_status AS status, reviewed_at AS ts FROM sections WHERE requested_by = $dash_uid AND reviewed_at IS NOT NULL ORDER BY reviewed_at DESC LIMIT 6") as $r) {
            $feed_items[] = array_merge($r, ['kind' => 'Section']);
        }
        foreach (sb_dash_rows($conn, "SELECT subject_name AS name, review_status AS status, approved_at AS ts FROM subjects WHERE proposed_by = $dash_uid AND approved_at IS NOT NULL ORDER BY approved_at DESC LIMIT 6") as $r) {
            $feed_items[] = array_merge($r, ['kind' => 'Subject']);
        }
        foreach (sb_dash_sort_feed($feed_items) as $r) {
            $approved = $r['status'] === 'approved';
            $activity_feed[] = [
                'icon' => $approved ? $ico_approvals : $ico_x,
                'text' => 'Your ' . strtolower($r['kind']) . ' "' . htmlspecialchars($r['name']) . '" was ' . ($approved ? 'approved' : 'rejected'),
                'time' => sb_dash_time_ago($r['ts']),
            ];
        }
    }

    $trend_max = 1;
    foreach ($trend_series as $pt) $trend_max = max($trend_max, $pt['value']);

    // ── Section capacity — shown to everyone; knowing what's nearly full
    //    matters whether you manage sections (coordinator/scheduler) or
    //    you're enrolling students into one (registrar/records/treasury). ──
    $capacity_rows = sb_dash_rows($conn, "
        SELECT s.section_name, s.grade_level, st.strand_code AS strand, s.capacity,
               COUNT(DISTINCT CASE WHEN e.status IN ('pending','enrolled') THEN e.enrollment_id END) AS enrolled
        FROM sections s
        JOIN strands st ON st.strand_id = s.strand
        LEFT JOIN enrollments e ON e.section_id = s.section_id
        WHERE s.is_active = 1 AND s.approval_status = 'approved' AND s.capacity > 0
        GROUP BY s.section_id
        ORDER BY (enrolled / s.capacity) DESC
        LIMIT 6
    ");
  ?>
  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <div class="staff-topbar-title">
          Dashboard
          <span class="staff-topbar-subtitle"><?= htmlspecialchars($roleLabel) ?> overview</span>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php if (!in_array($department, ['records', 'registrar', 'treasury'], true)) { include_once 'staff_notifications.php'; } ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <div class="staff-welcome" style="display:flex; align-items:center; gap:10px;">
        <span class="staff-stat-icon" style="margin-bottom:0;"><?= $welcome_icon ?></span>
        Welcome back, <?= htmlspecialchars($_SESSION['username'] ?? 'there') ?>!
      </div>
      <div class="staff-welcome-sub">Here's what's happening in <?= htmlspecialchars($roleLabel) ?> today.</div>

      <?php if (!empty($statusRows)): ?>
      <div class="staff-stat-grid" style="margin-top:1rem;">
        <?php foreach ($statusRows as $row): ?>
        <div class="staff-stat-card<?= $row['warn'] ? ' warn-card' : '' ?>">
          <div class="staff-stat-icon"><?= $stat_icon_map[$row['label']] ?? $ico_dashboard ?></div>
          <div class="staff-stat-label"><?= htmlspecialchars($row['label']) ?></div>
          <div class="staff-stat-value<?= $row['warn'] ? ' warn' : '' ?>"><?= $row['value'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($trend_title): ?>
      <div class="staff-section-label">Trend</div>
      <div class="staff-panel-block">
        <div class="staff-panel-title"><?= htmlspecialchars($trend_title) ?></div>
        <div class="staff-trend-chart">
          <?php foreach ($trend_series as $pt): $pct = round($pt['value'] / $trend_max * 100); ?>
            <div class="staff-trend-bar-wrap">
              <span class="staff-trend-value"><?= $pt['value'] ?></span>
              <div class="staff-trend-bar" style="height:<?= max(4, $pct) ?>%"></div>
              <span class="staff-trend-label"><?= htmlspecialchars($pt['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="staff-section-label">Activity &amp; Capacity</div>
      <div class="staff-dash-split">
        <div class="staff-panel-block">
          <div class="staff-panel-title">Recent Activity</div>
          <?php if (empty($activity_feed)): ?>
            <p class="empty-state">No recent activity.</p>
          <?php else: ?>
            <?php foreach ($activity_feed as $item): ?>
              <div class="staff-activity-row">
                <span class="staff-activity-icon"><?= $item['icon'] ?></span>
                <div>
                  <div class="staff-activity-text"><?= $item['text'] ?></div>
                  <div class="staff-activity-time"><?= htmlspecialchars($item['time']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="staff-panel-block">
          <div class="staff-panel-title">Section Capacity</div>
          <?php if (empty($capacity_rows)): ?>
            <p class="empty-state">No active sections yet.</p>
          <?php else: ?>
            <?php foreach ($capacity_rows as $c):
              $enrolled = (int) $c['enrolled'];
              $capacity = (int) $c['capacity'];
              $pct = min(100, round($enrolled / $capacity * 100));
              $fillClass = $pct >= 100 ? 'full' : ($pct >= 80 ? 'high' : 'ok');
            ?>
              <div class="staff-capacity-row">
                <div class="staff-capacity-top">
                  <span class="staff-capacity-name">
                    <?= htmlspecialchars($c['section_name']) ?>
                    <span class="td-meta">G<?= htmlspecialchars($c['grade_level']) ?> &middot; <?= htmlspecialchars($c['strand']) ?></span>
                  </span>
                  <span class="staff-capacity-count"><?= $enrolled ?>/<?= $capacity ?></span>
                </div>
                <div class="staff-capacity-bar"><div class="staff-capacity-fill <?= $fillClass ?>" style="width:<?= $pct ?>%"></div></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
