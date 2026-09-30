<?php
/* ============================================================
   approvals.php — Coordinator Approvals (consolidated)
   ------------------------------------------------------------
   Reviewer-only page (role='admin' or department='coordinator').
   Consolidates the three pending-request queues that already
   live on sections.php / curriculum.php / scheduling.php into
   one dashboard: Sections | Curriculum | Schedule.

   Rows open a detail drawer; Approve / Reject live in the drawer
   footer (reject requires a reason). Ready rows can also be
   approved in bulk. Every decision runs the same guarded UPDATEs
   as those pages (same tables/columns/guards) — this page does
   not replace them, it's an additional consolidated entry point.
   ============================================================ */

session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../scheduler/_schedule_lib.php';

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$is_reviewer = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');

if (!$is_reviewer) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

// One-shot message left by the previous decision's redirect.
$flash = $_SESSION['approvals_flash'] ?? null;
unset($_SESSION['approvals_flash']);

/* ── Helpers ─────────────────────────────────────────────────────────────── */

// Display-only naming: "11-3" -> "ABM 11-3", "Stem 12-5" -> "STEM 12-5".
// The stored section_name is never changed.
function ap_label(string $name, ?string $strand): string {
    if (!$strand) return $name;
    if (preg_match('/^' . preg_quote($strand, '/') . '\b/i', $name)) {
        return $strand . substr($name, strlen($strand));
    }
    if (preg_match('/^\d{2}\b/', $name)) return $strand . ' ' . $name;
    return $name;
}

function ap_person(array $r): string {
    return $r['req_family'] ? trim($r['req_given'] . ' ' . $r['req_family']) : '';
}

function ap_ago(?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $d = time() - $t;
    if ($d < 60)    return 'Just now';
    if ($d < 3600)  return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' hr ago';
    $days = (int)floor($d / 86400);
    if ($days === 1)  return 'Yesterday';
    if ($days < 30)   return $days . ' days ago';
    return date('M j, Y', $t);
}

function ap_time_label(string $t): string {
    return date('g:i A', strtotime($t));
}

