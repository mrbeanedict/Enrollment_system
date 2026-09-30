<?php
/* ============================================================
   scheduling.php — Class Scheduling (the schedule builder)
   ------------------------------------------------------------
   Scheduler-only. The one place a section's weekly class schedule is
   built, for either semester:

     - Sections rail (left): every active section with its progress and
       schedule status; search + status chips.
     - Builder (right): the selected section's subjects on a Mon–Fri
       timetable AND as an editable list — both views edit the same
       in-memory schedule. A side panel edits one subject at a time.
       Teacher/room clashes against every other section (same semester)
       and overlaps inside the section show live, before saving.
     - One "Save draft" posts the whole schedule; it's saved in a single
       transaction (all or nothing) and re-checked on the server with
       row locks — the browser checks are a convenience only.
     - "Submit for approval" saves (if needed) then submits.

   Coordinators/admins review schedules in coordinator/approvals.php
   (Schedule tab, per semester) and are redirected there. The old
   create_schedule.php guided page now redirects here too.

   Shared logic (clash checks, save, submit, busy map): _schedule_lib.php
   ============================================================ */

session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/_schedule_lib.php';

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

$department = $_SESSION['department'] ?? null;
if ($department === null) {
    $dept_stmt = mysqli_prepare($conn, "
        SELECT d.department_name
        FROM staff s
        JOIN departments d ON d.department_id = s.department_id
        WHERE s.user_id = ? AND s.is_active = 1
    ");
    mysqli_stmt_bind_param($dept_stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($dept_stmt);
    mysqli_stmt_bind_result($dept_stmt, $deptResult);
    if (mysqli_stmt_fetch($dept_stmt)) {
        $department = $deptResult;
        $_SESSION['department'] = $department;
    }
    mysqli_stmt_close($dept_stmt);
}
$department = $department ?? '';

$is_reviewer  = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');
$is_scheduler = ($role === 'staff' && $department === 'scheduler');

$semester = (int)($_POST['semester'] ?? $_GET['semester'] ?? 1) === 2 ? 2 : 1;

// Reviewers approve schedules on the Approvals page (Schedule tab).
if ($is_reviewer) {
    header('Location: ' . APP_URL . '/roles/staff/coordinator/approvals?type=schedule' . ($semester === 2 ? '&sem=2' : ''));
    exit();
}
if (!$is_scheduler) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

function strand_badge_class(?string $code): string {
    $map = ['STEM' => 'stem', 'HUMSS' => 'humss', 'ABM' => 'abm', 'GAS' => 'gas', 'TVL-ICT' => 'tvl', 'TVL' => 'tvl', 'CORE' => 'core'];
    return $map[$code] ?? 'none';
}

$error      = '';
$failed_row = null;
$posted     = null;   // what was typed, re-shown after a failed save

$flash = $_SESSION['sched_flash'] ?? null;
unset($_SESSION['sched_flash']);

// ── Save (and optionally submit) the whole schedule ─────────────────────────
// Posted as one row per subject (subject_id[], teacher_id[]) plus one row per
// class meeting (m_subject[], m_day[], m_start[], m_end[], m_room[]).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $then_submit = ($_POST['then_submit'] ?? '') === '1';

    $posted = [];
    foreach ((array)($_POST['subject_id'] ?? []) as $i => $sid) {
        $posted[(int)$sid] = [
            'subject_id' => (int)$sid,
            'teacher_id' => ($_POST['teacher_id'][$i] ?? '') !== '' ? (int)$_POST['teacher_id'][$i] : null,
            'meetings'   => [],
        ];
    }
    foreach ((array)($_POST['m_subject'] ?? []) as $j => $sid) {
        if (!isset($posted[(int)$sid])) continue;
        $posted[(int)$sid]['meetings'][] = [
            'day'        => (string)($_POST['m_day'][$j] ?? ''),
            'start_time' => (string)($_POST['m_start'][$j] ?? ''),
            'end_time'   => (string)($_POST['m_end'][$j] ?? ''),
            'room'       => (string)($_POST['m_room'][$j] ?? ''),
        ];
    }
    $posted = array_values($posted);

    $res = sched_save_slots($conn, $section_id, $semester, $posted, $user_id);
    if (!$res['ok']) {
        $error      = $res['error'];
        $failed_row = $res['row'];
    } else {
        $msg = !$res['changed'] ? 'No changes to save.'
             : ($res['reverted'] ? 'Saved. Because this schedule was approved, it went back to the coordinator for re-approval.'
                                 : 'Draft saved.');
        if ($then_submit) {
            $sub = sched_submit($conn, $section_id, $semester, $user_id);
            if ($sub['ok']) {
                $msg = 'Submitted for coordinator approval. Editing is locked until it\'s reviewed.';
            } else {
                $msg = ($res['changed'] ? 'Draft saved, but it wasn\'t submitted: ' : 'Not submitted: ') . $sub['error'];
            }
        }
        $_SESSION['sched_flash'] = $msg;
        header("Location: scheduling?section_id=$section_id&semester=$semester"); exit();
    }
}

// ── Sections rail ───────────────────────────────────────────────────────────
// "Ready" = has a teacher and at least one class meeting.
$status_col = sched_col('schedule_status', $semester);
$rail_stmt = $conn->prepare("
    SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.room, sec.school_year,
           sec.$status_col AS schedule_status,
           COUNT(ss.subject_id) AS subject_count,
           SUM(ss.teacher_id IS NOT NULL AND EXISTS (SELECT 1 FROM section_subject_meetings m
                 WHERE m.section_id = ss.section_id AND m.subject_id = ss.subject_id AND m.semester = ss.semester)) AS ready_count,
           (SELECT COUNT(*) FROM section_subject_meetings m WHERE m.section_id = sec.section_id AND m.semester = ?) AS timed_count
    FROM sections sec
    JOIN strands st ON st.strand_id = sec.strand
    LEFT JOIN section_subjects ss ON ss.section_id = sec.section_id AND ss.semester = ?
    WHERE sec.is_active = 1 AND sec.approval_status = 'approved'
    GROUP BY sec.section_id
    ORDER BY sec.grade_level ASC, st.strand_code ASC, sec.section_name ASC");
$rail_stmt->bind_param('ii', $semester, $semester);
$rail_stmt->execute();
$rail = $rail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rail_stmt->close();

// Human status for a schedule: unscheduled splits into "Not started" / "Draft".
function sched_status_view(string $status, int $timed): array {
    return match ($status) {
        'pending'  => ['pending',  'Pending review'],
        'approved' => ['approved', 'Approved'],
        'rejected' => ['rejected', 'Needs changes'],
        default    => $timed > 0 ? ['draft', 'Draft'] : ['empty', 'Not started'],
    };
}

// ── Selected section ────────────────────────────────────────────────────────
$sel_id   = (int)($_POST['section_id'] ?? $_GET['section_id'] ?? 0);
$selected = $sel_id ? sched_fetch_section($conn, $sel_id, $semester) : null;
if ($selected && (!$selected['is_active'] || $selected['approval_status'] !== 'approved')) $selected = null;

$payload = null;
if ($selected) {
    $db_rows = sched_fetch_rows($conn, $sel_id, $semester);
    $seeding = false;
    $s1_by_id = [];
    if ($semester === 2) {
        foreach (sched_fetch_rows($conn, $sel_id, 1) as $r) $s1_by_id[(int)$r['subject_id']] = $r;
        if (!$db_rows && $s1_by_id) {
            // Semester 2 not set up yet: start from Semester 1's subjects,
            // teachers kept as a starting point, no classes yet.
            $seeding = true;
            foreach ($s1_by_id as $r) {
                $db_rows[] = ['subject_id' => $r['subject_id'], 'subject_name' => $r['subject_name'], 'teacher_id' => $r['teacher_id'], 'meetings' => []];
            }
        }
    }

    $js_m = fn(array $ms) => array_map(fn($m) => ['d' => $m['day'], 's' => $m['start_time'], 'e' => $m['end_time'], 'r' => $m['room'] ?? ''], $ms);
    $to_js = function (array $r) use ($s1_by_id, $seeding, $js_m): array {
        $s1 = $s1_by_id[(int)$r['subject_id']] ?? null;
        return [
            'id'   => (int)$r['subject_id'],
            'name' => $r['subject_name'],
            't'    => $r['teacher_id'] ? (int)$r['teacher_id'] : null,
            'm'    => $js_m($r['meetings']),
            's1'   => $seeding && $s1 ? $js_m($s1['meetings']) : null,
        ];
    };
    $original = array_map($to_js, $db_rows);
    $current  = $original;

    // After a failed save, show exactly what was typed (by subject).
    if ($posted !== null) {
        $by_id = [];
        foreach ($posted as $p) $by_id[$p['subject_id']] = $p;
        foreach ($current as &$c) {
            if (!isset($by_id[$c['id']])) continue;
            $c['t'] = $by_id[$c['id']]['teacher_id'];
            $c['m'] = $js_m($by_id[$c['id']]['meetings']);
        }
        unset($c);
    }

    $timed_now = array_sum(array_map(fn($r) => count($r['m']), $original));
    [$st_key, $st_label] = sched_status_view($selected['schedule_status'], $timed_now);

    $teachers = mysqli_query($conn, "SELECT teacher_id, CONCAT(given_name, ' ', family_name) AS name FROM teachers WHERE is_active = 1 ORDER BY family_name, given_name")->fetch_all(MYSQLI_ASSOC);

    $payload = [
        'sem'      => $semester,
        'section'  => [
            'id'        => (int)$selected['section_id'],
            'label'     => sched_section_label($selected['section_name'], $selected['strand']),
            'room'      => $selected['room'] ?: '',
            'status'    => $selected['schedule_status'],
            'editable'  => in_array($selected['schedule_status'], ['unscheduled', 'rejected', 'approved'], true) && $original,
            'seeding'   => $seeding,
        ],
        'rows'      => $current,
        'original'  => $original,
        'failedRow' => $failed_row,
        'teachers'  => array_map(fn($t) => [(int)$t['teacher_id'], $t['name']], $teachers),
        'busy'      => sched_busy_map($conn, $semester, $sel_id),
        'bounds'    => sched_time_bounds(),
    ];
}


$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

// NOTE: $conn stays open — staff_sidebar.php (included below) queries it.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Class Scheduling — Scheduler</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    /* The `hidden` attribute is how this page opens/closes the side panel,
       scrim, views and filtered rail rows — but any element with its own
       display rule (the panel is display:flex, rail rows display:block)
       would otherwise ignore it and stay visible. */
    [hidden] { display:none !important; }

    .sb { --sb-line:#E6EAE8; --sb-line-2:#F0F2F1; --sb-muted:#66717A; --sb-ink:#17211C; --sb-bg:#F7F9F8;
          --sb-bad:#B3261E; --sb-bad-bg:#FDEEEC; --sb-warn:#8A5A00; --sb-warn-bg:#FFF5E0; --sb-ok:#1A6B4A; --sb-ok-bg:#E6F4EC;
          --sb-row-h:52px; }

    /* Header */
    .sb-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:1rem; }
    .sb-head .page-sub { margin-bottom:0; }
    .sb-seg { display:inline-flex; padding:3px; background:var(--sb-bg); border:1px solid var(--sb-line); border-radius:9px; }
    .sb-seg a { padding:7px 16px; border-radius:7px; font-size:13px; font-weight:500; color:var(--sb-muted); text-decoration:none; }
    .sb-seg a:hover { color:var(--sb-ink); }
    .sb-seg a[aria-current="true"] { background:#fff; color:var(--sb-ink); font-weight:600; box-shadow:0 1px 2px rgba(20,40,30,.14); }

    .sb-layout { display:grid; grid-template-columns:290px minmax(0,1fr); gap:16px; align-items:start; }

    /* Sections rail */
    .sb-rail { background:#fff; border:1px solid var(--sb-line); border-radius:14px; overflow:hidden; position:sticky; top:12px;
               max-height:calc(100vh - 110px); display:flex; flex-direction:column; }
    .sb-rail-top { padding:12px; border-bottom:1px solid var(--sb-line); display:flex; flex-direction:column; gap:9px; }
    .sb-search { position:relative; }
    .sb-search svg { position:absolute; left:10px; top:50%; width:15px; height:15px; transform:translateY(-50%); color:#8A8A9A; pointer-events:none; }
    .sb-search input { width:100%; height:34px; padding:0 10px 0 31px; border:1px solid #D4D9D6; border-radius:8px; font-size:13px; font-family:inherit; background:#fff; outline:none; }
    .sb-search input:focus { border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); }
    .sb-chips { display:flex; flex-wrap:wrap; gap:5px; }
    .sb-chip { height:26px; padding:0 10px; border:1px solid #D4D9D6; border-radius:20px; background:#fff; font:500 11.5px/1 inherit; font-family:inherit; color:#3A4A42; cursor:pointer; }
    .sb-chip:hover { border-color:var(--brand-accent); }
    .sb-chip.is-on { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }
    .sb-rail-list { overflow-y:auto; padding:4px 0 8px; }
    .sb-grade { padding:10px 14px 4px; font-size:10.5px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#8A8A9A; }
    .sb-sec { display:block; padding:9px 14px; text-decoration:none; color:inherit; border-left:3px solid transparent; }
    .sb-sec:hover { background:var(--sb-bg); }
    .sb-sec[aria-current="page"] { background:var(--brand-tint); border-left-color:var(--brand-primary); }
    .sb-sec-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .sb-sec-name { font-size:13px; font-weight:600; color:var(--sb-ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-sec-meta { display:flex; align-items:center; gap:8px; margin-top:5px; font-size:11px; color:var(--sb-muted); }
    .sb-prog { flex:1; height:4px; border-radius:3px; background:#E6EAE8; overflow:hidden; }
    .sb-prog i { display:block; height:100%; background:#2E9E6B; border-radius:3px; }
    .sb-rail-empty { padding:24px 14px; font-size:12.5px; color:var(--sb-muted); text-align:center; }
    .sb-rail-select { display:none; }

    /* Status pills */
    .sb-pill { display:inline-flex; align-items:center; gap:5px; flex-shrink:0; height:20px; padding:0 8px; border-radius:20px; font-size:10.5px; font-weight:600; white-space:nowrap; }
    .sb-pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .sb-pill--empty    { background:#EEF0EF; color:#66717A; }
    .sb-pill--draft    { background:#E8EEF8; color:#2F5591; }
    .sb-pill--pending  { background:var(--sb-warn-bg); color:var(--sb-warn); }
    .sb-pill--approved { background:var(--sb-ok-bg); color:var(--sb-ok); }
    .sb-pill--rejected { background:var(--sb-bad-bg); color:var(--sb-bad); }

    /* Main card */
    .sb-main { background:#fff; border:1px solid var(--sb-line); border-radius:14px; min-width:0; }
    .sb-main-head { padding:18px 20px 14px; border-bottom:1px solid var(--sb-line); }
    .sb-title-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .sb-title { font-size:19px; font-weight:650; color:var(--sb-ink); margin:0; }
    .sb-facts { display:flex; flex-wrap:wrap; gap:6px 14px; margin-top:6px; font-size:12.5px; color:var(--sb-muted); }
    .sb-facts b { color:var(--sb-ink); font-weight:600; }
    .strand-badge { display:inline-flex; align-items:center; padding:1px 7px; border-radius:5px; font-size:10.5px; font-weight:700; }
    .strand-badge--stem  { background:#E1ECEA; color:#1F5E57; }
    .strand-badge--humss { background:#E6ECF2; color:#34526E; }
    .strand-badge--abm   { background:#EFEEE2; color:#5E5A2A; }
    .strand-badge--gas   { background:#E4F0E8; color:#2D6547; }
    .strand-badge--tvl   { background:#F1EBE3; color:#6B4E2E; }
    .strand-badge--core  { background:#E9ECEB; color:#44524B; }
    .strand-badge--none  { background:#EDEDF0; color:#4A5560; }
    .sb-stats { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .sb-stat { display:flex; align-items:center; gap:8px; padding:7px 11px; border:1px solid var(--sb-line); border-radius:9px; font-size:12.5px; color:var(--sb-muted); }
    .sb-stat b { font-size:14px; color:var(--sb-ink); font-variant-numeric:tabular-nums; }
    .sb-stat.is-bad { border-color:#F0C4BF; background:var(--sb-bad-bg); color:var(--sb-bad); }
    .sb-stat.is-bad b { color:var(--sb-bad); }
    .sb-stat .sb-prog { width:70px; flex:none; }

    .sb-banner { display:flex; gap:10px; align-items:flex-start; margin:14px 20px 0; padding:10px 12px; border-radius:9px; font-size:13px; line-height:1.45; }
    .sb-banner svg { width:16px; height:16px; flex-shrink:0; margin-top:1px; }
    .sb-banner--info { background:#EEF3FB; color:#27466F; }
    .sb-banner--warn { background:var(--sb-warn-bg); color:#6B4A00; }
    .sb-banner--bad  { background:var(--sb-bad-bg); color:#8C1D17; }
    .sb-banner .sb-link { margin-left:auto; }

    .sb-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:14px 20px 10px; }
    .sb-view { display:inline-flex; padding:2px; background:var(--sb-bg); border:1px solid var(--sb-line); border-radius:8px; }
    .sb-view button { height:28px; padding:0 12px; border:0; border-radius:6px; background:none; font:500 12.5px inherit; font-family:inherit; color:var(--sb-muted); cursor:pointer; }
    .sb-view button[aria-pressed="true"] { background:#fff; color:var(--sb-ink); font-weight:600; box-shadow:0 1px 2px rgba(20,40,30,.14); }
    .sb-hint { font-size:12px; color:var(--sb-muted); }
    .sb-link { background:none; border:0; padding:4px 6px; border-radius:6px; font:600 12.5px inherit; font-family:inherit; color:var(--brand-primary); cursor:pointer; white-space:nowrap; }
    .sb-link:hover { background:var(--brand-tint); }

    /* Timetable */
    .sb-tt-wrap { padding:0 20px; overflow-x:auto; }
    .sb-tt { display:grid; grid-template-columns:58px repeat(5, minmax(110px, 1fr)); min-width:640px; user-select:none; -webkit-user-select:none; }
    .sb-tt-dh { padding:6px 0 8px; text-align:center; font-size:11.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--sb-muted); }
    .sb-tt-axis { position:relative; }
    .sb-tt-axis span { position:absolute; right:8px; font-size:10.5px; color:#8A8A9A; transform:translateY(-50%); white-space:nowrap; font-variant-numeric:tabular-nums; }
    /* Hour lines solid, half-hour lines faint (52px per hour). */
    .sb-tt-day { position:relative; border-left:1px solid var(--sb-line); border-top:1px solid var(--sb-line);
                 background-image:linear-gradient(#E3E8E5 1px, transparent 1px), linear-gradient(#F4F6F5 1px, transparent 1px);
                 background-size:100% 52px, 100% 26px; }
    .sb-tt-day:last-child { border-right:1px solid var(--sb-line); }
    .sb-tt-day::after { content:''; position:absolute; left:0; right:0; bottom:0; border-bottom:1px solid var(--sb-line); }
    .sb-cell { position:absolute; left:0; right:0; height:26px; border:0; background:none; padding:0; cursor:default; }
    .sb-placing .sb-cell { cursor:pointer; }
    .sb-placing .sb-cell:hover { background:rgba(47,107,79,.10); box-shadow:inset 0 0 0 2px rgba(47,107,79,.45); }
    .sb-placing .sb-cell:hover::after { content:'Place here'; font-size:10.5px; font-weight:600; color:var(--brand-primary); }
    /* Tinted block + accent bar (colour set inline per subject); text stays one ink colour. */
    .sb-block { position:absolute; z-index:2; padding:5px 7px 5px 8px; border-radius:6px; border:1px solid rgba(23,33,28,.08); border-left:3px solid; color:var(--sb-ink); text-align:left;
                overflow:hidden; cursor:pointer; font-family:inherit; display:flex; flex-direction:column; gap:1px; box-sizing:border-box; }
    .sb-block:hover { filter:brightness(.97); box-shadow:0 2px 8px rgba(20,40,30,.14); }
    .sb-block:focus-visible { outline:2px solid var(--brand-accent); outline-offset:1px; }
    .sb-block .n { font-size:12px; font-weight:600; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-block .m { font-size:10.5px; color:var(--sb-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-block.is-clash { border-color:#E7A59E !important; border-left-color:var(--sb-bad) !important; background:#FDF1EF !important; }
    .sb-block.is-clash .n::before { content:'⚠ '; color:var(--sb-bad); }
    .sb-block.is-active { outline:2px solid var(--sb-ink); outline-offset:1px; }
    .sb-placing .sb-block { pointer-events:none; opacity:.55; }
    .sb-tt-day.is-used { background-color:#F3F4F3; background-image:repeating-linear-gradient(135deg, transparent 0 8px, rgba(0,0,0,.035) 8px 16px); }

    .sb-tray { margin:12px 20px 0; padding:10px 12px; border:1px dashed #CBD3CE; border-radius:10px; background:var(--sb-bg); }
    .sb-tray-head { font-size:12px; color:var(--sb-muted); margin-bottom:7px; }
    .sb-tray-head b { color:var(--sb-ink); }
    .sb-tray-list { display:flex; flex-wrap:wrap; gap:6px; }
    .sb-tray-chip { max-width:260px; height:30px; padding:0 11px; border-radius:7px; border:1px solid rgba(23,33,28,.1); border-left:3px solid; color:var(--sb-ink); font:600 12px inherit; font-family:inherit;
                    cursor:pointer; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-tray-chip.is-on { outline:2px solid var(--sb-ink); outline-offset:1px; }
    .sb-tray-done { font-size:12.5px; color:var(--sb-ok); font-weight:600; }

    /* List view: one expandable card per subject, its classes inside */
    .sb-cards-wrap { padding:0 20px; display:flex; flex-direction:column; gap:8px; }
    .sb-card { border:1px solid var(--sb-line); border-radius:11px; background:#fff; }
    .sb-card.is-bad { border-color:#F0C4BF; }
    .sb-card.is-failed { box-shadow:0 0 0 3px rgba(179,38,30,.14); }
    .sb-card-head { display:flex; align-items:center; gap:10px; padding:10px 12px; min-height:52px; }
    .sb-ic { width:22px; height:22px; flex-shrink:0; border-radius:6px; display:grid; place-items:center; font-size:12px; font-weight:700; }
    .sb-ic--ok   { background:var(--sb-ok-bg); color:var(--sb-ok); }
    .sb-ic--warn { background:var(--sb-warn-bg); color:var(--sb-warn); }
    .sb-ic--bad  { background:var(--sb-bad-bg); color:var(--sb-bad); }
    .sb-card-name { flex:1; min-width:0; display:flex; flex-direction:column; align-items:flex-start; gap:2px; background:none; border:0; padding:0;
                    font-family:inherit; text-align:left; cursor:pointer; }
    .sb-card-name .n { max-width:100%; font-size:13.5px; font-weight:600; color:var(--sb-ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-card-name .s { max-width:100%; font-size:11.5px; color:var(--sb-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .sb-card-head select { width:190px; flex-shrink:0; height:34px; padding:0 8px; border:1px solid #D4D9D6; border-radius:7px; background:#fff; font-size:12.5px; font-family:inherit; color:var(--sb-ink); }
    .sb-count { flex-shrink:0; min-width:64px; text-align:right; font-size:11.5px; color:var(--sb-muted); font-variant-numeric:tabular-nums; }
    .sb-tog { width:30px; height:30px; flex-shrink:0; border:0; background:none; border-radius:7px; color:var(--sb-muted); cursor:pointer; display:grid; place-items:center; }
    .sb-tog:hover { background:var(--sb-bg); color:var(--sb-ink); }
    .sb-tog svg { width:15px; height:15px; transition:transform .15s; }
    .sb-card.is-open .sb-tog svg { transform:rotate(180deg); }
    .sb-card-body { padding:4px 12px 12px 44px; border-top:1px solid var(--sb-line-2); }
    .sb-card:not(.is-open) .sb-card-body { display:none; }
    .sb-dot { width:9px; height:9px; border-radius:3px; flex-shrink:0; }

    /* One class meeting row (list cards + side panel) */
    .sb-mrow { padding:8px 0; border-bottom:1px dashed var(--sb-line); }
    .sb-mrow:last-of-type { border-bottom:0; }
    .sb-mrow.is-focus { background:linear-gradient(90deg, var(--brand-tint), transparent 70%); margin:0 -8px; padding-left:8px; padding-right:8px; border-radius:8px; }
    .sb-mgrid { display:grid; grid-template-columns:78px 1fr auto 1fr minmax(80px, .9fr) 30px; align-items:center; gap:6px; }
    .sb-mgrid select, .sb-mgrid input { width:100%; min-width:0; height:34px; padding:0 7px; border:1px solid #D4D9D6; border-radius:7px; background:#fff; font-size:12.5px; font-family:inherit; color:var(--sb-ink); }
    .sb-mgrid select:disabled, .sb-mgrid input:disabled, .sb-card-head select:disabled { background:var(--sb-bg); color:var(--sb-muted); }
    .sb-mrow.is-bad .sb-mgrid select, .sb-mrow.is-bad .sb-mgrid input { border-color:#E7A59E; }
    .sb-dash { color:var(--sb-muted); font-size:12px; }
    .sb-mdel { width:30px; height:30px; border:0; background:none; border-radius:7px; color:#8A8A9A; font-size:16px; cursor:pointer; }
    .sb-mdel:hover { background:var(--sb-bad-bg); color:var(--sb-bad); }
    .sb-issue { margin-top:4px; font-size:11.5px; line-height:1.35; color:var(--sb-bad); font-weight:500; }
    .sb-issue--warn { color:var(--sb-warn); }
    .sb-add { margin-top:8px; display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 12px; border:1px dashed #B9C4BE; border-radius:8px;
              background:#fff; font:600 12px inherit; font-family:inherit; color:var(--brand-primary); cursor:pointer; }
    .sb-add:hover { border-style:solid; border-color:var(--brand-accent); background:var(--brand-tint); }
    .sb-add:disabled { opacity:.5; cursor:not-allowed; }
    .sb-panel .sb-mgrid { grid-template-columns:70px 1fr auto 1fr 30px; }
    .sb-panel .sb-mgrid input { grid-column:1 / 5; }
    .sb-empty-note { font-size:12.5px; color:var(--sb-muted); padding:6px 0; }
    @media (max-width: 760px) {
      .sb-card-head { flex-wrap:wrap; }
      .sb-card-head select { order:5; width:100%; }
      .sb-card-body { padding-left:12px; }
      .sb-mgrid { grid-template-columns:1fr 1fr auto 1fr 30px; }
      .sb-mgrid input { grid-column:1 / 5; }
    }

    /* Sticky action bar */
    .sb-bar { position:sticky; bottom:0; z-index:5; display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:16px;
              padding:12px 20px; border-top:1px solid var(--sb-line); background:rgba(255,255,255,.96); backdrop-filter:blur(4px);
              border-radius:0 0 14px 14px; }
    .sb-bar-state { margin-right:auto; display:flex; align-items:center; gap:8px; font-size:13px; color:var(--sb-muted); }
    .sb-bar-state .dot { width:8px; height:8px; border-radius:50%; background:#B8C2BC; }
    .sb-bar-state.is-dirty { color:var(--sb-ink); font-weight:600; }
    .sb-bar-state.is-dirty .dot { background:#D98A00; }

    /* Side panel */
    .sb-scrim { position:fixed; inset:0; z-index:900; background:rgba(18,32,26,.18); }
    .sb-panel { position:fixed; top:0; right:0; bottom:0; z-index:901; width:min(380px, 100vw); background:#fff; box-shadow:-12px 0 40px rgba(18,32,26,.16);
                display:flex; flex-direction:column; }
    .sb-panel-head { display:flex; align-items:flex-start; gap:10px; padding:18px 18px 12px; border-bottom:1px solid var(--sb-line); }
    .sb-panel-head h2 { margin:0; font-size:16px; font-weight:650; color:var(--sb-ink); line-height:1.3; }
    .sb-panel-head p { margin:3px 0 0; font-size:12px; color:var(--sb-muted); }
    .sb-x { margin-left:auto; width:30px; height:30px; flex-shrink:0; border:0; background:none; border-radius:8px; font-size:20px; color:var(--sb-muted); cursor:pointer; }
    .sb-x:hover { background:#EEF2F0; color:var(--sb-ink); }
    .sb-panel-body { flex:1; overflow-y:auto; padding:16px 18px; display:flex; flex-direction:column; gap:14px; }
    .sb-f label { display:block; margin-bottom:5px; font-size:12px; font-weight:600; text-transform:none; letter-spacing:0; color:var(--sb-ink); }
    .sb-f select, .sb-f input { width:100%; height:38px; padding:0 10px; border:1px solid #D4D9D6; border-radius:8px; background:#fff; font-size:13px; font-family:inherit; color:var(--sb-ink); }
    .sb-f select:disabled, .sb-f input:disabled { background:var(--sb-bg); color:var(--sb-muted); }
    .sb-f-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
    .sb-f-help { margin:5px 0 0; font-size:11.5px; color:var(--sb-muted); }
    .sb-checks { display:flex; flex-direction:column; gap:6px; }
    .sb-check { padding:9px 11px; border-radius:8px; font-size:12.5px; line-height:1.4; }
    .sb-check--bad  { background:var(--sb-bad-bg); color:#8C1D17; }
    .sb-check--warn { background:var(--sb-warn-bg); color:#6B4A00; }
    .sb-check--ok   { background:var(--sb-ok-bg); color:var(--sb-ok); font-weight:600; }
    .sb-panel-foot { display:flex; gap:8px; padding:12px 18px; border-top:1px solid var(--sb-line); }
    .sb-panel-foot .sch-btn:last-child { margin-left:auto; }

    .sb-empty { padding:64px 24px; text-align:center; color:var(--sb-muted); }
    .sb-empty svg { width:40px; height:40px; color:#B8C2BC; margin-bottom:10px; }
    .sb-empty h2 { margin:0 0 4px; font-size:15px; color:var(--sb-ink); }
    .sb-empty p { margin:0; font-size:13px; }

    .sch-btn--danger-ghost { background:#fff; border-color:#F0C4BF; color:var(--sb-bad); }
    .sch-btn--danger-ghost:hover { background:var(--sb-bad-bg); }

    @media (max-width: 1000px) {
      .sb-layout { grid-template-columns:1fr; }
      .sb-rail { display:none; }
      .sb-rail-select { display:block; margin-bottom:12px; }
      .sb-rail-select select { width:100%; height:40px; border:1px solid #D4D9D6; border-radius:9px; padding:0 10px; font-size:13.5px; font-family:inherit; background:#fff; }
    }
    @media (max-width: 560px) {
      .sb-main-head, .sb-toolbar, .sb-tt-wrap, .sb-cards-wrap, .sb-bar { padding-left:14px; padding-right:14px; }
      .sb-banner, .sb-tray { margin-left:14px; margin-right:14px; }
      .sb-panel { top:auto; width:100vw; max-height:88vh; border-radius:16px 16px 0 0; }
      .sb-f-row { grid-template-columns:1fr; }
    }
    @media (prefers-reduced-motion: no-preference) {
      .sb-panel { animation:sbIn .16s ease-out; }
      @keyframes sbIn { from { transform:translateX(24px); opacity:.4; } to { transform:none; opacity:1; } }
    }
  </style>
</head>
<body class="staff-layout">

  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>

  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left"></div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content sb">

      <div class="sb-head">
        <div>
          <p class="page-eyebrow">Scheduler Portal</p>
          <h1 class="page-title">Class Scheduling</h1>
          <p class="page-sub">Build each section's weekly timetable, then submit it for coordinator approval.</p>
        </div>
        <nav class="sb-seg" aria-label="Semester">
          <?php foreach ([1, 2] as $sm): ?>
            <a href="?semester=<?= $sm ?><?= $selected ? '&section_id=' . (int)$selected['section_id'] : '' ?>" <?= $semester === $sm ? 'aria-current="true"' : '' ?>>Semester <?= $sm ?></a>
          <?php endforeach; ?>
        </nav>
      </div>

      <?php if ($flash): ?><div class="notice notice-success" role="status" data-ui-toast><?= $e($flash) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice notice-error" role="alert" data-ui-toast><?= $e($error) ?> Nothing was saved — your changes are still below.</div><?php endif; ?>

      <!-- Phone / narrow: the rail collapses into this picker -->
      <div class="sb-rail-select">
        <label class="ap-sr" for="sbRailSelect" style="position:absolute;left:-9999px;">Section</label>
        <select id="sbRailSelect" onchange="if(this.value) location.href='?semester=<?= $semester ?>&section_id=' + this.value">
          <option value="">Choose a section…</option>
          <?php foreach ($rail as $s): [$k, $l] = sched_status_view($s['schedule_status'], (int)$s['timed_count']); ?>
            <option value="<?= (int)$s['section_id'] ?>" <?= $selected && (int)$selected['section_id'] === (int)$s['section_id'] ? 'selected' : '' ?>>
              <?= $e(sched_section_label($s['section_name'], $s['strand'])) ?> — <?= (int)$s['ready_count'] ?>/<?= (int)$s['subject_count'] ?> · <?= $e($l) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sb-layout">

        <!-- Sections rail -->
        <aside class="sb-rail" aria-label="Sections">
          <div class="sb-rail-top">
            <div class="sb-search">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
              <input type="search" id="sbRailSearch" placeholder="Search sections" aria-label="Search sections" autocomplete="off">
            </div>
            <div class="sb-chips" role="group" aria-label="Filter by status">
              <button type="button" class="sb-chip is-on" data-filter="" aria-pressed="true">All</button>
              <button type="button" class="sb-chip" data-filter="todo" aria-pressed="false">To do</button>
              <button type="button" class="sb-chip" data-filter="pending" aria-pressed="false">Pending</button>
              <button type="button" class="sb-chip" data-filter="approved" aria-pressed="false">Approved</button>
            </div>
          </div>
          <div class="sb-rail-list" id="sbRailList">
            <?php $last_grade = null; foreach ($rail as $s):
              [$k, $l] = sched_status_view($s['schedule_status'], (int)$s['timed_count']);
              $tot = (int)$s['subject_count']; $rdy = (int)$s['ready_count'];
              $group = in_array($k, ['pending', 'approved'], true) ? $k : 'todo';
              $label = sched_section_label($s['section_name'], $s['strand']);
              if ($s['grade_level'] !== $last_grade): $last_grade = $s['grade_level']; ?>
                <div class="sb-grade" data-grade="<?= $e($last_grade) ?>">Grade <?= $e($last_grade) ?></div>
              <?php endif; ?>
              <a class="sb-sec" href="?semester=<?= $semester ?>&section_id=<?= (int)$s['section_id'] ?>"
                 data-grade="<?= $e($s['grade_level']) ?>" data-group="<?= $group ?>" data-name="<?= $e(strtolower($label . ' ' . $s['room'])) ?>"
                 <?= $selected && (int)$selected['section_id'] === (int)$s['section_id'] ? 'aria-current="page"' : '' ?>>
                <div class="sb-sec-top">
                  <span class="sb-sec-name" title="<?= $e($label) ?>"><?= $e($label) ?></span>
                  <span class="sb-pill sb-pill--<?= $k ?>"><?= $e($l) ?></span>
                </div>
                <div class="sb-sec-meta">
                  <span class="sb-prog" aria-hidden="true"><i style="width:<?= $tot ? round($rdy / $tot * 100) : 0 ?>%"></i></span>
                  <span><?= $tot ? "$rdy/$tot ready" : 'No subjects' ?></span>
                </div>
              </a>
            <?php endforeach; ?>
            <p class="sb-rail-empty" id="sbRailEmpty" <?= $rail ? 'hidden' : '' ?>><?= $rail ? 'No sections match.' : 'No approved sections yet.' ?></p>
          </div>
        </aside>

        <!-- Builder -->
        <section class="sb-main" aria-label="Schedule builder">
          <?php if (!$selected): ?>
            <div class="sb-empty">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
              <h2>Pick a section to start</h2>
              <p>Choose a section from the list to build its Semester <?= $semester ?> timetable.</p>
            </div>
          <?php else:
            $sec_label = sched_section_label($selected['section_name'], $selected['strand']);
          ?>
            <div class="sb-main-head">
              <div class="sb-title-row">
                <h2 class="sb-title"><?= $e($sec_label) ?></h2>
                <span class="sb-pill sb-pill--<?= $st_key ?>" id="sbStatusPill"><?= $e($st_label) ?></span>
              </div>
              <div class="sb-facts">
                <span>Grade <b><?= $e($selected['grade_level']) ?></b></span>
                <span><span class="strand-badge strand-badge--<?= strand_badge_class($selected['strand']) ?>"><?= $e($selected['strand']) ?></span></span>
                <span>SY <b><?= $e($selected['school_year']) ?></b></span>
                <span>Homeroom <b><?= $e($selected['room'] ?: '—') ?></b></span>
                <span>Semester <b><?= $semester ?></b></span>
                <?php if ($selected['schedule_request_id']): ?><span>Request <b><?= $e($selected['schedule_request_id']) ?></b></span><?php endif; ?>
              </div>
              <?php if ($payload['rows']): ?>
                <div class="sb-stats" aria-live="polite">
                  <div class="sb-stat"><b id="sbReady">0/0</b> ready <span class="sb-prog"><i id="sbReadyBar" style="width:0"></i></span></div>
                  <div class="sb-stat" id="sbClashStat"><b id="sbClashes">0</b> <span id="sbClashWord">clashes</span></div>
                  <div class="sb-stat"><b id="sbHours">0</b> hrs / week</div>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($selected['schedule_status'] === 'rejected' && $selected['schedule_rejection_reason']): ?>
              <div class="sb-banner sb-banner--bad"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
                <div><b>Returned by the coordinator:</b> <?= $e($selected['schedule_rejection_reason']) ?> Make the changes, then submit again.</div></div>
            <?php elseif ($selected['schedule_status'] === 'pending'): ?>
              <div class="sb-banner sb-banner--warn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 118 0v4"/></svg>
                <div>Submitted and waiting for coordinator review. Editing is locked until it's approved or returned.</div></div>
            <?php elseif ($selected['schedule_status'] === 'approved'): ?>
              <div class="sb-banner sb-banner--info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/></svg>
                <div>This schedule is approved. Saving any change sends it back to the coordinator for re-approval.</div></div>
            <?php endif; ?>
            <?php if ($payload['section']['seeding']): ?>
              <div class="sb-banner sb-banner--info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/></svg>
                <div>Semester 2 hasn't been set up yet. The subjects and teachers below come from Semester 1 — place them on the timetable, or copy Semester 1's times as a starting point.</div>
                <button type="button" class="sb-link" id="sbCopyS1">Copy Semester 1 times</button></div>
            <?php endif; ?>

            <?php if (!$payload['rows']): ?>
              <div class="sb-empty">
                <h2>No subjects for this section yet</h2>
                <p><?= $semester === 2 ? 'Set up Semester 1 first — Semester 2 starts from its subjects.' : 'Subjects are added when the section is approved. Check that the curriculum for this grade and strand is approved.' ?></p>
              </div>
            <?php else: ?>
              <div class="sb-toolbar">
                <div class="sb-view" role="group" aria-label="View">
                  <button type="button" data-view="grid" aria-pressed="true">Timetable</button>
                  <button type="button" data-view="list" aria-pressed="false">List</button>
                </div>
                <span class="sb-hint" id="sbHint"></span>
              </div>

              <div id="sbGridView">
                <div class="sb-tt-wrap"><div class="sb-tt" id="sbTT"></div></div>
                <div class="sb-tray" id="sbTray"></div>
              </div>
              <div id="sbListView" hidden>
                <div class="sb-cards-wrap" id="sbList"></div>
              </div>

              <noscript><p class="sb-empty">The schedule builder needs JavaScript turned on.</p></noscript>

              <?php if ($payload['section']['editable']): ?>
                <div class="sb-bar">
                  <div class="sb-bar-state" id="sbState"><span class="dot"></span><span id="sbStateText">All changes saved</span></div>
                  <button type="button" class="sch-btn sch-btn--ghost" id="sbDiscard" disabled>Discard</button>
                  <button type="button" class="sch-btn sch-btn--ghost" id="sbSave" disabled>Save draft</button>
                  <?php if ($selected['schedule_status'] !== 'approved'): ?>
                    <button type="button" class="sch-btn sch-btn--primary" id="sbSubmit" disabled>Submit for approval</button>
                  <?php endif; ?>
                </div>
                <form method="POST" id="sbForm" hidden>
                  <input type="hidden" name="section_id" value="<?= (int)$selected['section_id'] ?>">
                  <input type="hidden" name="semester" value="<?= $semester ?>">
                  <input type="hidden" name="save_schedule" value="1">
                  <input type="hidden" name="then_submit" id="sbThenSubmit" value="0">
                  <div id="sbFields"></div>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>

  <?php if ($payload && $payload['rows']): ?>
  <!-- Side panel: one subject — its teacher and all of its weekly classes -->
  <div class="sb-scrim" id="sbScrim" hidden></div>
  <aside class="sb-panel" id="sbPanel" role="dialog" aria-modal="true" aria-labelledby="sbPanelTitle" hidden>
    <div class="sb-panel-head">
      <div>
        <h2 id="sbPanelTitle">Subject</h2>
        <p id="sbPanelSub"></p>
      </div>
      <button type="button" class="sb-x" id="sbPanelClose" aria-label="Close">&times;</button>
    </div>
    <div class="sb-panel-body">
      <div class="sb-f">
        <label for="pTeacher">Teacher</label>
        <select id="pTeacher" data-fk="p-t"></select>
        <p class="sb-f-help">Teachers already booked during any of these classes are marked busy.</p>
      </div>
      <div class="sb-f">
        <label>Classes this week <span style="font-weight:400;color:var(--sb-muted)">(one per day)</span></label>
        <div id="pMeetings"></div>
        <button type="button" class="sb-add" id="pAdd" data-fk="p-add">+ Add class</button>
        <button type="button" class="sb-link" id="pPlace">Place on timetable</button>
      </div>
      <div class="sb-checks" id="pChecks" aria-live="polite"></div>
    </div>
    <div class="sb-panel-foot">
      <button type="button" class="sch-btn sch-btn--primary" id="pDone">Done</button>
    </div>
  </aside>

  <script type="application/json" id="schedData"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>

<script>
// ── Sections rail: search + status chips ──────────────────────────────────────
(function () {
  const list = document.getElementById('sbRailList');
  if (!list) return;
  const search = document.getElementById('sbRailSearch');
  const chips = [...document.querySelectorAll('.sb-chip')];
  let group = '';
  function apply() {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    const gradeShown = {};
    list.querySelectorAll('.sb-sec').forEach(a => {
      const ok = (!q || a.dataset.name.includes(q)) && (!group || a.dataset.group === group);
      a.hidden = !ok;
      if (ok) { shown++; gradeShown[a.dataset.grade] = true; }
    });
    list.querySelectorAll('.sb-grade').forEach(g => { g.hidden = !gradeShown[g.dataset.grade]; });
    const empty = document.getElementById('sbRailEmpty');
    if (empty && list.querySelector('.sb-sec')) empty.hidden = shown > 0;
  }
  search.addEventListener('input', apply);
  chips.forEach(c => c.addEventListener('click', () => {
    group = c.dataset.filter;
    chips.forEach(x => { const on = x === c; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on); });
    apply();
  }));
  const cur = list.querySelector('[aria-current="page"]');
  if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
})();

// ── Schedule builder ──────────────────────────────────────────────────────────
// State: one entry per subject — { id, name, t (teacher), m: [{d, s, e, r}], s1 }
// where m holds that subject's weekly classes (at most one per day). Every
// change re-renders the timetable, the list, the panel and the summary from
// this state; focus and caret are restored so typing is never interrupted.
(function () {
  const dataEl = document.getElementById('schedData');
  if (!dataEl) return;
  const D = JSON.parse(dataEl.textContent);
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
  const BOUNDS = D.bounds;                          // "07:00:00" … "17:00:00", every 30 min
  const STARTS = BOUNDS.slice(0, -1), ENDS = BOUNDS.slice(1);
  const toMin = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const DAY0 = toMin(BOUNDS[0]), DAY_END = toMin(BOUNDS[BOUNDS.length - 1]);
  const HOUR_PX = 52, HOURS = (DAY_END - DAY0) / 60;
  const px = t => (toMin(t) - DAY0) / 60 * HOUR_PX;
  const label = t => { if (!t) return ''; let [h, m] = t.split(':').map(Number); const ap = h < 12 ? 'AM' : 'PM'; h = h % 12 || 12; return h + ':' + String(m).padStart(2, '0') + ' ' + ap; };
  const short = t => label(t).replace(':00', '').replace(' ', '');
  const endFor = (start, dur) => { const want = Math.min(toMin(start) + dur, DAY_END); return ENDS.find(t => toMin(t) >= want) || ENDS[ENDS.length - 1]; };
  // Subject colours: one cohesive family around the brand green (greens,
  // teals, sage, slate) — [tint background, accent bar / dark text]. Assigned
  // by position in the list; close enough to feel calm, distinct enough to
  // tell neighbouring blocks apart.
  const PALETTE = [['#E6F2EB','#2F6B4F'],['#E3F0F0','#2A6666'],['#EDF1E4','#56692F'],['#E5ECF2','#3A5A78'],
                   ['#E1F0EA','#1F6E57'],['#EBEEEA','#4A5C52'],['#E6EFF3','#2E6378'],['#EEF0E8','#65683F']];
  const TEACHERS = new Map(D.teachers);
  const editable = !!D.section.editable;
  const homeroom = D.section.room || '';
  const roomKey = r => String(r || '').trim().toLowerCase();

  const cloneRows = rs => rs.map(r => ({ ...r, m: (r.m || []).map(x => ({ ...x })) }));
  const byDay = (a, b) => DAYS.indexOf(a.d) - DAYS.indexOf(b.d) || String(a.s).localeCompare(String(b.s));
  const ORIGINAL = cloneRows(D.original);
  let rows = cloneRows(D.rows);
  const colorize = () => rows.forEach((r, i) => { r.color = PALETTE[i % PALETTE.length]; });
  colorize();

  let view = 'grid', placing = null, activeId = null, focusMi = null, submitting = false, hintMsg = '';
  const openCards = new Set();
  const failedId = D.failedRow != null && D.rows[D.failedRow] ? D.rows[D.failedRow].id : null;
  const rowById = id => rows.find(x => x.id === id);

  const complete = m => !!(m.d && m.s && m.e);
  const effRoom = m => (m.r || '').trim() || homeroom;
  const norm = r => JSON.stringify([r.t || null, [...r.m].sort(byDay).map(m => [m.d || null, m.s || null, m.e || null, (m.r || '').trim()])]);
  const dirtyIds = () => rows.filter(r => { const o = ORIGINAL.find(x => x.id === r.id); return !o || norm(o) !== norm(r); }).map(r => r.id);

  // Problems with one class meeting (all blocking).
  function meetingIssues(r, i) {
    const m = r.m[i], out = [];
    if (!complete(m)) return ['Pick a day, start and end time.'];
    const sm = toMin(m.s), em = toMin(m.e);
    if (em <= sm) out.push('Ends before it starts.');
    if (r.m.some((o, j) => j !== i && o.d === m.d)) out.push('Already has a class on ' + m.d + ' — one per day.');
    D.busy.forEach(b => {
      if (b[2] !== m.d || !(sm < b[4] && b[3] < em)) return;
      if (r.t && b[0] === r.t) out.push('Teacher is busy — ' + b[5] + '.');
      if (effRoom(m) && b[1] && roomKey(b[1]) === roomKey(effRoom(m))) out.push('Room ' + effRoom(m) + ' is taken — ' + b[5] + '.');
    });
    rows.forEach(o => o.m.forEach((om, j) => {
      if ((o === r && j === i) || !complete(om) || om.d !== m.d) return;
      if (toMin(om.s) < em && sm < toMin(om.e)) out.push(o === r ? 'Overlaps its own ' + om.d + ' class.' : 'Overlaps ' + o.name + ' (same section).');
    }));
    return [...new Set(out)];
  }
  // Subject level: 'bad' (a class has a problem), 'warn' (incomplete), 'ok'.
  function subjectState(r) {
    const bad = r.m.some((m, i) => complete(m) && meetingIssues(r, i).length) || r.m.some(m => !complete(m));
    const warns = [];
    if (!r.m.length) warns.push('No classes yet.');
    if (!r.t) warns.push('No teacher assigned.');
    return { level: bad ? 'bad' : (warns.length ? 'warn' : 'ok'), warns };
  }
  const ready = r => !!r.t && r.m.length > 0 && r.m.every(complete);
  const summaryText = r => r.m.length ? [...r.m].filter(complete).sort(byDay).map(m => m.d + ' ' + short(m.s) + '–' + short(m.e)).join(' · ') || 'Class times not set' : 'No classes yet';

  // ── Render helpers ──────────────────────────────────────────────────────
  const opt = (v, text, sel, dis) => '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + (dis ? ' disabled' : '') + '>' + esc(text) + '</option>';
  const teacherOptions = (r, busyT) => opt('', 'No teacher', !r.t) + D.teachers.map(t =>
      opt(t[0], t[1] + (busyT && busyT.has(t[0]) ? ' — busy (' + busyT.get(t[0]) + ')' : ''), r.t === t[0])).join('');

  function meetingRow(r, i, fkp) {
    const m = r.m[i], dis = editable ? '' : ' disabled';
    const used = new Set(r.m.filter((o, j) => j !== i).map(o => o.d));
    const iss = meetingIssues(r, i);
    return '<div class="sb-mrow' + (iss.length ? ' is-bad' : '') + (activeId === r.id && focusMi === i ? ' is-focus' : '') + '" data-id="' + r.id + '" data-mi="' + i + '">'
      + '<div class="sb-mgrid">'
      + '<select data-k="d" data-fk="' + fkp + i + '-d" aria-label="Day"' + dis + '>' + opt('', 'Day', !m.d) + DAYS.map(d => opt(d, d, m.d === d, used.has(d))).join('') + '</select>'
      + '<select data-k="s" data-fk="' + fkp + i + '-s" aria-label="Start"' + dis + '>' + opt('', 'Start', !m.s) + STARTS.map(t => opt(t, label(t), m.s === t)).join('') + '</select>'
      + '<span class="sb-dash">–</span>'
      + '<select data-k="e" data-fk="' + fkp + i + '-e" aria-label="End"' + dis + '>' + opt('', 'End', !m.e) + ENDS.map(t => opt(t, label(t), m.e === t)).join('') + '</select>'
      + '<input type="text" data-k="r" data-fk="' + fkp + i + '-r" maxlength="50" value="' + esc(m.r || '') + '" placeholder="' + esc(homeroom || 'Homeroom') + '" aria-label="Room"' + dis + '>'
      + (editable ? '<button type="button" class="sb-mdel" data-del data-fk="' + fkp + i + '-x" aria-label="Remove this class" title="Remove this class">✕</button>' : '<span></span>')
      + '</div>'
      + iss.map(t => '<div class="sb-issue">⚠ ' + esc(t) + '</div>').join('')
      + '</div>';
  }

  // ── Timetable ───────────────────────────────────────────────────────────
  function renderGrid() {
    const tt = $('sbTT'), H = HOURS * HOUR_PX;
    const placingRow = placing ? rowById(placing) : null;
    const usedDays = new Set(placingRow ? placingRow.m.map(m => m.d) : []);
    let h = '<div></div>' + DAYS.map(d => '<div class="sb-tt-dh">' + d + '</div>').join('');
    h += '<div class="sb-tt-axis" style="height:' + H + 'px">';
    BOUNDS.forEach(t => { if (t.slice(3, 5) === '00') h += '<span style="top:' + px(t) + 'px">' + short(t) + '</span>'; });
    h += '</div>';
    DAYS.forEach(day => {
      h += '<div class="sb-tt-day' + (usedDays.has(day) ? ' is-used' : '') + '" style="height:' + H + 'px" data-day="' + day + '">';
      if (editable && placing && !usedDays.has(day)) {
        STARTS.forEach(s => {
          h += '<button type="button" class="sb-cell" style="top:' + px(s) + 'px" data-day="' + day + '" data-start="' + s + '" data-fk="c-' + day + s + '" aria-label="Place on ' + day + ' at ' + label(s) + '"></button>';
        });
      }
      // This day's classes, laid out in lanes so overlaps sit side by side.
      const items = [];
      rows.forEach(r => r.m.forEach((m, i) => { if (complete(m) && m.d === day) items.push({ r, m, i }); }));
      items.sort((a, b) => toMin(a.m.s) - toMin(b.m.s));
      const laneEnd = [];
      items.forEach(it => {
        let lane = laneEnd.findIndex(e => e <= toMin(it.m.s));
        if (lane < 0) { lane = laneEnd.length; laneEnd.push(0); }
        laneEnd[lane] = toMin(it.m.e); it.lane = lane;
      });
      const lanes = Math.max(1, laneEnd.length);
      items.forEach(({ r, m, i, lane }) => {
        const top = px(m.s), ht = Math.max(22, (toMin(m.e) - toMin(m.s)) / 60 * HOUR_PX - 3);
        const clash = meetingIssues(r, i).length > 0;
        const tname = r.t ? TEACHERS.get(r.t) || '' : 'No teacher';
        h += '<button type="button" class="sb-block' + (clash ? ' is-clash' : '') + (r.id === activeId && i === focusMi ? ' is-active' : '') + '" data-id="' + r.id + '" data-mi="' + i + '" data-fk="b-' + r.id + '-' + i + '"'
          + ' style="top:' + (top + 1) + 'px;height:' + ht + 'px;left:calc(' + (lane / lanes * 100) + '% + 3px);width:calc(' + (100 / lanes) + '% - 6px);background:' + r.color[0] + ';border-left-color:' + r.color[1] + '"'
          + ' title="' + esc(r.name + ' · ' + m.d + ' ' + label(m.s) + '–' + label(m.e) + ' · ' + tname + (effRoom(m) ? ' · ' + effRoom(m) : '')) + '">'
          + '<span class="n">' + esc(r.name) + '</span>'
          + (ht > 34 ? '<span class="m">' + esc(short(m.s) + '–' + short(m.e) + ' · ' + tname) + '</span>' : '')
          + '</button>';
      });
      h += '</div>';
    });
    tt.innerHTML = h;
    tt.classList.toggle('sb-placing', !!placing);
  }

  function renderTray() {
    const tray = $('sbTray');
    const un = rows.filter(r => !r.m.length);
    if (placing) {
      const r = rowById(placing);
      tray.innerHTML = '<div class="sb-tray-head"><b>Adding a class for ' + esc(r.name) + '.</b> Click a free time on the grid'
        + (r.m.length ? ' (days it already meets are greyed out)' : '') + ' — or press Esc to cancel.</div>';
      return;
    }
    if (!un.length) {
      tray.innerHTML = '<span class="sb-tray-done">✓ Every subject has at least one class. Click a block to edit it or add more classes.</span>';
      return;
    }
    tray.innerHTML = '<div class="sb-tray-head"><b>' + un.length + (un.length === 1 ? ' subject has' : ' subjects have') + ' no classes yet.</b> '
      + (editable ? 'Pick one, then click a time on the grid.' : '') + '</div>'
      + '<div class="sb-tray-list">' + un.map(r =>
        '<button type="button" class="sb-tray-chip" data-id="' + r.id + '" data-fk="t-' + r.id + '"'
        + ' style="background:' + r.color[0] + ';border-left-color:' + r.color[1] + '" title="' + esc(r.name) + '"' + (editable ? '' : ' disabled') + '>'
        + esc(r.name) + '</button>').join('') + '</div>';
  }

  // ── List (expandable subject cards) ─────────────────────────────────────
  function renderList() {
    const dis = editable ? '' : ' disabled';
    $('sbList').innerHTML = rows.map(r => {
      const st = subjectState(r), open = openCards.has(r.id);
      const ic = st.level === 'ok' ? '✓' : (st.level === 'bad' ? '⚠' : '!');
      const n = r.m.length;
      return '<div class="sb-card' + (open ? ' is-open' : '') + (st.level === 'bad' ? ' is-bad' : '') + (r.id === failedId ? ' is-failed' : '') + '" data-id="' + r.id + '">'
        + '<div class="sb-card-head">'
        +   '<span class="sb-ic sb-ic--' + st.level + '" aria-hidden="true">' + ic + '</span>'
        +   '<button type="button" class="sb-card-name" data-toggle data-fk="lt-' + r.id + '" aria-expanded="' + open + '">'
        +     '<span class="n" title="' + esc(r.name) + '"><span class="sb-dot" style="display:inline-block;margin-right:7px;background:' + r.color[1] + '"></span>' + esc(r.name) + '</span>'
        +     '<span class="s">' + esc(summaryText(r)) + '</span></button>'
        +   '<select data-k="t" data-fk="l-' + r.id + '-t" aria-label="Teacher for ' + esc(r.name) + '"' + dis + '>' + teacherOptions(r) + '</select>'
        +   '<span class="sb-count">' + n + (n === 1 ? ' class' : ' classes') + '/wk</span>'
        +   '<button type="button" class="sb-tog" data-toggle aria-label="' + (open ? 'Collapse' : 'Expand') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg></button>'
        + '</div>'
        + '<div class="sb-card-body">'
        +   (n ? r.m.map((m, i) => meetingRow(r, i, 'l-' + r.id + '-')).join('') : '<div class="sb-empty-note">No classes yet.</div>')
        +   st.warns.filter(w => w !== 'No classes yet.').map(w => '<div class="sb-issue sb-issue--warn">' + esc(w) + '</div>').join('')
        +   (editable ? '<button type="button" class="sb-add" data-add data-fk="la-' + r.id + '"' + (r.m.length >= DAYS.length ? ' disabled' : '') + '>+ Add class</button>' : '')
        + '</div></div>';
    }).join('');
  }

  // ── Side panel ──────────────────────────────────────────────────────────
  const panel = $('sbPanel'), scrim = $('sbScrim');
  let lastFocus = null;
  function openPanel(id, mi) {
    if (!rowById(id)) return;
    if (panel.hidden) lastFocus = document.activeElement;
    activeId = id; focusMi = mi == null ? null : mi;
    panel.hidden = false; scrim.hidden = false;
    render();
    const target = focusMi != null ? panel.querySelector('[data-fk="p-' + focusMi + '-d"]') : null;
    setTimeout(() => (target && editable ? target : (editable ? $('pTeacher') : $('pDone'))).focus(), 20);
  }
  function renderPanel() {
    const r = rowById(activeId); if (!r) return;
    $('sbPanelTitle').textContent = r.name;
    $('sbPanelSub').textContent = r.m.length + (r.m.length === 1 ? ' class' : ' classes') + ' a week' + (r.t ? ' · ' + (TEACHERS.get(r.t) || '') : '');
    // Teachers already booked during any of this subject's classes.
    const busyT = new Map();
    r.m.filter(complete).forEach(m => {
      const sm = toMin(m.s), em = toMin(m.e);
      D.busy.forEach(b => { if (b[0] && b[2] === m.d && sm < b[4] && b[3] < em) busyT.set(b[0], b[5]); });
      rows.forEach(o => { if (o !== r && o.t) o.m.forEach(om => { if (complete(om) && om.d === m.d && toMin(om.s) < em && sm < toMin(om.e)) busyT.set(o.t, o.name + ' · this section'); }); });
    });
    $('pTeacher').innerHTML = teacherOptions(r, busyT);
    $('pTeacher').disabled = !editable;
    $('pMeetings').dataset.id = r.id;
    $('pMeetings').innerHTML = r.m.length ? r.m.map((m, i) => meetingRow(r, i, 'p-')).join('') : '<div class="sb-empty-note">No classes yet — add one below, or place it on the timetable.</div>';
    $('pAdd').hidden = $('pPlace').hidden = !editable;
    $('pAdd').disabled = $('pPlace').disabled = r.m.length >= DAYS.length;
    const st = subjectState(r);
    $('pChecks').innerHTML = st.level === 'ok'
      ? '<div class="sb-check sb-check--ok">✓ Ready — no clashes.</div>'
      : st.warns.map(w => '<div class="sb-check sb-check--warn">' + esc(w) + '</div>').join('');
  }
  function closePanel() {
    if (panel.hidden) return;
    panel.hidden = true; scrim.hidden = true; activeId = null; focusMi = null;
    render();
    lastFocus?.focus?.();
  }

  // ── Header stats + action bar ───────────────────────────────────────────
  function renderSummary() {
    const total = rows.length, nReady = rows.filter(ready).length;
    let nClash = 0, mins = 0;
    rows.forEach(r => r.m.forEach((m, i) => {
      if (!complete(m)) return;
      mins += Math.max(0, toMin(m.e) - toMin(m.s));
      if (meetingIssues(r, i).length) nClash++;
    }));
    const incomplete = rows.some(r => r.m.some(m => !complete(m)));
    $('sbReady').textContent = nReady + '/' + total;
    $('sbReadyBar').style.width = (total ? nReady / total * 100 : 0) + '%';
    $('sbClashes').textContent = nClash;
    $('sbClashWord').textContent = nClash === 1 ? 'clash' : 'clashes';
    $('sbClashStat').classList.toggle('is-bad', nClash > 0);
    const hours = mins / 60;
    $('sbHours').textContent = Number.isInteger(hours) ? hours : hours.toFixed(1);

    $('sbHint').textContent = hintMsg || (!editable ? 'Read-only while this schedule is under review.'
      : (view === 'grid' ? 'Click a class to edit it. A subject can meet on several days.' : 'Open a subject to add or change its classes.'));

    if (!$('sbState')) return;
    const n = dirtyIds().length;
    $('sbState').classList.toggle('is-dirty', n > 0 || (D.section.seeding && rows.some(r => r.m.length)));
    $('sbStateText').textContent = n ? n + (n === 1 ? ' subject changed' : ' subjects changed') + ' — not saved'
      : (D.section.seeding ? 'Semester 2 not saved yet' : 'All changes saved');
    $('sbDiscard').disabled = n === 0;
    $('sbSave').disabled = !(n > 0 || D.section.seeding) || incomplete;
    $('sbSave').title = incomplete ? 'Finish or remove the classes that are missing a day or time.' : '';
    $('sbSave').textContent = D.section.status === 'approved' ? 'Save & send for re-approval' : 'Save draft';
    const sub = $('sbSubmit');
    if (sub) {
      sub.disabled = nReady !== total || nClash > 0 || incomplete;
      sub.title = nReady !== total || incomplete ? 'Every subject needs a teacher and at least one complete class.' : (nClash ? 'Fix the clashes first.' : '');
      sub.textContent = n ? 'Save & submit for approval' : 'Submit for approval';
    }
  }

  // Re-render everything, keeping keyboard focus (and caret) where it was.
  function render() {
    const a = document.activeElement, fk = a && a.dataset ? a.dataset.fk : null;
    const caret = a && typeof a.selectionStart === 'number' ? [a.selectionStart, a.selectionEnd] : null;
    renderGrid(); renderTray(); renderList();
    if (!panel.hidden) renderPanel();
    renderSummary();
    if (fk) {
      const el = document.querySelector('[data-fk="' + fk + '"]');
      if (el && el !== document.activeElement) {
        el.focus({ preventScroll: true });
        if (caret && el.setSelectionRange) try { el.setSelectionRange(caret[0], caret[1]); } catch (_) {}
      }
    }
  }
  const flash = msg => { hintMsg = msg; renderSummary(); setTimeout(() => { hintMsg = ''; renderSummary(); }, 3500); };

  // ── Mutations ───────────────────────────────────────────────────────────
  function setMeeting(r, i, k, v) {
    const m = r.m[i]; if (!m) return;
    if (k === 's' && v) {
      // Moving the start keeps the class length; a first start defaults to 1 hour.
      const dur = m.s && m.e ? toMin(m.e) - toMin(m.s) : 60;
      m.s = v; m.e = endFor(v, Math.max(30, dur));
    } else {
      m[k] = k === 'r' ? v : (v || null);
    }
  }
  // Next day this subject doesn't meet yet, after its last class (wrapping).
  function nextFreeDay(r) {
    const used = new Set(r.m.map(m => m.d));
    const last = r.m.length ? Math.max(...r.m.map(m => DAYS.indexOf(m.d))) : -1;
    for (let k = 1; k <= DAYS.length; k++) { const d = DAYS[(last + k + DAYS.length) % DAYS.length]; if (!used.has(d)) return d; }
    return null;
  }
  // "+ Add class": same time as the subject's first class, on the next free day.
  function addMeeting(r) {
    if (r.m.length >= DAYS.length) return -1;
    const first = r.m.find(complete);
    r.m.push({ d: nextFreeDay(r), s: first ? first.s : null, e: first ? first.e : null, r: first ? first.r || '' : '' });
    return r.m.length - 1;
  }
  function placeAt(day, start) {
    const r = rowById(placing); if (!r) return;
    if (r.m.some(m => m.d === day)) { flash(r.name + ' already has a class on ' + day + '.'); return; }
    const first = r.m.find(complete);
    const dur = first ? toMin(first.e) - toMin(first.s) : 60;
    r.m.push({ d: day, s: start, e: endFor(start, dur), r: first ? first.r || '' : '' });
    placing = null;
    openPanel(r.id, r.m.length - 1);
  }

  // One handler for meeting rows / teacher selects / add buttons, in the
  // list cards and the side panel alike.
  function onChange(e) {
    const el = e.target;
    const holder = el.closest('[data-id]'); if (!holder || !el.dataset.k) return;
    const r = rowById(+holder.dataset.id); if (!r) return;
    if (el.dataset.k === 't') r.t = el.value ? +el.value : null;
    else { const mrow = el.closest('[data-mi]'); if (!mrow) return; setMeeting(r, +mrow.dataset.mi, el.dataset.k, el.value); }
    render();
  }
  function onClick(e) {
    const holder = e.target.closest('[data-id]'); if (!holder) return;
    const r = rowById(+holder.dataset.id); if (!r) return;
    if (e.target.closest('[data-del]')) {
      const mi = +e.target.closest('[data-mi]').dataset.mi;
      r.m.splice(mi, 1);
      if (activeId === r.id) focusMi = null;
      render();
    } else if (e.target.closest('[data-add]')) {
      const mi = addMeeting(r); openCards.add(r.id); render();
      document.querySelector('[data-fk="l-' + r.id + '-' + mi + '-d"]')?.focus();
    } else if (e.target.closest('[data-toggle]')) {
      openCards.has(r.id) ? openCards.delete(r.id) : openCards.add(r.id);
      render();
    }
  }
  $('sbList').addEventListener('change', onChange);
  $('sbList').addEventListener('input', e => { if (e.target.dataset.k === 'r') onChange(e); });
  $('sbList').addEventListener('click', onClick);
  $('pMeetings').addEventListener('change', onChange);
  $('pMeetings').addEventListener('input', e => { if (e.target.dataset.k === 'r') onChange(e); });
  $('pMeetings').addEventListener('click', onClick);
  $('pTeacher').addEventListener('change', e => { const r = rowById(activeId); if (r) { r.t = e.target.value ? +e.target.value : null; render(); } });
  $('pAdd').addEventListener('click', () => {
    const r = rowById(activeId); if (!r) return;
    const mi = addMeeting(r); focusMi = mi; render();
    panel.querySelector('[data-fk="p-' + mi + '-d"]')?.focus();
  });
  $('pPlace').addEventListener('click', () => {
    const id = activeId; closePanel();
    placing = id; setView('grid'); render();
  });

  $('sbTT').addEventListener('click', e => {
    const cell = e.target.closest('.sb-cell');
    if (cell && placing) { placeAt(cell.dataset.day, cell.dataset.start); return; }
    const block = e.target.closest('.sb-block');
    if (block) openPanel(+block.dataset.id, +block.dataset.mi);
  });
  $('sbTray').addEventListener('click', e => {
    const chip = e.target.closest('.sb-tray-chip'); if (!chip || !editable) return;
    const id = +chip.dataset.id;
    placing = placing === id ? null : id;
    render();
  });
  $('pDone').addEventListener('click', closePanel);
  $('sbPanelClose').addEventListener('click', closePanel);
  scrim.addEventListener('click', closePanel);
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (!panel.hidden) closePanel();
    else if (placing) { placing = null; render(); }
  });

  // View toggle (remembered per browser; phones default to the list).
  const viewBtns = [...document.querySelectorAll('.sb-view button')];
  function setView(v) {
    view = v;
    viewBtns.forEach(b => b.setAttribute('aria-pressed', b.dataset.view === v));
    $('sbGridView').hidden = v !== 'grid';
    $('sbListView').hidden = v !== 'list';
    try { localStorage.setItem('sbView', v); } catch (_) {}
    renderSummary();
  }
  viewBtns.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));

  $('sbCopyS1')?.addEventListener('click', () => {
    rows.forEach(r => { if (r.s1 && r.s1.length) r.m = r.s1.map(x => ({ ...x })); });
    render();
  });

  // ── Save / submit ───────────────────────────────────────────────────────
  function post(thenSubmit) {
    $('sbThenSubmit').value = thenSubmit ? '1' : '0';
    let h = '';
    rows.forEach(r => {
      h += '<input type="hidden" name="subject_id[]" value="' + r.id + '"><input type="hidden" name="teacher_id[]" value="' + (r.t || '') + '">';
      r.m.forEach(m => {
        h += '<input type="hidden" name="m_subject[]" value="' + r.id + '">'
          + '<input type="hidden" name="m_day[]" value="' + esc(m.d || '') + '">'
          + '<input type="hidden" name="m_start[]" value="' + esc(m.s || '') + '">'
          + '<input type="hidden" name="m_end[]" value="' + esc(m.e || '') + '">'
          + '<input type="hidden" name="m_room[]" value="' + esc((m.r || '').trim()) + '">';
      });
    });
    $('sbFields').innerHTML = h;
    submitting = true;
    document.getElementById('pageLoader')?.classList.add('show');
    $('sbForm').submit();
  }
  function confirmThen(title, text, go) {
    if (typeof Swal === 'undefined') { if (window.confirm(text)) go(); return; }
    Swal.fire({ title, text, icon: 'question', showCancelButton: true, confirmButtonColor: '#1E4D3B', cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, continue', cancelButtonText: 'Cancel' }).then(res => { if (res.isConfirmed) go(); });
  }
  $('sbSave')?.addEventListener('click', () => {
    if (D.section.status === 'approved') {
      confirmThen('Save changes?', 'This schedule is approved. Saving sends it back to the coordinator for re-approval.', () => post(false));
    } else post(false);
  });
  $('sbSubmit')?.addEventListener('click', () => {
    confirmThen('Submit for approval?', 'The whole Semester ' + D.sem + ' schedule for ' + D.section.label + ' goes to the coordinator. Editing is locked until it\'s reviewed.', () => post(true));
  });
  $('sbDiscard')?.addEventListener('click', () => {
    confirmThen('Discard changes?', 'Go back to the last saved version of this schedule?', () => {
      rows = cloneRows(ORIGINAL); colorize();
      placing = null; closePanel(); render();
    });
  });
  window.addEventListener('beforeunload', e => {
    if (!submitting && editable && dirtyIds().length > 0) { e.preventDefault(); e.returnValue = ''; }
  });

  // ── First paint ─────────────────────────────────────────────────────────
  rows.forEach(r => { if (subjectState(r).level === 'bad' || r.id === failedId) openCards.add(r.id); });
  let saved = null;
  try { saved = localStorage.getItem('sbView'); } catch (_) {}
  render();
  setView(saved === 'list' || saved === 'grid' ? saved : (window.innerWidth < 720 ? 'list' : 'grid'));
  if (failedId != null) openPanel(failedId, null);
})();
</script>

</body>
</html>