function ap_minutes(string $t): int {
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

// Schedule requests are reviewed one semester at a time (Semester 2 lives in
// the sections.schedule_*_sem2 shadow columns). Set below from ?sem= / req_sem.
$ap_sem = 1;
function ap_sem(): int { return $GLOBALS['ap_sem'] === 2 ? 2 : 1; }

/* ── Per-type query pieces ──────────────────────────────────────────────── */

// 'from' contains no WHERE; callers append their own. Every select aliases
// the same column names so rendering is type-agnostic.
function ap_parts(string $type): array {
    $req = 'req.family_name AS req_family, req.given_name AS req_given';
    if ($type === 'sections') {
        return [
            'id'      => 's.section_id',
            'name'    => 's.section_name',
            'status'  => 's.approval_status',
            'base'    => '1=1',
            'search'  => '(s.section_name LIKE ? OR req.family_name LIKE ? OR req.given_name LIKE ?)',
            'from'    => 'FROM sections s JOIN strands st ON st.strand_id = s.strand LEFT JOIN staff req ON req.user_id = s.requested_by',
            'select'  => "SELECT s.section_id AS id, s.section_name AS name, s.grade_level, s.strand AS strand_id, st.strand_code AS strand,
                                 s.room, s.capacity, s.school_year, s.approval_status AS status, s.rejection_reason,
                                 NULL AS submitted_at, s.reviewed_at AS decided_at, s.requested_by AS req_uid, $req",
        ];
    }
    if ($type === 'curriculum') {
        return [
            'id'      => 's.subject_id',
            'name'    => 's.subject_name',
            'status'  => 's.review_status',
            'base'    => '1=1',
            'search'  => '(s.subject_name LIKE ? OR req.family_name LIKE ? OR req.given_name LIKE ?)',
            'from'    => 'FROM subjects s JOIN strands st ON st.strand_id = s.strand LEFT JOIN staff req ON req.user_id = s.proposed_by',
            'select'  => "SELECT s.subject_id AS id, s.subject_name AS name, s.grade_level, s.strand AS strand_id, st.strand_code AS strand,
                                 s.subject_category AS category, s.school_year, s.review_status AS status, s.rejection_reason,
                                 NULL AS submitted_at, s.approved_at AS decided_at, s.proposed_by AS req_uid, $req",
        ];
    }
    $c = fn($b) => 's.' . sched_col($b, ap_sem());
    return [
        'id'      => 's.section_id',
        'name'    => 's.section_name',
        'status'  => $c('schedule_status'),
        // 'unscheduled' sections never submitted anything — not a request.
        'base'    => $c('schedule_status') . " <> 'unscheduled'",
        'search'  => '(s.section_name LIKE ? OR req.family_name LIKE ? OR req.given_name LIKE ?)',
        'from'    => 'FROM sections s JOIN strands st ON st.strand_id = s.strand LEFT JOIN staff req ON req.user_id = ' . $c('schedule_submitted_by'),
        'select'  => "SELECT s.section_id AS id, s.section_name AS name, s.grade_level, s.strand AS strand_id, st.strand_code AS strand,
                             s.room, s.school_year, {$c('schedule_status')} AS status, {$c('schedule_rejection_reason')} AS rejection_reason,
                             {$c('schedule_submitted_at')} AS submitted_at, {$c('schedule_reviewed_at')} AS decided_at,
                             {$c('schedule_submitted_by')} AS req_uid, $req",
    ];
}

function ap_fetch_row(mysqli $conn, string $type, int $id): ?array {
    $p = ap_parts($type);
    $stmt = $conn->prepare($p['select'] . ' ' . $p['from'] . ' WHERE ' . $p['id'] . ' = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) ap_normalize($type, $row);
    return $row;
}

// Curriculum stores 'for_review' where the others say 'pending'.
function ap_normalize(string $type, array &$row): void {
    if ($row['status'] === 'for_review') $row['status'] = 'pending';
    $row['label'] = $type === 'curriculum' ? $row['name'] : ap_label($row['name'], $row['strand']);
}

/* ── Detail + checks (drawer content, bulk eligibility) ─────────────────── */

function ap_detail(mysqli $conn, string $type, array $r): array {
    $d = ['facts' => [], 'issues' => [], 'slots' => [], 'scheduled' => null, 'total' => null, 'count' => null];
    // Subject (curriculum) requests show the grade only, without the strand.
    $d['facts'][] = $type === 'curriculum'
        ? ['Grade', 'Grade ' . $r['grade_level']]
        : ['Grade & strand', 'Grade ' . $r['grade_level'] . ' · ' . $r['strand']];

    if ($type === 'sections') {
        $d['facts'][] = ['Room', $r['room'] !== null && $r['room'] !== '' ? $r['room'] : '—'];
        $d['facts'][] = ['Capacity', (int)$r['capacity'] . ' students'];

        $core = strand_id($conn, 'CORE');
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM subjects WHERE grade_level = ? AND strand IN (?, ?) AND school_year = ? AND is_active = 1 AND review_status = 'approved'");
        $st->bind_param('siis', $r['grade_level'], $r['strand_id'], $core, $r['school_year']);
        $st->execute();
        $d['count'] = (int)$st->get_result()->fetch_assoc()['c'];
        $st->close();

        $sid = (int)$r['id'];
        $st = $conn->prepare("SELECT section_id FROM sections
                              WHERE section_name = ? AND school_year = ? AND section_id <> ?
                                AND is_active = 1 AND approval_status <> 'rejected' LIMIT 1");
        $st->bind_param('ssi', $r['name'], $r['school_year'], $sid);
        $st->execute();
        $dup = $st->get_result()->fetch_assoc();
        $st->close();
        if ($dup) {
            $d['issues'][] = ['kind' => 'conflict', 'title' => 'Name already in use',
                'text' => 'Another section in ' . $r['school_year'] . ' is already called "' . $r['label'] . '".'];
        }
        if ($d['count'] === 0) {
            $d['issues'][] = ['kind' => 'warn', 'title' => 'No subjects to add',
                'text' => 'No curriculum subjects exist for Grade ' . $r['grade_level'] . ' ' . $r['strand'] . ', so this section would start empty.'];
        }
    } elseif ($type === 'curriculum') {
        $d['facts'][] = ['Category', ucfirst($r['category'])];

        // Same school year only — a subject carried over from last year's
        // curriculum has the same name/grade/strand by design and is not a
        // duplicate (this used to flag every carried-over subject and block
        // bulk approval of a whole new curriculum).
        $sid = (int)$r['id'];
        $st = $conn->prepare("SELECT subject_id FROM subjects
                              WHERE subject_name = ? AND grade_level = ? AND strand = ? AND school_year = ? AND subject_id <> ?
                                AND review_status <> 'rejected' AND is_active = 1 LIMIT 1");
        $st->bind_param('ssisi', $r['name'], $r['grade_level'], $r['strand_id'], $r['school_year'], $sid);
        $st->execute();
        $dup = $st->get_result()->fetch_assoc();
        $st->close();
        if ($dup) {
            $d['issues'][] = ['kind' => 'conflict', 'title' => 'Duplicate subject',
                'text' => '"' . $r['name'] . '" already exists for Grade ' . $r['grade_level'] . ' ' . $r['strand'] . ' in ' . $r['school_year'] . '.'];
        }
    } else {
        $d = ap_schedule_detail($conn, $r, $d);
    }

    $d['facts'][] = ['School year', $r['school_year']];
    return $d;
}

function ap_schedule_detail(mysqli $conn, array $r, array $d): array {
    $section_id = (int)$r['id'];
    $sem        = ap_sem();

    // Subjects with their teacher, and every weekly class meeting of each.
    $rows = sched_fetch_rows($conn, $section_id, $sem);
    $tnames = [];
    $ids = array_filter(array_map(fn($x) => (int)$x['teacher_id'], $rows));
    if ($ids) {
        $res = $conn->query("SELECT teacher_id, CONCAT(given_name, ' ', family_name) AS n FROM teachers WHERE teacher_id IN (" . implode(',', $ids) . ")");
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $t) $tnames[(int)$t['teacher_id']] = $t['n'];
    }

    // Overlaps inside the section (students attend every class).
    $own = [];
    $flat = [];
    foreach ($rows as $row) foreach ($row['meetings'] as $m) $flat[] = [$row['subject_name'], $m];
    foreach ($flat as $a => [$na, $ma]) {
        foreach ($flat as $b => [$nb, $mb]) {
            if ($a < $b && $ma['day'] === $mb['day'] && $ma['start_time'] < $mb['end_time'] && $mb['start_time'] < $ma['end_time']) {
                $own[$na . '|' . $ma['day']] = true; $own[$nb . '|' . $mb['day']] = true;
                $d['issues'][] = ['kind' => 'conflict', 'title' => 'Overlap inside the section, ' . $ma['day'],
                    'text' => '"' . $na . '" and "' . $nb . '" are scheduled at the same time.'];
            }
        }
    }

    $no_slot = []; $no_teacher = []; $scheduled = 0;
    foreach ($rows as $row) {
        $tid   = $row['teacher_id'] ? (int)$row['teacher_id'] : null;
        $tname = $tid ? ($tnames[$tid] ?? null) : null;
        if ($tid && $row['meetings']) $scheduled++;
        if (!$row['meetings'])  $no_slot[]    = $row['subject_name'];
        elseif (!$tid)          $no_teacher[] = $row['subject_name'];

        if (!$row['meetings']) {
            $d['slots'][] = ['subject' => $row['subject_name'], 'day' => null, 'teacher' => $tname, 'room' => null, 'timed' => false, 'conflict' => false];
            continue;
        }
        foreach ($row['meetings'] as $m) {
            $room = $m['room'] ?: ($r['room'] ?: null);
            $slot = ['subject' => $row['subject_name'], 'day' => $m['day'], 'teacher' => $tname, 'room' => $room, 'timed' => true,
                     'conflict' => isset($own[$row['subject_name'] . '|' . $m['day']]),
                     'sm' => ap_minutes($m['start_time']), 'em' => ap_minutes($m['end_time']),
                     'label' => ap_time_label($m['start_time']) . ' – ' . ap_time_label($m['end_time'])];
            $clash = sched_find_clash($conn, $section_id, $sem, $tid, $room, $m['day'], $m['start_time'], $m['end_time']);
            if ($clash) {
                $slot['conflict'] = true;
                $d['issues'][] = ['kind' => 'conflict',
                    'title' => ($clash['kind'] === 'teacher' ? 'Teacher' : 'Room') . ' conflict, ' . $m['day'] . ' ' . ap_time_label($m['start_time']),
                    'text'  => '"' . $row['subject_name'] . '": ' . ($clash['kind'] === 'teacher' && $tname ? str_replace('This teacher', $tname, $clash['text']) : $clash['text'])];
            }
            $d['slots'][] = $slot;
        }
    }

    $n = count($no_slot);
    if ($n) {
        $d['issues'][] = ['kind' => 'warn', 'title' => $n . ($n === 1 ? ' subject has' : ' subjects have') . ' no classes',
            'text' => implode(', ', $no_slot) . '.'];
    }
    $n = count($no_teacher);
    if ($n) {
        $d['issues'][] = ['kind' => 'warn', 'title' => $n . ($n === 1 ? ' subject has' : ' subjects have') . ' no teacher',
            'text' => implode(', ', $no_teacher) . '.'];
    }
    if (!$rows) {
        $d['issues'][] = ['kind' => 'warn', 'title' => 'No subjects on this schedule', 'text' => 'This section has no subjects for Semester ' . $sem . '.'];
    }

    $d['scheduled'] = $scheduled;
    $d['total']     = count($rows);
    $d['facts'][]   = ['Semester', 'Semester ' . $sem];
    $d['facts'][]   = ['Classes per week', (string)array_sum(array_map(fn($x) => count($x['meetings']), $rows))];
    return $d;
}

/* ── Decisions ──────────────────────────────────────────────────────────── */

// Each returns [bool ok, string message]. Same statements/guards the
// individual queue pages use. $notify=false lets bulk runs send one
// summary notification per requester instead (see ap_bulk_decide).
function ap_decide(mysqli $conn, string $type, int $id, string $decision, int $user_id, string $reason = '', bool $notify = true): array {
    if ($decision === 'reject' && $reason === '') {
        return [false, 'Please provide a reason for rejecting this request.'];
    }
    $row = ap_fetch_row($conn, $type, $id);
    if (!$row) return [false, 'Request not found.'];
    if ($row['status'] !== 'pending') return [false, '"' . $row['label'] . '" has already been reviewed.'];

    $uid    = $notify && $row['req_uid'] ? (int)$row['req_uid'] : null;
    $label  = $row['label'];
    $raw    = $row['name'];
    $done   = $decision === 'approve' ? 'approved' : 'rejected';

    if ($type === 'sections') {
        if ($decision === 'approve') {
            $upd = $conn->prepare("UPDATE sections SET approval_status='approved', reviewed_by=?, reviewed_at=NOW() WHERE section_id=? AND approval_status='pending'");
            $upd->bind_param('ii', $user_id, $id);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();
            if ($affected < 1) return [false, 'Could not approve "' . $label . '" — it may have already been reviewed.'];
            ap_populate_section_subjects($conn, $id, $row['grade_level'], (int)$row['strand_id'], $row['school_year']);
        } else {
            $upd = $conn->prepare("UPDATE sections SET approval_status='rejected', is_active=0, rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE section_id=? AND approval_status='pending'");
            $upd->bind_param('sii', $reason, $user_id, $id);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();
            if ($affected < 1) return [false, 'Could not reject "' . $label . '" — it may have already been reviewed.'];
        }
        if ($uid) notify_users($conn, [$uid], "Section \"$raw\" was $done.", 'scheduler/sections', 'schedule');
    } elseif ($type === 'curriculum') {
        if ($decision === 'approve') {
            $stmt = $conn->prepare("UPDATE subjects SET review_status='approved', approved_by=?, approved_at=NOW(), rejection_reason=NULL WHERE subject_id=? AND review_status='for_review'");
            $stmt->bind_param('ii', $user_id, $id);
        } else {
            $stmt = $conn->prepare("UPDATE subjects SET review_status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=? WHERE subject_id=? AND review_status='for_review'");
            $stmt->bind_param('isi', $user_id, $reason, $id);
        }
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) return [false, 'Could not ' . $decision . ' "' . $label . '" — it may have already been reviewed.'];
        if ($uid) notify_users($conn, [$uid], "Subject \"$raw\" was $done.", 'scheduler/curriculum', 'schedule');
    } else {
        $c = fn($b) => sched_col($b, ap_sem());
        if ($decision === 'approve') {
            $stmt = $conn->prepare("UPDATE sections SET {$c('schedule_status')}='approved', {$c('schedule_reviewed_by')}=?, {$c('schedule_reviewed_at')}=NOW() WHERE section_id=? AND {$c('schedule_status')}='pending'");
            $stmt->bind_param('ii', $user_id, $id);
        } else {
            $stmt = $conn->prepare("UPDATE sections SET {$c('schedule_status')}='rejected', {$c('schedule_reviewed_by')}=?, {$c('schedule_reviewed_at')}=NOW(), {$c('schedule_rejection_reason')}=? WHERE section_id=? AND {$c('schedule_status')}='pending'");
            $stmt->bind_param('isi', $user_id, $reason, $id);
        }
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) return [false, 'Could not ' . $decision . ' "' . $label . '" — it may have already been reviewed.'];
        if ($uid) notify_users($conn, [$uid], "Semester " . ap_sem() . " schedule for section \"$raw\" was $done.", 'scheduler/scheduling?section_id=' . $id . '&semester=' . ap_sem(), 'schedule');
    }

    return [true, ($type === 'schedule' ? 'Semester ' . ap_sem() . ' schedule for ' : '') . '"' . $label . '" ' . $done . '.'];
}

// Mirrors sections.php's populate_section_subjects() — kept local so this
// page has no hard dependency on sections.php internals.
function ap_populate_section_subjects($conn, $section_id, $grade_level, $strandId, $school_year) {
    // Scoped to the section's own school_year — mirrors sections.php's
    // populate_section_subjects(), so a section only ever pulls subjects
    // that actually belong to its curriculum year, not a past (or
    // inactive/unapproved) year's list.
    $coreId = strand_id($conn, 'CORE');
    $subj_stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE grade_level = ? AND strand IN (?, ?) AND school_year = ? AND is_active = 1 AND review_status = 'approved'");
    $subj_stmt->bind_param('siis', $grade_level, $strandId, $coreId, $school_year);
    $subj_stmt->execute();
    $subject_ids = array_column($subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_id');
    $subj_stmt->close();

    foreach ($subject_ids as $subject_id) {
        $teacher_row = $conn->query("SELECT teacher_id FROM teachers ORDER BY RAND() LIMIT 1")->fetch_assoc();
        $teacher_id  = $teacher_row ? (int) $teacher_row['teacher_id'] : null;
        $ss_stmt = $conn->prepare("INSERT INTO section_subjects (section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, 1)");
        $ss_stmt->bind_param('iii', $section_id, $subject_id, $teacher_id);
        $ss_stmt->execute();
        $ss_stmt->close();

        // Keep teacher_assignments (what the teacher portal actually reads)
        // in sync with section_subjects.teacher_id — same pattern as
        // scheduler/sections.php's populate_section_subjects(). This
        // function only ever seeds a brand-new section, always Semester 1.
        $del_stmt = $conn->prepare(
            "DELETE FROM teacher_assignments WHERE subject_id = ? AND section_id = ? AND school_year = ? AND semester = 1"
        );
        $del_stmt->bind_param('iis', $subject_id, $section_id, $school_year);
        $del_stmt->execute();
        $del_stmt->close();

        if ($teacher_id) {
            $ta_stmt = $conn->prepare(
                "INSERT INTO teacher_assignments (teacher_id, subject_id, section_id, school_year, semester) VALUES (?, ?, ?, ?, 1)"
            );
            $ta_stmt->bind_param('iiis', $teacher_id, $subject_id, $section_id, $school_year);
            $ta_stmt->execute();
            $ta_stmt->close();
        }
    }
}

/* ── Filter / sort / page state (shared by URLs and queries) ────────────── */

$valid_types = ['sections', 'curriculum', 'schedule'];
$type = in_array($_GET['type'] ?? '', $valid_types, true) ? $_GET['type'] : 'sections';
$ap_sem = (int)($_POST['req_sem'] ?? $_GET['sem'] ?? 1) === 2 ? 2 : 1;

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected', 'all'], true) ? $_GET['status'] : 'pending';

$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

$valid_grade_levels = ['11', '12'];
$valid_strands      = ['CORE', 'STEM', 'HUMSS', 'ABM', 'GAS', 'TVL'];
$f_grade  = in_array($_GET['grade_level'] ?? '', $valid_grade_levels, true) ? $_GET['grade_level'] : '';
$f_strand = in_array($_GET['strand'] ?? '', $valid_strands, true) ? $_GET['strand'] : '';

// Date is only sortable on Schedule (the only queue that records a submitted time).
$sortable = $type === 'schedule' ? ['name', 'level', 'date'] : ['name', 'level'];
$sort_key = in_array($_GET['sort'] ?? '', $sortable, true) ? $_GET['sort'] : '';
$sort_dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : '';
// Default: work the queue oldest-first; history newest-first.
$eff_key = $sort_key !== '' ? $sort_key : 'date';
$eff_dir = $sort_dir !== '' ? $sort_dir : ($status === 'pending' ? 'asc' : 'desc');

$state = [
    'type' => $type, 'sem' => $type === 'schedule' && $ap_sem === 2 ? 2 : '', 'status' => $status, 'q' => $q, 'grade_level' => $f_grade, 'strand' => $f_strand,
    'sort' => $sort_key, 'dir' => $sort_key !== '' ? $eff_dir : '', 'page' => $page,
];
// Any change resets to page 1 unless 'page' is passed explicitly.
$url = function (array $over = []) use ($state): string {
    $p = $over + $state;
    if (!array_key_exists('page', $over)) $p['page'] = 1;
    if ($p['status'] === 'pending') $p['status'] = '';
    if ((int)$p['page'] <= 1) $p['page'] = '';
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};

/* ── Search/filter clause (shared by the list and the "all" batch actions) ─ */

$P = ap_parts($type);
$status_value_map = $type === 'curriculum'
    ? ['pending' => 'for_review', 'approved' => 'approved', 'rejected' => 'rejected']
    : ['pending' => 'pending',    'approved' => 'approved', 'rejected' => 'rejected'];

$where  = [$P['base']];
$types  = '';
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = $P['search'];
    $types  .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($f_grade !== '')  { $where[] = 's.grade_level = ?';  $types .= 's'; $params[] = $f_grade; }
if ($f_strand !== '') { $where[] = 'st.strand_code = ?'; $types .= 's'; $params[] = $f_strand; }
$where_sql = implode(' AND ', $where);

// Every pending request of the current type matching the search/filters,
// across all pages — what "Approve all" / "Reject all" act on.
function ap_pending_ids(mysqli $conn, array $P, string $where_sql, string $types, array $params, string $pending_value): array {
    $stmt = $conn->prepare("SELECT {$P['id']} AS id {$P['from']} WHERE $where_sql AND {$P['status']} = ? ORDER BY {$P['id']}");
    $stmt->bind_param($types . 's', ...array_merge($params, [$pending_value]));
    $stmt->execute();
    $ids = array_map(fn($x) => (int)$x['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return $ids;
}

// Runs one decision over many requests. Approvals only clear requests with
// no issues (anything flagged needs eyes on it); rejections apply the same
// reason to all. Requesters get one summary notification each instead of
// one per request.
function ap_bulk_decide(mysqli $conn, string $type, array $ids, string $decision, int $user_id, string $reason = ''): array {
    $done = 0; $skipped = 0; $last_err = ''; $by_requester = [];
    foreach ($ids as $id) {
        $row = ap_fetch_row($conn, $type, $id);
        if (!$row || $row['status'] !== 'pending') { $skipped++; continue; }
        if ($decision === 'approve' && ap_detail($conn, $type, $row)['issues']) { $skipped++; continue; }
        [$ok, $msg] = ap_decide($conn, $type, $id, $decision, $user_id, $reason, false);
        if (!$ok) { $skipped++; $last_err = $msg; continue; }
        $done++;
        if ($row['req_uid']) $by_requester[(int)$row['req_uid']][] = $row['name'];
    }

    $noun  = ['sections' => ['section', 'sections'], 'curriculum' => ['subject', 'subjects'], 'schedule' => ['schedule', 'schedules']][$type];
    $link  = ['sections' => 'scheduler/sections', 'curriculum' => 'scheduler/curriculum', 'schedule' => 'scheduler/scheduling'][$type];
    $verb  = $decision === 'approve' ? 'approved' : 'rejected';
    foreach ($by_requester as $uid => $names) {
        $n    = count($names);
        $what = $n === 1
            ? ucfirst($noun[0]) . ($type === 'schedule' ? ' for section' : '') . ' "' . $names[0] . '"'
            : $n . ' ' . $noun[1];
        if ($type === 'schedule') $what = 'Semester ' . ap_sem() . ' ' . lcfirst($what);
        notify_users($conn, [$uid], $what . ' ' . ($n === 1 ? 'was' : 'were') . ' ' . $verb . ($decision === 'reject' ? ': ' . $reason : '.'), $link, 'schedule');
    }

    $msg = '';
    if ($done > 0) {
        $msg = $done . ' ' . $noun[$done === 1 ? 0 : 1] . ' ' . $verb . '.';
        if ($skipped) {
            $msg .= ' ' . $skipped . ' skipped (' . ($decision === 'approve' ? 'flagged with issues or ' : '') . 'already reviewed) — open them to decide one by one.';
        }
    }
    return [$done, $msg, $last_err];
}

/* ── POST: approve / reject, one or many ────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'] ?? '';
    $ptype    = in_array($_POST['req_type'] ?? '', $valid_types, true) ? $_POST['req_type'] : $type;
    $reason   = trim($_POST['rejection_reason'] ?? '');

    if (in_array($decision, ['approve', 'reject'], true)) {
        [$ok, $msg] = ap_decide($conn, $ptype, (int)($_POST['req_id'] ?? 0), $decision, $user_id, $reason);
        if ($ok) {
            $_SESSION['approvals_flash'] = $msg;
            header("Location: approvals" . $url(['page' => $page])); exit();
        }
        $error = $msg;
    } elseif (in_array($decision, ['bulk_approve', 'bulk_reject', 'approve_all', 'reject_all'], true)) {
        $bulk_verb = str_starts_with($decision, 'bulk_') ? substr($decision, 5) : substr($decision, 0, -4);
        if (str_ends_with($decision, '_all')) {
            // "All" acts on the queue as currently filtered — only offered on
            // Curriculum, where a new curriculum arrives as one big batch.
            $ids = $ptype === $type && $type === 'curriculum'
                ? ap_pending_ids($conn, $P, $where_sql, $types, $params, $status_value_map['pending'])
                : [];
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['req_ids'] ?? [])))));
        }

        if ($bulk_verb === 'reject' && $reason === '') {
            $error = 'Please provide a reason for rejecting these requests.';
        } elseif (!$ids) {
            $error = 'There are no pending requests to ' . $bulk_verb . '.';
        } else {
            [$done, $msg, $last_err] = ap_bulk_decide($conn, $ptype, $ids, $bulk_verb, $user_id, $reason);
            if ($done > 0) {
                $_SESSION['approvals_flash'] = $msg;
                header("Location: approvals" . $url(['page' => $page])); exit();
            }
            $error = $last_err !== '' ? $last_err
                : ($bulk_verb === 'approve'
                    ? 'Nothing was approved — every request has issues to check or was already reviewed. Open them to decide one by one.'
                    : 'Nothing was rejected — the requests were already reviewed.');
        }
    }
}

/* ── Build the list for the selected type ───────────────────────────────── */

// Counts per status under the current search/filters (feeds the segmented control).
$status_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$stmt = $conn->prepare("SELECT {$P['status']} AS st, COUNT(*) AS c {$P['from']} WHERE $where_sql GROUP BY {$P['status']}");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
    $k = $c['st'] === 'for_review' ? 'pending' : $c['st'];
    if (isset($status_counts[$k])) $status_counts[$k] = (int)$c['c'];
}
$stmt->close();
$status_counts['all'] = $status_counts['pending'] + $status_counts['approved'] + $status_counts['rejected'];

$total_count = $status_counts[$status];
$total_pages = max(1, (int)ceil($total_count / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$list_where  = $where_sql;
$list_types  = $types;
$list_params = $params;
if ($status !== 'all') {
    $list_where  .= " AND {$P['status']} = ?";
    $list_types  .= 's';
    $list_params[] = $status_value_map[$status];
}

$dir_sql = strtoupper($eff_dir);
if ($eff_key === 'name')       $order = "ORDER BY {$P['name']} $dir_sql, {$P['id']} ASC";
elseif ($eff_key === 'level')  $order = "ORDER BY s.grade_level $dir_sql, st.strand_code $dir_sql, {$P['id']} ASC";
elseif ($type === 'schedule')  { $sub_at = 's.' . sched_col('schedule_submitted_at', $ap_sem); $order = "ORDER BY ($sub_at IS NULL), $sub_at $dir_sql, {$P['id']} $dir_sql"; }
else                           $order = "ORDER BY {$P['id']} $dir_sql";

$stmt = $conn->prepare("{$P['select']} {$P['from']} WHERE $list_where $order LIMIT ? OFFSET ?");
$stmt->bind_param($list_types . 'ii', ...array_merge($list_params, [$per_page, $offset]));
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Per-row checks + the drawer payload (embedded as JSON, opened client-side).
$payload = [];
$has_pending_rows = false;
foreach ($rows as &$r) {
    ap_normalize($type, $r);
    $r['detail']   = ap_detail($conn, $type, $r);
    $r['issues_n'] = count($r['detail']['issues']);
    $r['conflicts_n'] = count(array_filter($r['detail']['issues'], fn($i) => $i['kind'] === 'conflict'));
    if ($r['status'] === 'pending') $has_pending_rows = true;

    $facts = [];
    $person = ap_person($r);
    $facts[] = ['Requested by', $person !== '' ? $person : '—'];
    if ($r['submitted_at']) $facts[] = ['Submitted', date('M j, Y g:i A', strtotime($r['submitted_at'])) . ' · ' . ap_ago($r['submitted_at'])];
    $facts = array_merge($facts, $r['detail']['facts']);
    if ($r['status'] !== 'pending' && $r['decided_at']) {
        $facts[] = [ucfirst($r['status']), date('M j, Y g:i A', strtotime($r['decided_at']))];
    }

    $payload[(int)$r['id']] = [
        'title'     => $r['label'],
        'sub'       => 'Grade ' . $r['grade_level'] . ' · ' . $r['strand'] . ' · ' . $r['school_year'],
        'status'    => $r['status'],
        'facts'     => $facts,
        'issues'    => $r['detail']['issues'],
        'slots'     => $r['detail']['slots'],
        'scheduled' => $r['detail']['scheduled'],
        'total'     => $r['detail']['total'],
        'count'     => $r['detail']['count'],
        'reason'    => $r['status'] === 'rejected' ? ($r['rejection_reason'] ?: '') : '',
        'first'     => $person !== '' ? explode(' ', $person)[0] : '',
        'viewUrl'   => '', // coordinators review here; the scheduler's builder is scheduler-only
    ];
}
unset($r);

// Curriculum batch strip: every pending subject under the current filters
// (all pages), split into ready vs flagged so "Approve all" can say up
// front how many it will actually clear.
$batch_total = $batch_ready = 0;
if ($type === 'curriculum' && $status === 'pending' && $status_counts['pending'] > 0) {
    foreach (ap_pending_ids($conn, $P, $where_sql, $types, $params, $status_value_map['pending']) as $bid) {
        $brow = ap_fetch_row($conn, $type, $bid);
        if (!$brow) continue;
        $batch_total++;
        if (!ap_detail($conn, $type, $brow)['issues']) $batch_ready++;
    }
}
$batch_flagged = $batch_total - $batch_ready;

// Pending counts across all three types, for the tab badges.
$pending_counts = ['sections' => 0, 'curriculum' => 0, 'schedule' => 0];
$pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sections WHERE approval_status = 'pending'");
if ($pc) $pending_counts['sections'] = (int)mysqli_fetch_assoc($pc)['c'];
$pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM subjects WHERE review_status = 'for_review'");
if ($pc) $pending_counts['curriculum'] = (int)mysqli_fetch_assoc($pc)['c'];
$sem_pending = [1 => 0, 2 => 0];
$pc = mysqli_query($conn, "SELECT SUM(schedule_status = 'pending') AS s1, SUM(schedule_status_sem2 = 'pending') AS s2 FROM sections");
if ($pc && ($pr = mysqli_fetch_assoc($pc))) $sem_pending = [1 => (int)$pr['s1'], 2 => (int)$pr['s2']];
$pending_counts['schedule'] = $sem_pending[1] + $sem_pending[2];

$type_labels   = ['sections' => 'Sections', 'curriculum' => 'Curriculum', 'schedule' => 'Schedule'];
$status_labels = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
$noun          = ['sections' => 'section', 'curriculum' => 'subject', 'schedule' => 'schedule'][$type];
$has_filters   = $q !== '' || $f_grade !== '' || $f_strand !== '';

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

// Sortable <th>: link toggles direction when it is the active column.
$th_sort = function (string $key, string $label) use ($url, $sort_key, $eff_key, $eff_dir, $sortable, $type): string {
    if (!in_array($key, $sortable, true)) return '<th scope="col">' . $label . '</th>';
    $active = $eff_key === $key && ($sort_key !== '' || ($key === 'date' && $type === 'schedule'));
    $next   = $active && $eff_dir === 'asc' ? 'desc' : 'asc';
    $aria   = $active ? ' aria-sort="' . ($eff_dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
    return '<th scope="col"' . $aria . '><a class="ap-sort" href="' . htmlspecialchars($url(['sort' => $key, 'dir' => $next])) . '">' . $label
         . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg></a></th>';
};

// NOTE: do NOT close $conn here — staff_sidebar.php / staff_notifications.php
// (included below, in the HTML) run their own queries and need it open.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Approvals — Coordinator</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    .ap, .ap-drawer {
      --ap-ink:#17211C; --ap-muted:#54615A; --ap-line:#E1E7E3; --ap-line-strong:#C4CFC8;
      --ap-surface:#fff; --ap-surface-2:#F5F8F6; --ap-accent:var(--brand-primary); --ap-accent-soft:var(--brand-tint);
      --ap-ok:#17663F; --ap-ok-bg:#E1F2E8; --ap-warn:#7A4300; --ap-warn-bg:#FCEED3; --ap-bad:#A5221B; --ap-bad-bg:#FCE8E6;
      --ap-r-ctl:8px; --ap-r-card:12px;
      font-family:'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
      color:var(--ap-ink);
    }
    .ap *, .ap *::before, .ap *::after { box-sizing:border-box; }
    .ap button, .ap input, .ap select, .ap textarea { font-family:inherit; color:inherit; }
    .ap button { cursor:pointer; }
    .ap :focus-visible, .ap-drawer :focus-visible { outline:2px solid var(--ap-accent); outline-offset:2px; }
    .ap-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }

    /* Topbar: breadcrumb replaces the title, date is plain text */
    .ap-crumb { display:flex; align-items:center; gap:8px; font-size:13px; color:#54615A; }
    .ap-crumb b { color:#17211C; font-weight:600; }
    .ap-crumb .sep { color:#9AA7A0; }
    .staff-topbar-date { border:0; background:none; box-shadow:none; padding:0; height:auto; font-weight:500; color:#54615A; }
    .staff-topbar-date::before { display:none; }

    /* Header — uses the shared .page-eyebrow/.page-title Fraunces styling from
       css_staff.css (same as enrollments_staff.php) instead of overriding it. */
    .staff-content.ap .page-sub { color:var(--ap-muted); font-size:14px; margin-bottom:1.25rem; }
    .ap .notice { max-width:none; margin:0 0 1rem; border-radius:var(--ap-r-ctl); }

    /* Type tabs (underlined) */
    .ap-tabs { display:flex; gap:24px; border-bottom:1px solid var(--ap-line); margin-bottom:18px; overflow-x:auto; overflow-y:hidden; }
    .ap-tab { display:inline-flex; align-items:center; gap:8px; padding:10px 0; font-size:14px; font-weight:600; color:var(--ap-muted);
      text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
    .ap-tab:hover { color:var(--ap-ink); }
    .ap-tab[aria-current="page"] { color:var(--ap-ink); border-bottom-color:var(--ap-accent); }
    .ap-tab .ct { font-size:12px; font-weight:600; min-width:20px; padding:0 6px; text-align:center; border-radius:999px; background:var(--ap-line); color:var(--ap-ink); }
    .ap-tab .ct.on { background:var(--ap-accent); color:#fff; }

    /* Card + toolbar */
    .ap-card { background:var(--ap-surface); border:1px solid #E4E4EC; border-radius:16px; overflow:hidden;
      box-shadow:0 1px 2px rgba(16,24,40,.04), 0 1px 8px rgba(16,24,40,.03); }
    .ap-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:12px 16px; border-bottom:1px solid var(--ap-line); min-height:60px; }
    .ap-bar[hidden] { display:none; }
    .ap-bar.bulk { background:var(--ap-accent-soft); }
    .ap-bar.bulk b { color:var(--ap-accent); }
    .ap-bar-r { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .ap-bulk-flag { margin-left:6px; color:var(--ap-warn); font-size:13px; }

    /* Curriculum batch strip: approve / reject every pending subject at once */
    .ap-batch { padding:12px 16px; border-bottom:1px solid var(--ap-line); background:var(--ap-surface-2); }
    .ap-batch[hidden] { display:none; }
    .ap-batch-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .ap-batch-txt { font-size:14px; }
    .ap-batch-txt b { font-weight:600; }
    .ap-batch-rej { flex-basis:100%; width:100%; margin-top:12px; }
    .ap-batch-rej[hidden] { display:none; }
    .ap-batch-rej label { display:block; font-weight:600; font-size:13px; margin-bottom:6px; }
    .ap .ap-batch-rej textarea { width:100%; height:auto; min-height:72px; resize:vertical; border:1px solid var(--ap-line-strong); border-radius:var(--ap-r-ctl); background:#fff; padding:8px 10px; font-size:14px; }
    .ap-batch-rej .row { display:flex; justify-content:flex-end; gap:8px; margin-top:10px; }

    /* Schedule tab: Semester 1 / Semester 2 switch (each semester is its own queue). */
    .ap-sem { display:inline-flex; gap:4px; margin:-6px 0 14px; padding:3px; background:var(--ap-surface-2); border:1px solid var(--ap-line); border-radius:var(--ap-r-ctl); }
    .ap-sem a { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:500; color:var(--ap-muted); text-decoration:none; }
    .ap-sem a:hover { color:var(--ap-ink); }
    .ap-sem a[aria-current="true"] { background:#fff; color:var(--ap-ink); font-weight:600; box-shadow:0 1px 2px rgba(20,40,30,.14); }
    .ap-sem .ct { font-size:11.5px; font-weight:600; min-width:18px; padding:0 6px; text-align:center; border-radius:999px; background:var(--ap-accent); color:#fff; }
    .ap-seg { display:inline-flex; padding:2px; background:var(--ap-surface-2); border:1px solid var(--ap-line); border-radius:var(--ap-r-ctl); }
    .ap-seg a { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:6px; font-size:13px; font-weight:500; color:var(--ap-muted); text-decoration:none; }
    .ap-seg a:hover { color:var(--ap-ink); }
    .ap-seg a[aria-current="true"] { background:#fff; color:var(--ap-ink); font-weight:600; box-shadow:0 1px 2px rgba(20,40,30,.14); }
    .ap-seg .ct { font-size:12px; font-weight:500; color:var(--ap-muted); }

    .ap-field { position:relative; }
    .ap-field svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--ap-muted); pointer-events:none; }
    /* .ap prefix: css_staff.css sets width:100% / height:40px on bare select + textarea */
    .ap .ap-input, .ap .ap-select { height:36px; width:auto; border:1px solid var(--ap-line-strong); border-radius:var(--ap-r-ctl); background-color:#fff; padding:0 10px; font-size:13px; }
    .ap .ap-input { padding-left:32px; width:240px; }
    .ap-input::placeholder { color:var(--ap-muted); }
    .ap .ap-select { min-width:130px; padding-right:30px;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2354615A' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 10px center; }
    .ap-clear { font-size:13px; color:var(--ap-muted); text-decoration:underline; text-underline-offset:2px; white-space:nowrap; }
    .ap-clear:hover { color:var(--ap-bad); }

    .ap-btn { display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; border-radius:var(--ap-r-ctl); border:1px solid var(--ap-line-strong);
      background:#fff; font-size:13px; font-weight:600; text-decoration:none; color:var(--ap-ink); white-space:nowrap; }
    .ap-btn:hover { background:var(--ap-surface-2); }
    .ap-btn.sm { height:30px; padding:0 12px; }
    .ap-btn.primary { background:var(--ap-accent); border-color:var(--ap-accent); color:#fff; }
    .ap-btn.primary:hover { background:var(--brand-primary-hover); }
    .ap-btn.bad { color:var(--ap-bad); }
    .ap-btn.bad-fill { background:var(--ap-bad); border-color:var(--ap-bad); color:#fff; }
    .ap-btn.bad-fill:hover { background:#8A1B15; }
    .ap-btn:disabled { opacity:.45; cursor:not-allowed; }

    /* Table — same conventions as enrollments_staff.php's .enroll-table:
       uppercase letter-spaced headers on a faint band, quiet row borders,
       row-hover wash. */
    .ap-scroll { overflow-x:auto; }
    .ap-table { width:100%; border-collapse:collapse; font-size:13px; min-width:760px; }
    .ap-table th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
      color:#8A8A9A; padding:10px 14px; background:#FAFAFC; border-top:1px solid #EDEDF2; border-bottom:1px solid #EDEDF2; white-space:nowrap; }
    .ap-table td { padding:11px 14px; border-bottom:1px solid #F5F5F7; vertical-align:middle; color:#1A1A2E; }
    .ap-table tbody tr:last-child td { border-bottom:0; }
    .ap-table tbody tr { cursor:pointer; }
    .ap-table tbody tr:hover td, .ap-table tbody tr:focus-within td { background:#FAFAFC; }
    .ap-table .c-chk { width:44px; padding-right:0; }
    .ap-table .c-act { width:100px; text-align:right; }
    .ap-chk { width:16px; height:16px; accent-color:var(--brand-primary); cursor:pointer; }
    .ap-chk:disabled { cursor:not-allowed; opacity:.4; }

    .ap-sort { display:inline-flex; align-items:center; gap:4px; color:inherit; text-decoration:none; }
    .ap-sort:hover { color:var(--ap-ink); }
    .ap-sort svg { opacity:.4; transition:transform .15s; }
    th[aria-sort] .ap-sort { color:var(--ap-ink); }
    th[aria-sort] .ap-sort svg { opacity:1; }
    th[aria-sort="ascending"] .ap-sort svg { transform:rotate(180deg); }

    .ap-rowlink { background:none; border:0; padding:0; font-weight:600; font-size:14px; text-align:left; max-width:280px;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
    .ap-rowlink:hover { color:var(--ap-accent); }
    .ap-sub { display:block; color:var(--ap-muted); font-size:12px; margin-top:1px; }
    .ap-dash { color:var(--ap-muted); }

    .ap-slots { display:flex; align-items:center; gap:10px; }
    .ap-meter { width:56px; height:4px; border-radius:2px; background:var(--ap-line); flex:none; }
    .ap-meter i { display:block; height:100%; border-radius:2px; background:var(--brand-accent); }
    .ap-meter i.short { background:#B5650F; }

    .ap-badge { display:inline-flex; align-items:center; gap:6px; height:22px; padding:0 9px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
    .ap-badge::before { content:""; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .ap-badge.ok   { color:var(--ap-ok);   background:var(--ap-ok-bg); }
    .ap-badge.warn { color:var(--ap-warn); background:var(--ap-warn-bg); }
    .ap-badge.bad  { color:var(--ap-bad);  background:var(--ap-bad-bg); }

    .ap-table tbody tr.ap-empty-row, .ap-table tbody tr.ap-empty-row:hover { background:transparent; cursor:default; }
    .ap-table tbody tr.ap-empty-row td { border-bottom:0; padding:0; }
    .ap-empty { padding:48px 16px 52px; text-align:center; }
    .ap-empty-icon { width:56px; height:56px; margin:0 auto 18px; border-radius:50%; display:flex; align-items:center; justify-content:center;
      background:var(--ap-accent-soft); color:var(--ap-accent); box-shadow:0 0 0 8px rgba(30,77,59,.06); animation:apFloat 3.6s ease-in-out infinite; }
    .ap-empty-icon svg { display:block; transform-origin:50% 60%; animation:apTilt 3.6s ease-in-out infinite; }
    @keyframes apFloat { 0%, 100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }
    @keyframes apTilt  { 0%, 100% { transform:rotate(-4deg); } 50% { transform:rotate(4deg); } }
    .ap-empty h2 { margin:0 0 4px; font-size:16px; font-weight:600; }
    .ap-empty p { margin:0 auto 16px; color:var(--ap-muted); max-width:380px; font-size:14px; }

    .ap-pager { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:12px 16px; border-top:1px solid var(--ap-line); font-size:13px; color:var(--ap-muted); }
    .ap-pages { display:flex; gap:4px; }
    .ap-pages a, .ap-pages span { display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:30px; padding:0 6px; border-radius:6px; text-decoration:none; color:var(--ap-ink); font-size:13px; }
    .ap-pages a:hover { background:var(--ap-surface-2); }
    .ap-pages span.cur { background:var(--ap-accent); color:#fff; font-weight:600; }

    /* Drawer */
    .ap-scrim { position:fixed; inset:0; background:rgba(15,25,20,.38); z-index:1090; opacity:0; visibility:hidden; transition:opacity .2s, visibility .2s; }
    .ap-scrim.on { opacity:1; visibility:visible; }
    .ap-drawer { position:fixed; top:0; right:0; bottom:0; z-index:1100; width:min(520px,100%); background:#fff; border-left:1px solid var(--ap-line);
      box-shadow:0 2px 6px rgba(15,25,20,.08), 0 16px 40px rgba(15,25,20,.16); display:flex; flex-direction:column;
      transform:translateX(100%); visibility:hidden; transition:transform .22s ease, visibility .22s;
      font-family:'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color:#17211C; }
    .ap-drawer *, .ap-drawer *::before, .ap-drawer *::after { box-sizing:border-box; }
    .ap-drawer button, .ap-drawer textarea { font-family:inherit; color:inherit; }
    .ap-drawer.on { transform:none; visibility:visible; }
    .ap-d-head { display:flex; align-items:flex-start; gap:12px; padding:20px 24px 16px; border-bottom:1px solid var(--ap-line); }
    .ap-d-head h2 { margin:0; font-size:20px; font-weight:700; letter-spacing:-.01em; line-height:1.25; word-break:break-word; }
    .ap-d-head p { margin:2px 0 0; color:var(--ap-muted); font-size:14px; }
    .ap-x { margin-left:auto; flex:none; width:34px; height:34px; display:grid; place-items:center; border:1px solid var(--ap-line-strong); border-radius:var(--ap-r-ctl); background:#fff; }
    .ap-x:hover { background:var(--ap-surface-2); }
    .ap-d-body { flex:1; min-height:0; overflow-y:auto; padding:20px 24px; font-size:14px; }
    .ap-d-body h3 { margin:24px 0 10px; font-size:14px; font-weight:600; }
    .ap-d-body h3:first-child { margin-top:0; }
    .ap-facts { margin:0; display:grid; grid-template-columns:130px 1fr; gap:8px 16px; }
    .ap-facts dt { color:var(--ap-muted); }
    .ap-facts dd { margin:0; word-break:break-word; }
    .ap-issues { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }
    .ap-issues li { padding:10px 12px; border-radius:var(--ap-r-ctl); border:1px solid; }
    .ap-issues li b { display:block; font-size:13px; }
    .ap-issues li.conflict { background:var(--ap-bad-bg); border-color:#E9B4AF; }
    .ap-issues li.conflict b { color:var(--ap-bad); }
    .ap-issues li.warn { background:var(--ap-warn-bg); border-color:#E8C98D; }
    .ap-issues li.warn b { color:var(--ap-warn); }
    .ap-clean { padding:10px 12px; border-radius:var(--ap-r-ctl); background:var(--ap-ok-bg); color:var(--ap-ok); font-weight:600; }
    .ap-reason { margin:0; padding:10px 12px; border-radius:var(--ap-r-ctl); background:var(--ap-surface-2); border:1px solid var(--ap-line); }
    .ap-link { color:var(--ap-accent); font-weight:600; text-underline-offset:2px; }

    /* user-select:none — double-clicking a block used to select its text,
       and the selection highlight painted over the block so it looked
       white/blank. The grid is a read-only preview; nothing to copy. */
    .ap-wk { display:grid; grid-template-columns:40px repeat(5, minmax(0,1fr)); gap:3px; font-size:11px; user-select:none; -webkit-user-select:none; }
    .ap-wk .h { color:var(--ap-muted); text-align:center; font-weight:600; padding-bottom:2px; }
    .ap-wk .t { color:var(--ap-muted); text-align:right; padding-right:4px; white-space:nowrap; transform:translateY(-6px); }
    .ap-wk .bg { border-top:1px dashed var(--ap-line); }
    .ap-wk .blk { background:#DDEBE3; color:#123425; border-radius:5px; padding:2px 4px; font-weight:500; overflow:hidden; line-height:1.25; z-index:1; cursor:default; }
    .ap-wk .blk.conflict { background:var(--ap-bad-bg); color:var(--ap-bad); outline:2px solid var(--ap-bad); outline-offset:-2px; font-weight:700; }
    .ap-legend { display:flex; gap:16px; margin-top:10px; color:var(--ap-muted); font-size:12px; }
    .ap-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:6px; vertical-align:-1px; background:#DDEBE3; }
    .ap-legend i.c { background:var(--ap-bad-bg); outline:2px solid var(--ap-bad); outline-offset:-2px; }
    .ap-list { list-style:none; margin:0; padding:0; }
    .ap-list li { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid var(--ap-line); }
    .ap-list li:last-child { border-bottom:0; }
    .ap-list .n { font-weight:500; }
    .ap-list .m { color:var(--ap-muted); font-size:12px; text-align:right; }

    .ap-d-foot { padding:14px 24px; border-top:1px solid var(--ap-line); background:#fff; }
    .ap-d-foot-inner { display:flex; gap:8px; justify-content:flex-end; align-items:center; flex-wrap:wrap; }
    .ap-d-foot .note { margin-right:auto; color:var(--ap-muted); font-size:13px; }
    .ap-rej { width:100%; }
    .ap-rej label { display:block; font-weight:600; font-size:13px; margin-bottom:6px; }
    .ap-rej textarea { width:100%; min-height:80px; resize:vertical; border:1px solid var(--ap-line-strong); border-radius:var(--ap-r-ctl); background:#fff; padding:8px 10px; font-size:14px; }
    .ap-rej .row { display:flex; justify-content:flex-end; gap:8px; margin-top:10px; }

    /* Hover label for [data-tip] (full subject names that get clipped).
       position:fixed + appended to <body> so it isn't clipped by the
       overflow:hidden schedule blocks or the drawer's scroll area. */
    .ap-tip { position:fixed; z-index:10000; max-width:280px; padding:6px 9px; border-radius:6px; background:#123425; color:#fff;
              font-size:12px; font-weight:500; line-height:1.35; box-shadow:0 4px 14px rgba(0,0,0,.18); pointer-events:none;
              opacity:0; transition:opacity .1s; }
    .ap-tip.on { opacity:1; }

    @media (max-width: 860px) {
      .ap .ap-input { width:100%; }
      .ap-field { flex:1 1 100%; }
      .ap-bar-r { width:100%; }
      .ap .ap-select { flex:1; }
      .ap-crumb .pre, .ap-crumb .sep { display:none; }
    }
    @media (prefers-reduced-motion: reduce) { .ap *, .ap-drawer, .ap-scrim { transition:none !important; } .ap-empty-icon, .ap-empty-icon svg { animation:none; } }
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

    <div class="staff-content ap">

      <p class="page-eyebrow">Coordinator Portal</p>
      <h1 class="page-title">Approvals</h1>
      <p class="page-sub">Requests from schedulers waiting on your decision.</p>

      <?php if ($flash): ?><div class="notice notice-info" role="status"><?= $e($flash) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="notice notice-info" role="status"><?= $e($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error" role="alert"><?= $e($error) ?></div><?php endif; ?>

      <nav class="ap-tabs" aria-label="Request type">
        <?php foreach ($type_labels as $tkey => $tlabel): ?>
          <a class="ap-tab" href="<?= $e($url(['type' => $tkey, 'sem' => '', 'status' => 'pending', 'q' => '', 'grade_level' => '', 'strand' => '', 'sort' => '', 'dir' => ''])) ?>"
             <?= $type === $tkey ? 'aria-current="page"' : '' ?>>
            <?= $tlabel ?>
            <?php if ($pending_counts[$tkey] > 0): ?>
              <span class="ct <?= $type === $tkey ? 'on' : '' ?>"><?= $pending_counts[$tkey] ?><span class="ap-sr"> pending</span></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php if ($type === 'schedule'): ?>
        <div class="ap-sem" role="group" aria-label="Semester">
          <?php foreach ([1, 2] as $sm): ?>
            <a href="<?= $e($url(['sem' => $sm === 2 ? 2 : ''])) ?>" <?= $ap_sem === $sm ? 'aria-current="true"' : '' ?>>
              Semester <?= $sm ?><?php if ($sem_pending[$sm] > 0): ?><span class="ct"><?= $sem_pending[$sm] ?><span class="ap-sr"> pending</span></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <section class="ap-card" aria-label="<?= $e($type_labels[$type]) ?> requests">

        <div class="ap-bar" id="apToolbar">
          <form method="GET" class="ap-bar-r" id="apFilters">
            <input type="hidden" name="type" value="<?= $e($type) ?>">
            <?php if ($state['sem']): ?><input type="hidden" name="sem" value="2"><?php endif; ?>
            <?php if ($status !== 'pending'): ?><input type="hidden" name="status" value="<?= $e($status) ?>"><?php endif; ?>
            <?php if ($sort_key !== ''): ?>
              <input type="hidden" name="sort" value="<?= $e($sort_key) ?>">
              <input type="hidden" name="dir" value="<?= $e($eff_dir) ?>">
            <?php endif; ?>
            <div class="ap-field">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
              <input class="ap-input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Search <?= $type === 'curriculum' ? 'subject' : 'section' ?> or scheduler" aria-label="Search requests">
            </div>
            <select class="ap-select" name="grade_level" aria-label="Grade level" onchange="this.form.submit()">
              <option value="">All grades</option>
              <?php foreach ($valid_grade_levels as $g): ?>
                <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
              <?php endforeach; ?>
            </select>
            <select class="ap-select" name="strand" aria-label="Strand" onchange="this.form.submit()">
              <option value="">All strands</option>
              <?php foreach ($valid_strands as $s): ?>
                <option value="<?= $s ?>" <?= $f_strand === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($has_filters): ?>
              <a class="ap-clear" href="<?= $e($url(['q' => '', 'grade_level' => '', 'strand' => ''])) ?>">Clear</a>
            <?php endif; ?>
          </form>

          <div class="ap-seg" role="group" aria-label="Filter by status">
            <?php foreach ($status_labels as $skey => $slabel): ?>
              <a href="<?= $e($url(['status' => $skey])) ?>" <?= $status === $skey ? 'aria-current="true"' : '' ?>>
                <?= $slabel ?><span class="ct"><?= $status_counts[$skey] ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($batch_total > 0):
          $bn = fn(int $n) => $n . ' ' . ($n === 1 ? 'subject' : 'subjects'); ?>
          <div class="ap-batch" id="apBatch">
            <div class="ap-batch-row">
              <div class="ap-batch-txt">
                <b><?= $bn($batch_total) ?></b> waiting for review<?= $has_filters ? ' matching your filters' : '' ?>
                <span class="ap-sub">
                  <?= $batch_ready ?> ready<?php if ($batch_flagged): ?> · <?= $batch_flagged ?> flagged, skipped by Approve all<?php endif; ?>
                </span>
              </div>
              <div class="ap-bar-r">
                <button type="button" class="ap-btn sm bad" id="apAllRejBtn" aria-expanded="false" aria-controls="apAllRej">Reject all</button>
                <form method="POST" data-icon="question" style="display:contents"
                      data-confirm="Approve <?= $e($bn($batch_ready)) ?><?= $batch_flagged ? $e(' (' . $batch_flagged . ' flagged will be left for you to review)') : '' ?>?">
                  <input type="hidden" name="req_type" value="curriculum">
                  <input type="hidden" name="decision" value="approve_all">
                  <button type="submit" class="ap-btn sm primary" <?= $batch_ready ? '' : 'disabled title="Every pending subject is flagged — review them one by one"' ?>>
                    Approve all<?= $batch_ready ? ' (' . $batch_ready . ')' : '' ?>
                  </button>
                </form>
              </div>
            </div>
            <form method="POST" class="ap-batch-rej" id="apAllRej" hidden data-icon="warning"
                  data-confirm="Reject all <?= $e($bn($batch_total)) ?>? The scheduler will need to resubmit them.">
              <input type="hidden" name="req_type" value="curriculum">
              <input type="hidden" name="decision" value="reject_all">
              <label for="apAllWhy">Reason for rejecting all <?= $bn($batch_total) ?></label>
              <textarea id="apAllWhy" name="rejection_reason" required placeholder="Sent to the scheduler with the rejection — say what to fix before resubmitting"></textarea>
              <div class="row">
                <button type="button" class="ap-btn sm" id="apAllRejNo">Cancel</button>
                <button type="submit" class="ap-btn sm bad-fill" id="apAllRejGo" disabled>Reject <?= $bn($batch_total) ?></button>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($has_pending_rows): ?>
          <form method="POST" class="ap-bar bulk" id="apBulk" hidden data-confirm="Approve the selected requests?" data-icon="question">
            <input type="hidden" name="req_type" value="<?= $e($type) ?>">
            <input type="hidden" name="req_sem" value="<?= $ap_sem ?>">
            <input type="hidden" name="decision" value="bulk_approve" id="apBulkDecision">
            <span><b id="apBulkN">0</b> selected<span class="ap-bulk-flag" id="apBulkFlag"></span></span>
            <div class="ap-bar-r">
              <button type="button" class="ap-btn sm" id="apBulkClear">Clear</button>
              <button type="button" class="ap-btn sm bad" id="apBulkRejBtn" aria-expanded="false" aria-controls="apBulkRej">Reject selected</button>
              <button type="submit" class="ap-btn sm primary" id="apBulkApp">Approve selected</button>
            </div>
            <div class="ap-batch-rej" id="apBulkRej" hidden>
              <label for="apBulkWhy">Reason for rejecting the selected requests</label>
              <textarea id="apBulkWhy" name="rejection_reason" disabled placeholder="Sent to the scheduler with the rejection — say what to fix before resubmitting"></textarea>
              <div class="row">
                <button type="button" class="ap-btn sm" id="apBulkRejNo">Cancel</button>
                <button type="submit" class="ap-btn sm bad-fill" id="apBulkRejGo" disabled>Reject selected</button>
              </div>
            </div>
          </form>
        <?php endif; ?>

          <div class="ap-scroll">
            <table class="ap-table">
              <thead>
                <tr>
                  <th class="c-chk" scope="col">
                    <?php if ($has_pending_rows): ?><input type="checkbox" class="ap-chk" id="apSelAll" aria-label="Select all pending requests on this page"><?php endif; ?>
                  </th>
                  <?= $th_sort('name', $type === 'curriculum' ? 'Subject' : 'Section') ?>
                  <?php if ($type === 'sections'): ?>
                    <th scope="col">Room</th><th scope="col">Subjects</th>
                  <?php elseif ($type === 'curriculum'): ?>
                    <th scope="col">Category</th>
                  <?php else: ?>
                    <th scope="col">Slots scheduled</th>
                  <?php endif; ?>
                  <th scope="col"><?= $status === 'pending' ? 'Checks' : 'Status' ?></th>
                  <?= $type === 'schedule' ? $th_sort('date', 'Submitted') : '<th scope="col">Requested by</th>' ?>
                  <th scope="col" class="c-act">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows):
                  $cols = $type === 'sections' ? 7 : 6; ?>
                  <tr class="ap-empty-row">
                    <td colspan="<?= $cols ?>">
                      <div class="ap-empty">
                        <div class="ap-empty-icon" aria-hidden="true">
                          <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                            <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                            <path d="M4 8.5 12 13l8-4.5M12 13v7" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                          </svg>
                        </div>
                        <?php if ($has_filters): ?>
                          <h2>No requests match</h2>
                          <p>Try a different search or clear the filters.</p>
                          <a class="ap-btn" href="<?= $e($url(['q' => '', 'grade_level' => '', 'strand' => ''])) ?>">Clear filters</a>
                        <?php elseif ($status === 'pending'): ?>
                          <h2>Nothing waiting on you</h2>
                          <p>Every <?= $e($noun) ?> request has been decided.</p>
                          <a class="ap-btn" href="<?= $e($url(['status' => 'all'])) ?>">View decided requests</a>
                        <?php else: ?>
                          <h2>No <?= $status === 'all' ? '' : $e($status) . ' ' ?>requests</h2>
                          <p><?= $status === 'all' ? 'Requests appear once schedulers submit them.' : 'Nothing has been ' . $e($status) . ' yet.' ?></p>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                  $pending = $r['status'] === 'pending';
                  $person  = ap_person($r);
                  $d       = $r['detail'];
                ?>
                  <tr data-id="<?= (int)$r['id'] ?>">
                    <td class="c-chk">
                      <?php if ($pending): ?>
                        <input type="checkbox" class="ap-chk ap-row-chk" form="apBulk" name="req_ids[]" value="<?= (int)$r['id'] ?>"
                               aria-label="Select <?= $e($r['label']) ?>"
                               <?= $r['issues_n'] ? 'data-flagged="1" title="Flagged — can be rejected in bulk, but must be approved from its review panel"' : '' ?>>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button type="button" class="ap-rowlink" data-tip="<?= $e($r['label']) ?>"><?= $e($r['label']) ?></button>
                      <span class="ap-sub">Grade <?= $e($r['grade_level']) ?> · <?= $e($r['strand']) ?><?= $type === 'curriculum' ? '' : ' · ' . $e($r['school_year']) ?></span>
                    </td>

                    <?php if ($type === 'sections'): ?>
                      <td><?= $r['room'] !== null && $r['room'] !== '' ? $e($r['room']) : '<span class="ap-dash">—</span>' ?>
                        <span class="ap-sub"><?= (int)$r['capacity'] ?> seats</span></td>
                      <td><?= (int)$d['count'] ?> <?= (int)$d['count'] === 1 ? 'subject' : 'subjects' ?>
                        <?php if ($pending): ?><span class="ap-sub">added on approval</span><?php endif; ?></td>
                    <?php elseif ($type === 'curriculum'): ?>
                      <td><?= $e(ucfirst($r['category'])) ?><span class="ap-sub"><?= $e($r['school_year']) ?></span></td>
                    <?php else: ?>
                      <td>
                        <?php if ($d['total']):
                          $pct = (int)round($d['scheduled'] / $d['total'] * 100); ?>
                          <div class="ap-slots">
                            <span><?= (int)$d['scheduled'] ?> of <?= (int)$d['total'] ?> subjects</span>
                            <span class="ap-meter" aria-hidden="true"><i class="<?= $d['scheduled'] < $d['total'] ? 'short' : '' ?>" style="width:<?= $pct ?>%"></i></span>
                          </div>
                        <?php else: ?><span class="ap-dash">No subjects</span><?php endif; ?>
                      </td>
                    <?php endif; ?>

                    <td>
                      <?php if ($r['status'] === 'approved'): ?><span class="ap-badge ok">Approved</span>
                      <?php elseif ($r['status'] === 'rejected'): ?><span class="ap-badge bad">Rejected</span>
                      <?php elseif ($r['conflicts_n']): ?><span class="ap-badge bad"><?= $r['conflicts_n'] ?> <?= $r['conflicts_n'] === 1 ? 'conflict' : 'conflicts' ?></span>
                      <?php elseif ($r['issues_n']): ?><span class="ap-badge warn"><?= $r['issues_n'] ?> to check</span>
                      <?php else: ?><span class="ap-badge ok">Ready</span><?php endif; ?>
                    </td>

                    <td>
                      <?php if ($type === 'schedule'): ?>
                        <?php if ($r['submitted_at']): ?>
                          <time datetime="<?= $e(date('c', strtotime($r['submitted_at']))) ?>" title="<?= $e(date('M j, Y g:i A', strtotime($r['submitted_at']))) ?>"><?= $e(ap_ago($r['submitted_at'])) ?></time>
                          <?php if ($person !== ''): ?><span class="ap-sub">by <?= $e($person) ?></span><?php endif; ?>
                        <?php else: ?><span class="ap-dash">—</span><?php endif; ?>
                      <?php else: ?>
                        <?= $person !== '' ? $e($person) : '<span class="ap-dash">—</span>' ?>
                        <?php if (!$pending && $r['decided_at']): ?>
                          <span class="ap-sub"><?= ucfirst($r['status']) ?> <?= $e(ap_ago($r['decided_at'])) ?></span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>

                    <td class="c-act">
                      <button type="button" class="ap-btn sm primary ap-open"><?= $pending ? 'Review' : 'View' ?></button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($total_count > 0): ?>
            <div class="ap-pager">
              <span>Showing <?= $offset + 1 ?>–<?= $offset + count($rows) ?> of <?= $total_count ?></span>
              <?php if ($total_pages > 1): ?>
                <nav class="ap-pages" aria-label="Pagination">
                  <?php if ($page > 1): ?><a href="<?= $e($url(['page' => $page - 1])) ?>" aria-label="Previous page">&larr;</a><?php endif; ?>
                  <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                    <?php if ($pg === $page): ?><span class="cur" aria-current="page"><?= $pg ?></span>
                    <?php else: ?><a href="<?= $e($url(['page' => $pg])) ?>"><?= $pg ?></a><?php endif; ?>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?><a href="<?= $e($url(['page' => $page + 1])) ?>" aria-label="Next page">&rarr;</a><?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
          <?php endif; ?>
      </section>

    </div>
  </div>

  <div class="ap-scrim" id="apScrim"></div>
  <aside class="ap-drawer" id="apDrawer" role="dialog" aria-modal="true" aria-labelledby="apDTitle" aria-hidden="true">
    <div class="ap-d-head">
      <div><h2 id="apDTitle"></h2><p id="apDSub"></p></div>
      <button type="button" class="ap-x" id="apDClose" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <div class="ap-d-body" id="apDBody"></div>
    <form method="POST" class="ap-d-foot" id="apDForm">
      <input type="hidden" name="req_type" value="<?= $e($type) ?>">
      <input type="hidden" name="req_sem" value="<?= $ap_sem ?>">
      <input type="hidden" name="req_id" id="apDId" value="">
      <div class="ap-d-foot-inner" id="apDFoot"></div>
    </form>
  </aside>

  <script type="application/json" id="apData"><?= json_encode(['type' => $type, 'rows' => $payload], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

  <script>
  (function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const DATA = JSON.parse($('#apData').textContent);

    /* ── Hover labels: any [data-tip] element shows its full text ── */
    const tip = document.createElement('div');
    tip.className = 'ap-tip'; tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    let tipFor = null;
    function showTip(el) {
      tipFor = el;
      tip.textContent = el.dataset.tip;
      tip.classList.add('on');
      const b = el.getBoundingClientRect(), t = tip.getBoundingClientRect();
      let x = b.left + b.width / 2 - t.width / 2;
      x = Math.max(8, Math.min(x, window.innerWidth - t.width - 8));
      let y = b.top - t.height - 6;
      if (y < 8) y = b.bottom + 6;
      tip.style.left = x + 'px'; tip.style.top = y + 'px';
    }
    function hideTip() { tipFor = null; tip.classList.remove('on'); }
    document.addEventListener('mouseover', e => {
      const el = e.target.closest && e.target.closest('[data-tip]');
      if (el && el !== tipFor) showTip(el); else if (!el && tipFor) hideTip();
    });
    document.addEventListener('focusin', e => { const el = e.target.closest && e.target.closest('[data-tip]'); if (el) showTip(el); });
    document.addEventListener('focusout', hideTip);
    document.addEventListener('scroll', hideTip, true);

    /* ── Reason panels (bulk reject + reject all): the submit button stays
          disabled until a reason is typed; the textarea is disabled while
          hidden so it never blocks or rides along with an approval ── */
    function reasonPanel(panel, openBtn, cancelBtn, goBtn, ta, onToggle) {
      function set(open) {
        panel.hidden = !open;
        ta.disabled = !open;
        openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) ta.focus(); else { ta.value = ''; goBtn.disabled = true; }
        if (onToggle) onToggle(open);
      }
      openBtn.addEventListener('click', () => set(panel.hidden));
      cancelBtn.addEventListener('click', () => { set(false); openBtn.focus(); });
      ta.addEventListener('input', () => goBtn.disabled = !ta.value.trim());
      return set;
    }
    const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);

    /* ── Reject all (Curriculum batch strip) ── */
    const batch = $('#apBatch');
    if (batch) {
      reasonPanel($('#apAllRej'), $('#apAllRejBtn'), $('#apAllRejNo'), $('#apAllRejGo'), $('#apAllWhy'));
    }

    /* ── Bulk selection ── */
    const bulk = $('#apBulk'), toolbar = $('#apToolbar'), selAll = $('#apSelAll');
    const rowChks = () => [...document.querySelectorAll('.ap-row-chk')];
    const noun = DATA.type === 'curriculum' ? ['subject', 'subjects'] : ['request', 'requests'];
    let setBulkRej = null;
    function syncBulk() {
      if (!bulk) return;
      const picked  = rowChks().filter(c => c.checked);
      const on      = picked.length;
      const flagged = picked.filter(c => c.dataset.flagged).length;
      const ready   = on - flagged;
      bulk.hidden = on === 0;
      toolbar.hidden = on > 0;
      if (batch) batch.hidden = on > 0;   // one set of actions at a time
      if (on === 0 && setBulkRej) setBulkRej(false);
      $('#apBulkN').textContent = on;
      $('#apBulkFlag').textContent = flagged ? '· ' + flagged + ' flagged (skipped when approving)' : '';
      const app = $('#apBulkApp');
      app.disabled = ready === 0;
      app.textContent = flagged && ready ? 'Approve ' + ready + ' ready' : 'Approve selected';
      $('#apBulkRejGo').textContent = 'Reject ' + plural(on, noun[0], noun[1]);
      if (selAll) {
        const all = rowChks();
        selAll.disabled = all.length === 0;
        selAll.checked = all.length > 0 && all.every(c => c.checked);
        selAll.indeterminate = on > 0 && on < all.length;
      }
    }
    if (bulk) {
      rowChks().forEach(c => c.addEventListener('change', syncBulk));
      if (selAll) selAll.addEventListener('change', () => { rowChks().forEach(c => c.checked = selAll.checked); syncBulk(); });
      $('#apBulkClear').addEventListener('click', () => { rowChks().forEach(c => c.checked = false); syncBulk(); });
      setBulkRej = reasonPanel($('#apBulkRej'), $('#apBulkRejBtn'), $('#apBulkRejNo'), $('#apBulkRejGo'), $('#apBulkWhy'),
        open => { $('#apBulkApp').hidden = open; $('#apBulkRejBtn').hidden = open; });
      // The shared confirm dialog reads data-confirm at submit time — word it
      // for whichever button was pressed.
      $('#apBulkApp').addEventListener('click', () => {
        const ready = rowChks().filter(c => c.checked && !c.dataset.flagged).length;
        $('#apBulkDecision').value = 'bulk_approve';
        bulk.dataset.icon = 'question';
        bulk.dataset.confirm = 'Approve ' + plural(ready, noun[0], noun[1]) + '?';
      });
      $('#apBulkRejGo').addEventListener('click', () => {
        const on = rowChks().filter(c => c.checked).length;
        $('#apBulkDecision').value = 'bulk_reject';
        bulk.dataset.icon = 'warning';
        bulk.dataset.confirm = 'Reject ' + plural(on, noun[0], noun[1]) + '? The scheduler will need to resubmit.';
      });
      syncBulk();
    }

    /* ── Drawer ── */
    const drawer = $('#apDrawer'), scrim = $('#apScrim'), foot = $('#apDFoot');
    let openId = null, lastFocus = null;

    function weekGrid(slots) {
      const timed = slots.filter(s => s.timed);
      if (!timed.length) return '';
      const DAYS = ['Mon','Tue','Wed','Thu','Fri'];
      const start = Math.floor(Math.min(...timed.map(s => s.sm)) / 30) * 30;
      const end   = Math.ceil(Math.max(...timed.map(s => s.em)) / 30) * 30;
      const rows  = (end - start) / 30;
      let h = '<div class="ap-wk" style="grid-template-rows:auto repeat(' + rows + ',22px)" role="img" aria-label="Weekly schedule preview"><span></span>';
      DAYS.forEach(d => h += '<span class="h">' + d + '</span>');
      for (let i = 0; i < rows; i++) {
        const m = start + i * 30, r = i + 2;
        const lbl = m % 60 === 0 ? ((Math.floor(m / 60) % 12 || 12) + (m < 720 ? ' AM' : ' PM')) : '';
        h += '<span class="t" style="grid-row:' + r + ';grid-column:1">' + lbl + '</span>';
        h += '<span class="bg" style="grid-row:' + r + ';grid-column:2 / span 5"></span>';
      }
      timed.forEach(s => {
        const c = DAYS.indexOf(s.day); if (c < 0) return;
        const r1 = (s.sm - start) / 30 + 2, span = Math.max(1, Math.round((s.em - s.sm) / 30));
        h += '<span class="blk' + (s.conflict ? ' conflict' : '') + '" style="grid-row:' + r1 + ' / span ' + span + ';grid-column:' + (c + 2) + '" data-tip="' + esc(s.subject + ' · ' + s.label) + '">' + esc(s.subject) + '</span>';
      });
      return h + '</div><div class="ap-legend"><span><i></i>Scheduled</span><span><i class="c"></i>Conflict</span></div>';
    }

    function bodyHTML(r) {
      let h = '<h3>Summary</h3><dl class="ap-facts">' + r.facts.map(f => '<dt>' + esc(f[0]) + '</dt><dd>' + esc(f[1]) + '</dd>').join('') + '</dl>';
      if (r.total !== null) h += '<dl class="ap-facts" style="margin-top:8px"><dt>Slots scheduled</dt><dd>' + r.scheduled + ' of ' + r.total + ' subjects</dd></dl>';
      else if (r.count !== null) h += '<dl class="ap-facts" style="margin-top:8px"><dt>On approval</dt><dd>' + r.count + ' subjects added</dd></dl>';
      if (r.status === 'rejected' && r.reason) h += '<h3>Reason for rejection</h3><p class="ap-reason">' + esc(r.reason) + '</p>';
      h += '<h3>Checks</h3>';
      h += r.issues.length
        ? '<ul class="ap-issues">' + r.issues.map(i => '<li class="' + i.kind + '"><b>' + esc(i.title) + '</b>' + esc(i.text) + '</li>').join('') + '</ul>'
        : '<div class="ap-clean">No issues found.</div>';
      if (r.slots.length) {
        h += '<h3>Weekly preview</h3>' + weekGrid(r.slots);
        // One line per subject, listing all of its weekly classes.
        const bySubj = new Map();
        r.slots.forEach(s => { if (!bySubj.has(s.subject)) bySubj.set(s.subject, []); bySubj.get(s.subject).push(s); });
        h += '<h3>Subjects</h3><ul class="ap-list">' + [...bySubj].map(([name, ss]) => {
          const timed = ss.filter(s => s.timed), t = ss[0].teacher;
          return '<li><span class="n" data-tip="' + esc(name) + '">' + esc(name) + '</span><span class="m">' +
            (timed.length ? timed.map(s => esc(s.day + ' ' + s.label) + (s.room ? ' · ' + esc(s.room) : '')).join('<br>') : 'No classes yet') +
            '<br>' + (t ? esc(t) : 'No teacher') + '</span></li>';
        }).join('') + '</ul>';
      }
      if (r.viewUrl) h += '<p style="margin:16px 0 0"><a class="ap-link" href="' + esc(r.viewUrl) + '">Open in scheduler</a></p>';
      return h;
    }

    function footNormal(r) {
      if (r.status !== 'pending') {
        foot.innerHTML = '<span class="note">This request has been decided.</span><button type="button" class="ap-btn" id="apDDone">Close</button>';
        $('#apDDone').onclick = closeDrawer;
        return;
      }
      foot.innerHTML = '<button type="button" class="ap-btn bad" id="apDRej">Reject</button>' +
        (r.issues.length
          ? '<button type="button" class="ap-btn primary" id="apDAppAny">Approve anyway</button>'
          : '<button type="submit" name="decision" value="approve" class="ap-btn primary">Approve</button>');
      $('#apDRej').onclick = () => footReject(r);
      const any = $('#apDAppAny');
      if (any) any.onclick = () => footConfirm(r);
    }
    function footConfirm(r) {
      const n = r.issues.length;
      foot.innerHTML = '<div class="ap-rej"><p style="margin:0 0 10px">This request has ' + n + (n === 1 ? ' issue' : ' issues') + '. Approve it anyway?</p>' +
        '<div class="row"><button type="button" class="ap-btn" id="apDNo">Cancel</button>' +
        '<button type="submit" name="decision" value="approve" class="ap-btn primary">Approve anyway</button></div></div>';
      $('#apDNo').onclick = () => footNormal(r);
    }
    function footReject(r) {
      foot.innerHTML = '<div class="ap-rej"><label for="apWhy">Reason for rejection</label>' +
        '<textarea id="apWhy" name="rejection_reason" required placeholder="' + esc(r.first ? 'Tell ' + r.first + ' what to fix before resubmitting' : 'Explain what needs to change') + '"></textarea>' +
        '<div class="row"><button type="button" class="ap-btn" id="apDNo">Cancel</button>' +
        '<button type="submit" name="decision" value="reject" class="ap-btn bad-fill" id="apDGo" disabled>Reject request</button></div></div>';
      const ta = $('#apWhy'), go = $('#apDGo');
      ta.focus();
      ta.oninput = () => go.disabled = !ta.value.trim();
      $('#apDNo').onclick = () => footNormal(r);
    }

    function openDrawer(id, from) {
      const r = DATA.rows[id]; if (!r) return;
      openId = id; lastFocus = from || document.activeElement;
      $('#apDTitle').textContent = r.title;
      $('#apDSub').textContent = r.sub;
      $('#apDId').value = id;
      $('#apDBody').innerHTML = bodyHTML(r);
      $('#apDBody').scrollTop = 0;
      footNormal(r);
      drawer.classList.add('on'); drawer.setAttribute('aria-hidden', 'false'); scrim.classList.add('on');
      setTimeout(() => $('#apDClose').focus(), 30);
    }
    function closeDrawer() {
      if (openId === null) return;
      openId = null;
      drawer.classList.remove('on'); drawer.setAttribute('aria-hidden', 'true'); scrim.classList.remove('on');
      if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }

    // Whole row opens the drawer; checkbox and links keep their own behaviour.
    $('.ap-table tbody') && $('.ap-table tbody').addEventListener('click', e => {
      if (e.target.closest('input, a, label')) return;
      const tr = e.target.closest('tr[data-id]'); if (!tr) return;
      openDrawer(tr.dataset.id, tr.querySelector('.ap-rowlink'));
    });
    $('#apDClose').addEventListener('click', closeDrawer);
    scrim.addEventListener('click', closeDrawer);
    $('#apDForm').addEventListener('submit', e => {
      const f = e.currentTarget;
      if (f.dataset.busy === '1') { e.preventDefault(); return; }
      f.dataset.busy = '1';
    });

    document.addEventListener('keydown', e => {
      if (openId === null) return;
      if (e.key === 'Escape' && !document.querySelector('.swal2-container')) { closeDrawer(); return; }
      if (e.key === 'Tab') {   // keep focus inside the drawer while it is open
        const f = [...drawer.querySelectorAll('a[href], button:not([disabled]), textarea, input:not([type=hidden])')].filter(x => x.offsetParent !== null);
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
  })();
  </script>

</body>
</html>
