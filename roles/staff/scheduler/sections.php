<?php
/* ============================================================
   sections.php — Coordinator (Staff) Section Management
   ------------------------------------------------------------
   ASSUMPTIONS / FOLLOW-UPS — please confirm or adjust:

   1. Run sections_approval_migration.sql first. It adds
      approval_status, requested_by, reviewed_by, reviewed_at,
      rejection_reason to `sections`.

   2. Access: staff with department='scheduler' can view this page
      and submit new sections (which land as 'pending'). role='admin'
      and staff with department='coordinator' are reviewers — they
      get Approve / Reject controls on pending rows.

   3. populate_section_subjects() (teachers/schedule/subjects) now
      runs on APPROVAL, not on creation. A pending section has no
      subjects yet. (The old manual "Generate Subjects" row action was
      removed — approval already generates them.)

   4. Deactivate / Reactivate are NOT gated by the approval system —
      only brand-new section creation is. The Action column (approve /
      reject / deactivate / reactivate) only renders for reviewers.

   5. staff_sidebar.php is assumed to render
      <aside class="staff-sidebar" id="staffSidebar">…</aside>
      as a sibling before .staff-main (per css_staff.css's
      `.staff-sidebar.open ~ .staff-main` rule), and to expose the
      logged-in staff member's session data itself.

   6. $_SESSION is assumed to carry: 'role' ('admin'|'staff'),
      'department' (for staff), and 'user_id'.
   ============================================================ */

session_start();
// Buffer all output (including anything staff_sidebar.php prints once it's
// included further down, inside the HTML) so header()/redirect calls never
// fail with "headers already sent" no matter where they happen in the page.
ob_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/_schedule_lib.php';

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$is_reviewer  = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');
$is_scheduler = ($role === 'staff' && $department === 'scheduler');

if (!$is_reviewer && !$is_scheduler) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

// ── AJAX: enrolled students for a section ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'students' && isset($_GET['section_id'])) {
    header('Content-Type: application/json');
    $sid = (int)$_GET['section_id'];
    $res = $conn->prepare("
        SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, e.admission_strand AS strand,
               s.family_name, s.given_name, s.student_number
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.section_id = ? AND e.status IN ('pending','enrolled')
        ORDER BY s.family_name, s.given_name
    ");
    $res->bind_param('i', $sid);
    $res->execute();
    $data = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($data);
    $conn->close();
    exit();
}

// ── Valid strands / school years ────────────────────────────────────────────
$valid_strands = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT'];
// Sourced from school_year_settings (not a synthetic date-based range) so a
// submitted year always exists as a row there — school_year now has an
// enforced FK to school_year_settings.school_year.
$valid_school_years = [];
$sy_res = mysqli_query($conn, "SELECT school_year FROM school_year_settings ORDER BY school_year DESC");
if ($sy_res) { while ($r = mysqli_fetch_row($sy_res)) { $valid_school_years[] = $r[0]; } }

// The year the page opens on: whichever year is open for admission or
// enrollment (same rule as curriculum.php), else the newest one.
$dy_res = mysqli_query($conn, "
    SELECT school_year FROM school_year_settings
    WHERE is_admission_open = 1 OR is_enrollment_open = 1
    ORDER BY school_year DESC LIMIT 1
");
$current_year = $dy_res && ($r = mysqli_fetch_row($dy_res)) ? $r[0] : ($valid_school_years[0] ?? '');

// A year before the current one has ended. Its sections stay as records
// (enrollment only ever offers the enrolling year's sections), so they show
// as "Past year" instead of Active — nothing to switch off by hand.
// "2025-2026" < "2026-2027" compares correctly as a string.
function is_past_year(string $sy, string $current): bool {
    return $current !== '' && strcmp($sy, $current) < 0;
}

// Maps a strand code to one of the .strand-badge--* color modifiers defined
// in this page's <style> block.
function strand_badge_class(?string $code): string {
    $map = ['STEM' => 'stem', 'HUMSS' => 'humss', 'ABM' => 'abm', 'GAS' => 'gas', 'TVL-ICT' => 'tvl', 'TVL' => 'tvl', 'CORE' => 'core'];
    return $map[$code] ?? 'none';
}

// ── Room helpers ─────────────────────────────────────────────────────────────
// A section's homeroom must be unique within its school year (among sections
// that are active and pending/approved — inactive or rejected ones free
// their room). Rooms are compared case- and whitespace-insensitively, so
// "r 101" and "R101" count as the same room.
function room_key(string $room): string {
    return strtoupper(preg_replace('/\s+/', '', $room));
}

// Every room in use, grouped by school year: [school_year => [ROOM_KEY => section_name]]
function rooms_in_use(mysqli $conn): array {
    $out = [];
    $res = mysqli_query($conn, "
        SELECT school_year, room, section_name FROM sections
        WHERE is_active = 1 AND approval_status IN ('pending', 'approved')
          AND room IS NOT NULL AND TRIM(room) <> ''
        ORDER BY section_name
    ");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $out[$r['school_year']][room_key($r['room'])] ??= $r['section_name'];
        }
    }
    return $out;
}

// Room keys in use in any school year. Suggestions draw from these so a new
// year (with few or no rooms yet) still follows the school's R101… pattern and
// skips rooms held by sections that will likely be carried over.
function all_room_keys(array $rooms_by_year): array {
    $keys = [];
    foreach ($rooms_by_year as $rooms) $keys += $rooms;
    return array_keys($keys);
}

// Suggests the next free room number: takes the most common "<prefix><number>"
// pattern (e.g. R101…R120) and walks up from its lowest number to the first
// one not taken — so R101, R102 taken suggests R103, and a stray outlier like
// R1107 doesn't push the suggestion to R1108. A lettered prefix ("R") beats
// bare numbers ("2"), so a stray "2" doesn't turn the suggestion into "3".
function suggest_room(array $taken_keys): string {
    $by_prefix = [];
    foreach ($taken_keys as $key) {
        if (preg_match('/^(.*?)(\d+)$/', $key, $m)) {
            $by_prefix[$m[1]][(int)$m[2]] = strlen($m[2]);
        }
    }
    if (!$by_prefix) return 'R101';
    uksort($by_prefix, fn($a, $b) => [$b !== '', count($by_prefix[$b])] <=> [$a !== '', count($by_prefix[$a])]);
    $prefix = array_key_first($by_prefix);
    $nums   = $by_prefix[$prefix];
    $n      = min(array_keys($nums));
    $width  = $nums[$n];
    while (isset($nums[$n])) $n++;
    return $prefix . str_pad((string)$n, $width, '0', STR_PAD_LEFT);
}

// ── Weekly business-hour slot list (Mon-Fri, hourly blocks 7:30 AM-4:30 PM) ──
function weekly_slots() {
    $days  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    $slots = [];
    foreach ($days as $day) {
        for ($hour = 7; $hour < 16; $hour++) {
            $start = sprintf('%02d:30:00', $hour);
            $end   = sprintf('%02d:30:00', $hour + 1);
            $slots[] = ['day' => $day, 'start_time' => $start, 'end_time' => $end];
        }
    }
    return $slots;
}

// Assigns each subject_id a random-but-non-overlapping weekly slot for one section
function assign_subject_schedules($conn, $section_id, $subject_ids) {
    $slots = weekly_slots();
    $offset = array_rand($slots);
    foreach ($subject_ids as $i => $subject_id) {
        $slot = $slots[($offset + $i) % count($slots)];
        // Written as the subject's single first meeting (section_subject_meetings),
        // which also mirrors it onto section_subjects' legacy day/time columns.
        sched_write_meetings($conn, (int)$section_id, (int)$subject_id, 1, [
            ['day' => $slot['day'], 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time'], 'room' => null],
        ]);
    }
}

// Populates section_subjects (with a random teacher and a generated schedule)
// for every subject eligible for this section's grade/strand (+ CORE subjects).
// Runs on APPROVAL (or immediately for admin-created sections).
// $strandId must be the resolved strands.strand_id, not the code string.
function populate_section_subjects($conn, $section_id, $grade_level, $strandId, $school_year) {
    // Scoped to the section's own school_year — subjects are now tagged per
    // year (see scheduler/curriculum.php), so a section only ever pulls
    // subjects that actually belong to its curriculum year, not last year's
    // (or next year's) list.
    $coreId = strand_id($conn, 'CORE');
    $subj_stmt = $conn->prepare(
        "SELECT subject_id FROM subjects WHERE grade_level = ? AND strand IN (?, ?) AND school_year = ? AND is_active = 1 AND review_status = 'approved'"
    );
    $subj_stmt->bind_param('siis', $grade_level, $strandId, $coreId, $school_year);
    $subj_stmt->execute();
    $subject_ids = array_column($subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_id');
    $subj_stmt->close();

    $psst_sec_stmt = $conn->prepare("SELECT section_name FROM sections WHERE section_id = ?");
    $psst_sec_stmt->bind_param('i', $section_id);
    $psst_sec_stmt->execute();
    $psst_section_name = $psst_sec_stmt->get_result()->fetch_assoc()['section_name'] ?? '';
    $psst_sec_stmt->close();

    foreach ($subject_ids as $subject_id) {
        $teacher_row = $conn->query("SELECT teacher_id FROM teachers ORDER BY RAND() LIMIT 1")->fetch_assoc();
        $teacher_id  = $teacher_row ? (int) $teacher_row['teacher_id'] : null;

        $ss_stmt = $conn->prepare(
            "INSERT INTO section_subjects (section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, 1)"
        );
        $ss_stmt->bind_param('iii', $section_id, $subject_id, $teacher_id);
        $ss_stmt->execute();
        $ss_stmt->close();

        // Keep teacher_assignments (what the teacher portal actually reads)
        // in sync with section_subjects.teacher_id — no unique key on this
        // table, so clear any stale row for this slot first, then insert.
        // This function only ever seeds a brand-new section, which always
        // starts at Semester 1.
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

            $psst_subj_stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
            $psst_subj_stmt->bind_param('i', $subject_id);
            $psst_subj_stmt->execute();
            $psst_subject_name = $psst_subj_stmt->get_result()->fetch_assoc()['subject_name'] ?? '';
            $psst_subj_stmt->close();

            $psst_tu_stmt = $conn->prepare("SELECT user_id FROM teachers WHERE teacher_id = ?");
            $psst_tu_stmt->bind_param('i', $teacher_id);
            $psst_tu_stmt->execute();
            $psst_tu_row = $psst_tu_stmt->get_result()->fetch_assoc();
            $psst_tu_stmt->close();
            notify_teacher_users(
                $conn,
                [(int) ($psst_tu_row['user_id'] ?? 0)],
                "You've been assigned to teach \"$psst_subject_name\" — $psst_section_name.",
                'roles/teacher/teacher_schedule',
                'schedule'
            );
        }
    }

    assign_subject_schedules($conn, $section_id, $subject_ids);
    return count($subject_ids);
}

// ── Add Section POST ───────────────────────────────────────────────────────
// Coordinator submissions land as 'pending' and wait for admin approval.
// Admin submissions are auto-approved and populated immediately.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section'])) {
    $section_name = trim($_POST['section_name'] ?? '');
    $grade_level  = trim($_POST['grade_level']  ?? '');
    $strand       = trim($_POST['strand']        ?? '');
    $room         = trim($_POST['room']          ?? '');
    $capacity     = (int)($_POST['capacity']     ?? 0);
    $school_year  = trim($_POST['school_year']   ?? '');
    $used_rooms   = rooms_in_use($conn)[$school_year] ?? [];

    if (!$section_name || !$grade_level || !$strand || !$school_year || $capacity < 1) {
        $error = 'Section name, grade level, strand, school year, and capacity are required.';
    } elseif (!in_array($strand, $valid_strands, true)) {
        $error = 'Please select a valid strand.';
    } elseif (!in_array($school_year, $valid_school_years, true)) {
        $error = 'School year must be the current or upcoming academic year.';
    } elseif ($room !== '' && isset($used_rooms[room_key($room)])) {
        $error = "Room \"$room\" is already used by {$used_rooms[room_key($room)]} in SY $school_year. "
               . 'Try ' . suggest_room(all_room_keys(rooms_in_use($conn))) . ' instead.';
    } else {
        $room            = $room !== '' ? $room : null;
        $approval_status = $is_reviewer ? 'approved' : 'pending';
        $reviewed_by      = $is_reviewer ? $user_id : null;
        $reviewed_at      = $is_reviewer ? date('Y-m-d H:i:s') : null;
        $strandId         = strand_id($conn, $strand);

        $stmt = $conn->prepare(
            "INSERT INTO sections
                (section_name, grade_level, strand, room, capacity, school_year, is_active,
                 approval_status, requested_by, reviewed_by, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
        );
        // room is a string (varchar) — it was previously bound as 'i', which
        // silently saved every room as 0.
        $stmt->bind_param(
            'ssisissiis',
            $section_name, $grade_level, $strandId, $room, $capacity, $school_year,
            $approval_status, $user_id, $reviewed_by, $reviewed_at
        );

        if ($stmt->execute()) {
            $new_section_id = $stmt->insert_id;

            if ($is_reviewer) {
                populate_section_subjects($conn, $new_section_id, $grade_level, $strandId, $school_year);
                $success = "Section \"$section_name\" created and activated.";
            } else {
                $success = "Section \"$section_name\" submitted for coordinator approval. "
                          . "Subjects and a schedule will be generated automatically once it's approved.";
                notify_coordinators($conn, "New section \"$section_name\" awaiting approval.", 'coordinator/approvals?type=sections');
            }
        } else {
            $error = 'Could not create section. Please try again.';
        }
        $stmt->close();
    }
}

// ── Approve Section POST (ADMIN-ONLY) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_section'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can approve a new section.';
    } else {
        $sid = (int)($_POST['section_id'] ?? 0);
        $sec_stmt = $conn->prepare("SELECT grade_level, strand, school_year, approval_status, section_name, requested_by FROM sections WHERE section_id = ?");
        $sec_stmt->bind_param('i', $sid);
        $sec_stmt->execute();
        $sec_row = $sec_stmt->get_result()->fetch_assoc();
        $sec_stmt->close();

        if (!$sec_row) {
            $error = 'Section not found.';
        } elseif ($sec_row['approval_status'] !== 'pending') {
            $error = 'This section has already been reviewed.';
        } else {
            $upd = $conn->prepare(
                "UPDATE sections SET approval_status='approved', reviewed_by=?, reviewed_at=NOW() WHERE section_id=?"
            );
            $upd->bind_param('ii', $user_id, $sid);
            if ($upd->execute()) {
                populate_section_subjects($conn, $sid, $sec_row['grade_level'], $sec_row['strand'], $sec_row['school_year']);
                $upd->close();
                if ($sec_row['requested_by']) {
                    notify_users($conn, [$sec_row['requested_by']], "Section \"{$sec_row['section_name']}\" was approved.", 'scheduler/sections', 'schedule');
                }
                header("Location: sections?" . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'school_year' => $_GET['school_year'] ?? '', 'grade_level' => $_GET['grade_level'] ?? '', 'strand' => $_GET['strand'] ?? '', 'filter' => $_GET['filter'] ?? 'pending', 'page' => $_GET['page'] ?? ''])));
                exit();
            }
            $upd->close();
            $error = 'Could not approve section. Please try again.';
        }
    }
}

// ── Reject Section POST (ADMIN-ONLY) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_section'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can reject a new section.';
    } else {
        $sid    = (int)($_POST['section_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        $reason = $reason !== '' ? $reason : null;

        $sec_stmt = $conn->prepare("SELECT approval_status, section_name, requested_by FROM sections WHERE section_id = ?");
        $sec_stmt->bind_param('i', $sid);
        $sec_stmt->execute();
        $sec_row = $sec_stmt->get_result()->fetch_assoc();
        $sec_stmt->close();

        if (!$sec_row) {
            $error = 'Section not found.';
        } elseif ($sec_row['approval_status'] !== 'pending') {
            $error = 'This section has already been reviewed.';
        } else {
            $upd = $conn->prepare(
                "UPDATE sections
                 SET approval_status='rejected', is_active=0, rejection_reason=?, reviewed_by=?, reviewed_at=NOW()
                 WHERE section_id=?"
            );
            $upd->bind_param('sii', $reason, $user_id, $sid);
            if ($upd->execute()) {
                $upd->close();
                if ($sec_row['requested_by']) {
                    notify_users($conn, [$sec_row['requested_by']], "Section \"{$sec_row['section_name']}\" was rejected.", 'scheduler/sections', 'schedule');
                }
                header("Location: sections?" . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'school_year' => $_GET['school_year'] ?? '', 'grade_level' => $_GET['grade_level'] ?? '', 'strand' => $_GET['strand'] ?? '', 'filter' => $_GET['filter'] ?? 'pending', 'page' => $_GET['page'] ?? ''])));
                exit();
            }
            $upd->close();
            $error = 'Could not reject section. Please try again.';
        }
    }
}

// ── Deactivate (soft delete) — coordinator/admin only ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_section'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can deactivate a section.';
    } else {
        $sid = (int)($_POST['section_id'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE sections SET is_active = 0 WHERE section_id = ? AND approval_status = 'approved'"
        );
        $stmt->bind_param('i', $sid);
        if ($stmt->execute()) {
            header("Location: sections?" . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'school_year' => $_GET['school_year'] ?? '', 'grade_level' => $_GET['grade_level'] ?? '', 'strand' => $_GET['strand'] ?? '', 'filter' => $_GET['filter'] ?? 'all', 'page' => $_GET['page'] ?? ''])));
            exit();
        }
        $error = 'Could not deactivate section. Please try again.';
        $stmt->close();
    }
}

// ── Reactivate (undo soft delete) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_section'])) {
    $sid = (int)($_POST['section_id'] ?? 0);
    $stmt = $conn->prepare(
        "UPDATE sections SET is_active = 1 WHERE section_id = ? AND approval_status = 'approved'"
    );
    $stmt->bind_param('i', $sid);
    if ($stmt->execute()) {
        header("Location: sections?" . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'school_year' => $_GET['school_year'] ?? '', 'grade_level' => $_GET['grade_level'] ?? '', 'strand' => $_GET['strand'] ?? '', 'filter' => $_GET['filter'] ?? 'all', 'page' => $_GET['page'] ?? ''])));
        exit();
    }
    $error = 'Could not reactivate section. Please try again.';
    $stmt->close();
}

// ── Edit an approved section (name / room / capacity) — coordinator/admin ────
// Grade, strand and school year stay fixed: its subjects and schedule were
// generated from them on approval.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_section'])) {
    $sid          = (int)($_POST['section_id'] ?? 0);
    $section_name = trim($_POST['section_name'] ?? '');
    $room         = trim($_POST['room'] ?? '');
    $capacity     = (int)($_POST['capacity'] ?? 0);

    $sec = null;
    if ($is_reviewer) {
        $st = $conn->prepare("
            SELECT s.school_year,
                   COUNT(DISTINCT CASE WHEN e.status IN ('pending','enrolled') THEN e.enrollment_id END) AS enrolled
            FROM sections s LEFT JOIN enrollments e ON e.section_id = s.section_id
            WHERE s.section_id = ? AND s.approval_status = 'approved'
            GROUP BY s.section_id");
        $st->bind_param('i', $sid);
        $st->execute();
        $sec = $st->get_result()->fetch_assoc();
        $st->close();
    }
    // Rooms taken by the year's other sections (this one's own room is free to keep).
    $taken = [];
    if ($sec && $room !== '') {
        $st = $conn->prepare("
            SELECT room, section_name FROM sections
            WHERE school_year = ? AND section_id <> ? AND is_active = 1
              AND approval_status IN ('pending', 'approved') AND room IS NOT NULL AND TRIM(room) <> ''");
        $st->bind_param('si', $sec['school_year'], $sid);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) $taken[room_key($t['room'])] ??= $t['section_name'];
        $st->close();
    }

    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can edit a section.';
    } elseif (!$sec) {
        $error = 'Section not found.';
    } elseif ($section_name === '' || $capacity < 1) {
        $error = 'Section name and capacity are required.';
    } elseif ($capacity < (int)$sec['enrolled']) {
        $error = "Capacity can't go below the {$sec['enrolled']} student" . ((int)$sec['enrolled'] === 1 ? '' : 's') . ' already placed in this section.';
    } elseif ($room !== '' && isset($taken[room_key($room)])) {
        $error = "Room \"$room\" is already used by {$taken[room_key($room)]} in SY {$sec['school_year']}.";
    } else {
        $room = $room !== '' ? $room : null;
        $st = $conn->prepare("UPDATE sections SET section_name = ?, room = ?, capacity = ? WHERE section_id = ?");
        $st->bind_param('ssii', $section_name, $room, $capacity, $sid);
        if ($st->execute()) {
            $_SESSION['sections_flash'] = "Section \"$section_name\" updated.";
            header("Location: sections?" . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'school_year' => $_GET['school_year'] ?? '', 'grade_level' => $_GET['grade_level'] ?? '', 'strand' => $_GET['strand'] ?? '', 'filter' => $_GET['filter'] ?? 'all', 'page' => $_GET['page'] ?? ''])));
            exit();
        }
        $error = 'Could not update section. Please try again.';
        $st->close();
    }
}

// ── Deactivate every active section of an ended school year — coordinator/admin ──
// Only for years before the current one, so the year that's enrolling now
// can't lose all its sections to one click.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_year'])) {
    $sy = trim($_POST['school_year'] ?? '');
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can deactivate sections.';
    } elseif (!in_array($sy, $valid_school_years, true)) {
        $error = 'Please choose a valid school year.';
    } elseif (!is_past_year($sy, $current_year)) {
        $error = "SY $sy hasn't ended yet — deactivate its sections one at a time if needed.";
    } else {
        $stmt = $conn->prepare("UPDATE sections SET is_active = 0 WHERE school_year = ? AND approval_status = 'approved' AND is_active = 1");
        $stmt->bind_param('s', $sy);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['sections_flash'] = $n > 0 ? "Deactivated $n section" . ($n === 1 ? '' : 's') . " in SY $sy." : "SY $sy had no active sections.";
        header("Location: sections?" . http_build_query(['school_year' => $sy, 'filter' => $_GET['filter'] ?? 'all']));
        exit();
    }
}

// ── Carry selected sections into another school year ──────────────────────
// Copies name / grade / strand / room / capacity (never students, teachers
// or schedules). Each copy gets its subjects from the TARGET year's
// curriculum, so carry the curriculum over first. A copy whose room is
// already taken in the target year keeps no room; one whose name + grade +
// strand already exists there is skipped. Coordinator copies are approved
// (and populated) straight away; a scheduler's wait in Approvals, exactly
// like a new section.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carry_sections'])) {
    $source_year = trim($_POST['source_year'] ?? '');
    $target_year = trim($_POST['target_year'] ?? '');
    $picked_ids  = array_values(array_unique(array_filter(array_map('intval', $_POST['section_id'] ?? []))));

    if (!in_array($source_year, $valid_school_years, true) || !in_array($target_year, $valid_school_years, true)) {
        $error = 'Please choose valid school years.';
    } elseif ($source_year === $target_year) {
        $error = 'Choose two different school years to copy between.';
    } elseif (is_past_year($target_year, $current_year)) {
        $error = "SY $target_year has already ended — carry sections into the current or an upcoming year.";
    } elseif (!$picked_ids) {
        $error = 'Please select at least one section to carry over.';
    } else {
        $ph = implode(',', array_fill(0, count($picked_ids), '?'));
        $src_stmt = $conn->prepare("
            SELECT section_name, grade_level, strand, room, capacity FROM sections
            WHERE section_id IN ($ph) AND school_year = ? AND approval_status = 'approved'
            ORDER BY grade_level, section_name
        ");
        $src_stmt->bind_param(str_repeat('i', count($picked_ids)) . 's', ...[...$picked_ids, $source_year]);
        $src_stmt->execute();
        $sources = $src_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $src_stmt->close();

        if (!$sources) {
            $error = 'None of the selected sections could be found in that school year.';
        } else {
            $exist_stmt = $conn->prepare("
                SELECT section_name, grade_level, strand FROM sections
                WHERE school_year = ? AND approval_status IN ('pending', 'approved')
            ");
            $exist_stmt->bind_param('s', $target_year);
            $exist_stmt->execute();
            $existing = [];
            foreach ($exist_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $x) {
                $existing[strtolower(trim($x['section_name'])) . '|' . $x['grade_level'] . '|' . $x['strand']] = true;
            }
            $exist_stmt->close();
            $used_rooms = rooms_in_use($conn)[$target_year] ?? [];

            $approval_status = $is_reviewer ? 'approved' : 'pending';
            $reviewed_by     = $is_reviewer ? $user_id : null;
            $reviewed_at     = $is_reviewer ? date('Y-m-d H:i:s') : null;
            $copied = $skipped = $no_room = $no_subjects = 0;

            $conn->begin_transaction();
            try {
                $ins = $conn->prepare("
                    INSERT INTO sections
                        (section_name, grade_level, strand, room, capacity, school_year, is_active,
                         approval_status, requested_by, reviewed_by, reviewed_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
                ");
                foreach ($sources as $s) {
                    $key = strtolower(trim($s['section_name'])) . '|' . $s['grade_level'] . '|' . $s['strand'];
                    if (isset($existing[$key])) { $skipped++; continue; }
                    $room = trim((string)$s['room']);
                    if ($room !== '' && isset($used_rooms[room_key($room)])) { $room = ''; $no_room++; }
                    $room = $room !== '' ? $room : null;
                    $strandId = (int)$s['strand'];
                    $capacity = (int)$s['capacity'];
                    $ins->bind_param('ssisissiis', $s['section_name'], $s['grade_level'], $strandId, $room, $capacity,
                        $target_year, $approval_status, $user_id, $reviewed_by, $reviewed_at);
                    $ins->execute();
                    $new_id = $ins->insert_id;
                    $existing[$key] = true;
                    if ($room !== null) $used_rooms[room_key($room)] = $s['section_name'];
                    if ($is_reviewer && populate_section_subjects($conn, $new_id, $s['grade_level'], $strandId, $target_year) === 0) {
                        $no_subjects++;
                    }
                    $copied++;
                }
                $ins->close();
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $copied = -1;
                $error  = 'Could not carry over sections. Nothing was copied — please try again.';
            }

            if ($copied >= 0) {
                $plural = fn($n, $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');
                $msg = $copied === 0
                    ? "Those sections are already part of SY $target_year."
                    : ($is_reviewer
                        ? $plural($copied, 'section') . " added to SY $target_year."
                        : $plural($copied, 'section') . " submitted for coordinator approval in SY $target_year.");
                if ($copied > 0 && $skipped)  $msg .= ' ' . $plural($skipped, 'section') . ' already existed and ' . ($skipped === 1 ? 'was' : 'were') . ' skipped.';
                if ($no_room)     $msg .= ' ' . $plural($no_room, 'section') . ' had ' . ($no_room === 1 ? 'its room' : 'their rooms') . ' already taken and now ' . ($no_room === 1 ? 'has' : 'have') . ' no room.';
                if ($no_subjects) $msg .= ' ' . $plural($no_subjects, 'section') . " got no subjects — SY $target_year's curriculum has none approved for "
                                  . ($no_subjects === 1 ? 'its' : 'their') . ' grade/strand yet. Carry the curriculum over first.';
                if (!$is_reviewer && $copied > 0) {
                    notify_coordinators($conn, $plural($copied, 'section') . " carried into SY $target_year awaiting approval.", 'coordinator/approvals?type=sections');
                }
                $_SESSION['sections_flash'] = $msg;
                header("Location: sections?" . http_build_query(['school_year' => $target_year]));
                exit();
            }
        }
    }
}

// The Add Section modal reopens itself (with this error inside it) when the
// submission fails, instead of losing what was typed — same as
// admin/staff_manage.php's Add staff modal.
$add_error = $carry_error = $edit_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section']) && $error !== '') {
    $add_error = $error;
    $error     = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_section']) && $error !== '' && $is_reviewer) {
    $edit_error = $error;
    $error      = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carry_sections']) && $error !== '') {
    $carry_error = $error;
    $error       = '';
}
// Success messages from redirecting handlers (carry over, deactivate year).
if (!empty($_SESSION['sections_flash'])) {
    $success = $_SESSION['sections_flash'];
    unset($_SESSION['sections_flash']);
}

// Rooms already taken per school year + a suggested next room, for the
// Add Section modal's live check (the server re-checks on submit above).
$rooms_by_year   = rooms_in_use($conn);
$room_suggestion = [];
$next_room       = suggest_room(all_room_keys($rooms_by_year));
foreach ($valid_school_years as $sy) {
    $room_suggestion[$sy] = $next_room;
}

// ── Filter ───────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$allowed_filters = ['all', 'active', 'inactive', 'pending', 'rejected'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'all';

// One school year at a time (defaults to the current one), so last year's
// sections are out of the way without having to switch them off.
$f_year = in_array($_GET['school_year'] ?? '', $valid_school_years, true) ? $_GET['school_year'] : $current_year;
$year_is_past = is_past_year($f_year, $current_year);

// Search (section name or room) + grade + strand, same toolbar as curriculum.php.
$f_q      = mb_substr(trim($_GET['q'] ?? ''), 0, 50);
$f_grade  = in_array($_GET['grade_level'] ?? '', ['11', '12'], true) ? $_GET['grade_level'] : '';
$f_strand = trim($_GET['strand'] ?? '');

// Which status each row falls under (the status switch).
$status_of = function (array $r): array {
    $out = ['all'];
    if ($r['approval_status'] === 'approved' && $r['is_active']) $out[] = 'active';
    if ($r['approval_status'] === 'pending')                      $out[] = 'pending';
    if ($r['approval_status'] === 'rejected')                     $out[] = 'rejected';
    if ($r['approval_status'] !== 'pending' && !$r['is_active'])  $out[] = 'inactive';
    return $out;
};
$where = "s.school_year = '" . mysqli_real_escape_string($conn, $f_year) . "'";

// For the "Deactivate all" confirmation: students still placed in this
// year's active sections.
$ye_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT e.enrollment_id) FROM enrollments e
    JOIN sections s ON s.section_id = e.section_id
    WHERE s.school_year = ? AND s.is_active = 1 AND s.approval_status = 'approved' AND e.status IN ('pending','enrolled')
");
$ye_stmt->bind_param('s', $f_year);
$ye_stmt->execute();
$year_enrolled = (int)$ye_stmt->get_result()->fetch_row()[0];
$ye_stmt->close();

// ── Carry Over modal data ────────────────────────────────────────────────
// Per year: approved sections [id, name, grade, strand, room, capacity] (the
// ones that can be copied) and the name|grade|strand keys already taken
// (pending or approved). Plus how many approved curriculum subjects each
// year has per grade|strand, to warn before copying into an empty curriculum.
$carry = ['years' => array_fill_keys($valid_school_years, []), 'taken' => array_fill_keys($valid_school_years, []), 'curr' => []];
$cr = mysqli_query($conn, "
    SELECT s.section_id, s.section_name, s.grade_level, st.strand_code, s.room, s.capacity, s.school_year, s.approval_status
    FROM sections s JOIN strands st ON st.strand_id = s.strand
    WHERE s.approval_status IN ('pending', 'approved')
    ORDER BY s.grade_level, s.section_name
");
while ($cr && $c = mysqli_fetch_assoc($cr)) {
    $k = strtolower(trim($c['section_name'])) . '|' . $c['grade_level'] . '|' . $c['strand_code'];
    $carry['taken'][$c['school_year']][] = $k;
    if ($c['approval_status'] === 'approved') {
        $carry['years'][$c['school_year']][] = [(int)$c['section_id'], $c['section_name'], $c['grade_level'], $c['strand_code'], (string)$c['room'], (int)$c['capacity']];
    }
}
$cc = mysqli_query($conn, "
    SELECT s.school_year, s.grade_level, st.strand_code, COUNT(*) n
    FROM subjects s JOIN strands st ON st.strand_id = s.strand
    WHERE s.is_active = 1 AND s.review_status = 'approved'
    GROUP BY s.school_year, s.grade_level, st.strand_code
");
while ($cc && $c = mysqli_fetch_assoc($cc)) {
    $carry['curr'][$c['school_year']][$c['grade_level'] . '|' . $c['strand_code']] = (int)$c['n'];
}
$carry['rooms'] = rooms_in_use($conn);
$carry['past']  = array_values(array_filter($valid_school_years, fn($y) => is_past_year($y, $current_year)));
$carry_open     = $carry_error !== '';
$carry_source   = $carry_open ? ($_POST['source_year'] ?? '') : '';
$carry_target   = $carry_open ? ($_POST['target_year'] ?? '') : '';
if (!$carry_open) {
    // Default: from the viewed year (or else the newest year that has
    // sections) into the next year after it that hasn't ended.
    $carry_source = !empty($carry['years'][$f_year]) ? $f_year : '';
    foreach ($valid_school_years as $y) {
        if ($carry_source === '' && !empty($carry['years'][$y])) $carry_source = $y;
    }
    $later = array_filter($valid_school_years, fn($y) => strcmp($y, $carry_source) > 0 && !is_past_year($y, $current_year));
    sort($later);
    $carry_target = $later[0] ?? $current_year;
}
$carry['picked'] = $carry_open ? array_map('intval', $_POST['section_id'] ?? []) : [];
// Strand chips: the strands sections actually use, in the usual order.
$strand_order  = ['CORE', 'STEM', 'HUMSS', 'ABM', 'GAS', 'TVL', 'TVL-ICT'];
$carry_strands = array_unique(array_column(array_merge([], ...array_values($carry['years'])), 3));
$strand_rank = fn($s) => ($i = array_search($s, $strand_order, true)) === false ? 99 : $i;
usort($carry_strands, fn($a, $b) => $strand_rank($a) <=> $strand_rank($b));

// Pending count, shown to admins as a heads-up regardless of current filter
$pending_count = 0;
if ($is_reviewer) {
    $pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sections WHERE approval_status = 'pending'");
    $pending_count = $pc ? (int) mysqli_fetch_assoc($pc)['c'] : 0;
}

// ── Fetch sections ─────────────────────────────────────────────────────────
$sql = "
    SELECT s.*,
           COUNT(DISTINCT CASE WHEN e.status IN ('pending','enrolled') THEN e.enrollment_id END) AS enrolled,
           COUNT(DISTINCT ss.subject_id) AS subj_count,
           req.given_name AS req_given, req.family_name AS req_family,
           rev.given_name AS rev_given, rev.family_name AS rev_family,
           st.strand_code AS strand
    FROM sections s
    JOIN strands st ON st.strand_id = s.strand
    LEFT JOIN enrollments e ON e.section_id = s.section_id
    LEFT JOIN section_subjects ss ON ss.section_id = s.section_id
    LEFT JOIN staff req ON req.user_id = s.requested_by
    LEFT JOIN staff rev ON rev.user_id = s.reviewed_by
    WHERE $where
    GROUP BY s.section_id
    ORDER BY (s.approval_status = 'pending') DESC, s.is_active DESC,
             s.grade_level ASC, s.strand ASC, s.section_name ASC
";
$result = mysqli_query($conn, $sql);
$year_rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

// "Deactivate all" works on the whole year, whatever the filters.
$year_active = count(array_filter($year_rows, fn($r) => in_array('active', $status_of($r), true)));
$can_deactivate_year = $is_reviewer && $year_active > 0 && is_past_year($f_year, $current_year);
$year_strands = array_values(array_unique(array_merge(['STEM', 'HUMSS', 'ABM', 'GAS', 'TVL'], array_column($year_rows, 'strand'))));

// Search / grade / strand, then the status counts, then the status itself.
$q_lc = mb_strtolower($f_q);
$rows = array_values(array_filter($year_rows, fn($r) =>
    ($q_lc === '' || str_contains(mb_strtolower($r['section_name']), $q_lc) || str_contains(mb_strtolower((string)$r['room']), $q_lc))
    && ($f_grade === '' || $r['grade_level'] === $f_grade)
    && ($f_strand === '' || $r['strand'] === $f_strand)));
$tab_counts = array_fill_keys($allowed_filters, 0);
foreach ($rows as $r) { foreach ($status_of($r) as $k) $tab_counts[$k]++; }
$rows = array_values(array_filter($rows, fn($r) => in_array($filter, $status_of($r), true)));

// 10 per page (the list is already filtered/sorted above).
$per_page    = 10;
$total_count = count($rows);
$total_pages = max(1, (int)ceil($total_count / $per_page));
$page        = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$rows        = array_slice($rows, ($page - 1) * $per_page, $per_page);
// NOTE: $conn is intentionally left open here — staff_sidebar.php (included
// further down, in the HTML) runs its own queries against it. PHP closes the
// connection automatically at end of script.

$filter_labels = [
    'all'      => 'All',
    'active'   => 'Approved',
    'pending'  => 'Pending',
    'rejected' => 'Rejected',
    'inactive' => 'Inactive',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sections — <?= $is_reviewer ? 'Coordinator' : 'Scheduler' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    /* Supplemental styles for the sections table/modal — css_staff.css doesn't
       define a generic data table, so these piggyback on its CSS variables. */
    /* ── Card + toolbar (same as curriculum.php) ── */
    .cu-card { background:#fff; border:1px solid #E4E4EC; border-radius:16px; overflow:hidden;
      box-shadow:0 1px 2px rgba(16,24,40,.04), 0 1px 8px rgba(16,24,40,.03); }
    .cu-bar { display:flex; flex-direction:column; gap:10px; padding:14px 16px 12px; margin:0; }
    .cu-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cu-row--filters { justify-content:space-between; gap:12px; }
    .cu-row-l { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cu-row .sec-btn-danger { margin-left:auto; }
    .cu-field { position:relative; }
    .cu-field--grow { flex:1 1 260px; }
    .cu-card .cu-field--grow .cu-input { width:100%; }
    .cu-field svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#54615A; pointer-events:none; }
    /* .cu-card prefix: css_staff.css sets width:100% / height:40px on bare inputs + selects */
    .cu-card .cu-input, .cu-card .cu-select { height:36px; width:auto; margin:0; border:1px solid #C4CFC8; border-radius:8px; background-color:#fff;
      padding:0 10px; font-size:13px; font-family:inherit; color:#17211C; outline:none; }
    .cu-card .cu-input { padding-left:32px; width:220px; }
    .cu-card .cu-input::placeholder { color:#54615A; }
    .cu-card .cu-input:focus, .cu-card .cu-select:focus { border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); }
    .cu-card .cu-select { min-width:120px; padding-right:30px; appearance:none; -webkit-appearance:none; cursor:pointer;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2354615A' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 10px center; }
    .cu-clear { font-size:13px; color:#54615A; text-decoration:underline; text-underline-offset:2px; white-space:nowrap; }
    .cu-clear:hover { color:#A5221B; }

    .cu-seg { display:inline-flex; padding:2px; background:#F5F8F6; border:1px solid #E1E7E3; border-radius:8px; }
    .cu-seg a { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:6px; font-size:13px; font-weight:500; color:#54615A; text-decoration:none; white-space:nowrap; }
    .cu-seg a:hover { color:#17211C; }
    .cu-seg a[aria-current="true"] { background:#fff; color:#17211C; font-weight:600; box-shadow:0 1px 2px rgba(20,40,30,.14); }
    .cu-seg .ct { font-size:12px; font-weight:500; color:#54615A; }

    .cu-scroll { overflow-x:auto; }
    .cu-pager { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:12px 16px; border-top:1px solid #E1E7E3; font-size:13px; color:#54615A; }
    .cu-pages { display:flex; gap:4px; }
    .cu-pages a, .cu-pages span { display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:30px; padding:0 6px; border-radius:6px; text-decoration:none; color:#17211C; font-size:13px; }
    .cu-pages a:hover { background:#F5F8F6; }
    .cu-pages span.cur { background:var(--brand-primary); color:#fff; font-weight:600; }
    .cu-empty { padding:48px 16px 52px; text-align:center; border-top:1px solid #EDEDF2; }
    .cu-empty h2 { margin:0 0 4px; font-size:16px; font-weight:600; color:#17211C; }
    .cu-empty p { margin:0; color:#54615A; font-size:14px; }
    @media (max-width: 860px) {
      .cu-field--grow { flex:1 1 100%; max-width:none; }
      .cu-row-l { width:100%; }
      .cu-card .cu-select { flex:1; }
      .cu-row .sec-btn-danger { margin-left:0; }
      .cu-seg { width:100%; overflow-x:auto; }
    }

    /* Color-coded, higher-contrast strand badges instead of plain text. */
    .strand-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:6px;
      font-size:11px; font-weight:700; letter-spacing:.01em; white-space:nowrap; }
    .strand-badge--stem  { background:#E1ECEA; color:#1F5E57; }
    .strand-badge--humss { background:#E6ECF2; color:#34526E; }
    .strand-badge--abm   { background:#EFEEE2; color:#5E5A2A; }
    .strand-badge--gas   { background:#E4F0E8; color:#2D6547; }
    .strand-badge--tvl   { background:#F1EBE3; color:#6B4E2E; }
    .strand-badge--core  { background:#E9ECEB; color:#44524B; }
    .strand-badge--none  { background:#EDEDF0; color:#4A5560; }

    /* Table — approvals.php's .ap-table: uppercase headers on a faint band,
       quiet row borders, row-hover wash. */
    .data-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:auto; min-width:680px; }
    .data-table th {
      text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
      color:#8A8A9A; padding:10px 14px; background:#FAFAFC; border-top:1px solid #EDEDF2; border-bottom:1px solid #EDEDF2; white-space:nowrap;
    }
    .data-table td { padding:11px 14px; border-bottom:1px solid #F5F5F7; vertical-align:middle; color:#1A1A2E; }
    .data-table th:first-child, .data-table td:first-child { padding-left:16px; }
    .data-table th:last-child,  .data-table td:last-child  { padding-right:16px; }
    .data-table tr:last-child td { border-bottom:none; }
    .td-name { font-weight:500; color:#1A1A2E; }
    .td-meta { font-size:11px; color:#8A8A9A; margin-top:2px; }

    .badge { display:inline-block; font-size:10px; font-weight:600; text-transform:uppercase;
      letter-spacing:.03em; border-radius:20px; padding:2px 9px; margin:1px 4px 1px 0; white-space:nowrap; }
    /* Approval = soft pill; Status = dot + label. One muted family throughout. */
    .badge-approved  { background:#E4F0E8; color:#2D6547; }
    .badge-pending   { background:#F5EEDC; color:#76581A; }
    .badge-rejected  { background:#F5E6E3; color:#94382C; }
    .badge-active, .badge-inactive { background:none; padding-left:0; text-transform:none; letter-spacing:0; font-size:12px; font-weight:600; }
    .badge-active::before, .badge-inactive::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:6px; vertical-align:1px; }
    .badge-active    { color:#2D6547; }
    .badge-active::before   { background:#3E8A62; }
    .badge-inactive  { color:#7A8580; }
    .badge-inactive::before { background:#B7C0BB; }

    .cap-bar { height:6px; background:#E7ECE9; border-radius:3px; margin-top:5px; overflow:hidden; width:100%; max-width:180px; }
    .cap-fill { height:100%; border-radius:3px; }
    .cap-ok   { background:#5E9C7A; }
    .cap-low  { background:#C9A04A; }
    .cap-full { background:#B5574B; }

    /* Section names are short; give Capacity the room instead. */
    .data-table .col-section { width:15%; }
    .data-table .col-cap     { width:26%; min-width:190px; }
    .cap-btn { width:100%; }


    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72; margin:2px 4px 2px 0;
    }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .btn-toggle.btn-approve { background:#1A7A5E; border-color:#1A7A5E; color:#fff; font-weight:600; }
    .btn-toggle.btn-approve:hover { background:#14624B; border-color:#14624B; color:#fff; }

    /* ── Row ⋮ menu (admin/staff_manage.php's .staff-card-menu look). The
       dropdown is position:fixed (placed by JS) so the table's horizontal
       scroll wrapper can't clip it. ── */
    .row-menu { position:relative; display:inline-block; }
    .row-menu-btn { width:30px; height:30px; border:none; border-radius:8px; background:transparent; color:#5A5A72; cursor:pointer;
      display:flex; align-items:center; justify-content:center; transition:background .12s, color .12s; }
    .row-menu-btn svg { width:16px; height:16px; }
    .row-menu-btn:hover, .row-menu.open .row-menu-btn { background:#E9EFEB; color:#1A1A2E; }
    .row-menu-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:1px; }
    .row-menu-dropdown { position:fixed; min-width:180px; padding:6px; background:#fff; border:1px solid #E1E8E4; border-radius:10px;
      box-shadow:0 12px 28px rgba(18,32,26,.16); opacity:0; visibility:hidden; transform:translateY(-4px);
      transition:opacity .12s ease, transform .12s ease, visibility .12s; z-index:60; }
    .row-menu.open .row-menu-dropdown { opacity:1; visibility:visible; transform:translateY(0); }
    .row-menu-dropdown form { margin:0; }
    .row-menu-item { display:flex; align-items:center; gap:10px; width:100%; padding:8px 10px; border:none; background:none;
      font-size:13px; font-weight:500; font-family:inherit; color:#1A1A2E; text-align:left; cursor:pointer; border-radius:7px; }
    .row-menu-item svg { width:15px; height:15px; flex-shrink:0; color:#5F6B74; }
    .row-menu-item:hover, .row-menu-item:focus-visible { background:#F0F5F2; outline:none; }
    .row-menu-item--danger, .row-menu-item--danger svg { color:#B3261E; }
    .row-menu-item--danger:hover { background:#FDF0EF; }
    .row-menu-divider { height:1px; margin:5px 4px; background:#E8EEEA; }

    /* ── Pending-approval card ── */
    .pend-card { display:flex; align-items:center; gap:14px; margin:0 0 1rem; padding:14px 16px; background:#FDF3E1;
      border:1px solid #F1D9A8; border-radius:12px; }
    .pend-count { flex:0 0 auto; width:34px; height:34px; border-radius:50%; background:#F6DFAE; color:#7A5410;
      display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; }
    .pend-text { flex:1 1 auto; min-width:0; }
    .pend-title { margin:0 0 2px; font-size:14px; font-weight:600; color:#17211C; }
    .pend-sub { margin:0; font-size:12.5px; color:#5A5A48; }
    .pend-btn { flex:0 0 auto; display:inline-flex; align-items:center; height:36px; padding:0 16px; border-radius:8px;
      background:var(--brand-primary); color:#fff; font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap; }
    .pend-btn:hover { background:var(--brand-primary-hover, #163A2C); color:#fff; }
    .pend-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:2px; }
    @media (max-width: 560px) {
      .pend-card { flex-wrap:wrap; }
      .pend-btn { width:100%; justify-content:center; }
    }

    .form-group { margin-bottom:.85rem; }
    .form-group label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem; }
    .form-group input, .form-group select { width:100%; height:38px; border:0.5px solid #D4D4E0;
      border-radius:8px; background:#FAFAFC; padding:0 12px; font-size:13px;
      font-family:inherit; color:#1A1A2E; outline:none; }
    .form-group input:focus, .form-group select:focus {
      border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); background:#fff; }
    .form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .btn-add { width:100%; height:38px; background:var(--brand-primary); border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:.2rem; }
    .btn-add:hover { background:var(--brand-primary-hover); }

    .two-col { display:grid; grid-template-columns:1fr 320px; gap:14px; align-items:start; }
    .full-col { display:block; }
    .full-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    /* css_staff.css's .panel is built for centered single-column public pages
       (max-width:760px; margin:auto). Inside this wider two-column dashboard
       grid that centering just eats space on both sides — neutralize it here
       so each panel fills its grid column instead. */
    .two-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    .card-title { font-size:13px; font-weight:600; color:#1A1A2E; }
    .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.9rem; }
    .empty-state { color:#8A8A9A; font-size:13px; padding:1rem 0; }
    tr.clickable { cursor:pointer; }
    tr.clickable:hover td { background:#FAFAFC; }

    /* Modal */
    dialog#sectionModal { border:none; border-radius:12px; padding:1.5rem;
      width:min(560px, 92vw); max-height:80vh; overflow-y:auto;
      box-shadow:0 8px 40px rgba(0,0,0,.22);
      position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); margin:0; }
    dialog#sectionModal::backdrop { background:rgba(0,0,0,.45); }
    .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .modal-title { font-size:15px; font-weight:600; color:#1A1A2E; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer; color:#8A8A9A; line-height:1; padding:0 4px; }
    .modal-close:hover { color:#1A1A2E; }
    .modal-meta { font-size:12px; color:#8A8A9A; margin-bottom:1rem; }
    .student-list { border-collapse:collapse; width:100%; font-size:13px; }
    .student-list th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:5px 8px 5px 0; border-bottom:0.5px solid #EBEBF0; }
    .student-list td { padding:8px 8px 8px 0; border-bottom:0.5px solid #F5F5F7; }
    .student-list tr:last-child td { border-bottom:none; }
    .badge-enrolled { background:#E4F0E8; color:#2D6547; }

    /* Capacity cell = the only way into the student list. user-select:none
       so a double-click doesn't paint a selection highlight over it. */
    .cap-btn { display:flex; flex-direction:column; align-items:flex-start; gap:1px; background:none; border:0;
      padding:4px 6px; margin:-4px -6px; border-radius:6px; font:inherit; color:inherit; text-align:left;
      cursor:pointer; user-select:none; -webkit-user-select:none; }
    .cap-btn:hover { background:var(--brand-tint); }
    .cap-btn:hover .cap-num { color:var(--brand-primary); text-decoration:underline; text-underline-offset:2px; }
    .cap-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:1px; }
    .cap-btn .cap-num { font-size:13px; font-weight:500; }
    .cap-btn .cap-bar { display:block; }

    .room-hint { margin:.35rem 0 0; font-size:12px; color:#5A5A72; min-height:1em; }
    .room-hint.is-error { color:#B3261E; }
    .room-chip { border:1px solid var(--brand-accent); background:var(--brand-tint); color:var(--brand-primary);
      border-radius:20px; padding:1px 9px; font-size:11.5px; font-weight:600; font-family:inherit; cursor:pointer; }
    .room-chip:hover { background:#fff; }

    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

    /* ── School year toolbar ──────────────────────────────────────────── */
    [hidden] { display:none !important; }
    .sec-btn-danger { height:36px; padding:0 12px; border:1px solid #E6C3BD; border-radius:8px; background:#fff; color:#94382C;
      font-size:12.5px; font-weight:600; font-family:inherit; cursor:pointer; white-space:nowrap; }
    .sec-btn-danger:hover { background:#F5E6E3; }
    .sec-past-note { margin:0 0 12px; padding:10px 12px; border-radius:8px; background:#F3F5F4; border:1px solid #E1E7E3; font-size:13px; color:#44524B; }
    .badge-past { background:none; padding-left:0; text-transform:none; letter-spacing:0; font-size:12px; font-weight:600; color:#6B6F75; }
    .badge-past::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:6px; vertical-align:1px;
      background:#fff; box-shadow:inset 0 0 0 1.5px #A3A9AF; }

    /* ── Carry Over modal (same as curriculum.php) ────────────────────── */
    .co-modal { max-width:600px; padding:0; display:flex; flex-direction:column; overflow:hidden; height:min(640px, calc(100vh - 40px)); }
    .co-head { padding:1.3rem 1.5rem 0; }
    .co-head .notice { margin:0 0 .75rem; }
    .co-header { border-bottom:0; padding-bottom:0; margin-bottom:.9rem; }
    .co-header h2 { font-size:1.15rem; }
    .co-form { display:flex; flex-direction:column; flex:1; min-height:0; }
    .co-controls { padding:0 1.5rem .9rem; border-bottom:1px solid #E8EEEA; display:flex; flex-direction:column; gap:.7rem; }
    .co-years { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:13px; color:#3A4A42; }
    .co-years label { margin:0; font-size:13px; font-weight:500; text-transform:none; letter-spacing:0; color:#3A4A42; }
    .co-years select { width:auto; height:34px; padding:0 10px; border:1px solid #D4D9D6; border-radius:8px; background:#fff; font-size:13px; font-family:inherit; color:#1A1A2E; }
    .co-warn { margin:0; padding:8px 10px; border-radius:8px; background:#F5EEDC; border:1px solid #E8D7A9; color:#76581A; font-size:12.5px; line-height:1.4; }
    .co-search-row { display:flex; align-items:center; gap:4px; }
    .co-search { position:relative; flex:1; min-width:0; }
    .co-search svg { position:absolute; left:11px; top:50%; width:15px; height:15px; transform:translateY(-50%); color:#8A8A9A; pointer-events:none; }
    .co-search input { width:100%; height:36px; padding:0 12px 0 33px; border:1px solid #D4D9D6; border-radius:8px; background:#fff; font-size:13px; font-family:inherit; outline:none; }
    .co-search input:focus { border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); }
    .co-link { background:none; border:0; padding:6px; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--brand-primary); cursor:pointer; white-space:nowrap; border-radius:6px; }
    .co-link:hover { background:var(--brand-tint); }
    .co-link--muted { color:#5A5A72; }
    .co-chips { display:flex; flex-wrap:wrap; gap:6px; }
    .co-chip { height:28px; padding:0 12px; border:1px solid #D4D9D6; border-radius:20px; background:#fff; font-family:inherit; font-size:12px; font-weight:500; color:#3A4A42; cursor:pointer; }
    .co-chip:hover { border-color:var(--brand-accent); }
    .co-chip.is-on { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }
    .co-list { flex:1; min-height:0; overflow-y:auto; padding:.35rem 1.5rem .75rem; }
    .co-empty { color:#8A8A9A; font-size:13px; text-align:center; padding:2.5rem 0; margin:0; }
    .co-check { display:flex; align-items:center; gap:12px; min-width:0; flex:1; margin:0; cursor:pointer;
      font-size:13px; font-weight:400; text-transform:none; letter-spacing:0; color:#1A1A2E; }
    .co-check input { width:16px; height:16px; margin:0; flex-shrink:0; accent-color:var(--brand-primary); cursor:pointer; }
    .co-check input:disabled { cursor:default; }
    .co-group-head { display:flex; align-items:center; gap:8px; padding:12px 0 10px; border-bottom:1px solid #E8EEEA; }
    .co-group-head .co-check { flex:0 1 auto; }
    .co-group-title { font-weight:600; font-size:13.5px; }
    .co-tag { display:inline-block; padding:1px 7px; border-radius:5px; background:#E6ECE8; color:#3A4A42; font-size:10.5px; font-weight:700; letter-spacing:.02em; }
    .co-tag--row { margin-left:auto; flex-shrink:0; }
    .co-toggle { margin-left:auto; display:inline-flex; align-items:center; gap:4px; background:none; border:0; padding:4px 6px; border-radius:6px;
      font-family:inherit; font-size:11.5px; color:#8A6A2A; cursor:pointer; white-space:nowrap; }
    .co-toggle:hover { background:#F5F5F7; }
    .co-toggle svg { width:13px; height:13px; transition:transform .15s; }
    .co-group.is-collapsed .co-toggle svg { transform:rotate(-90deg); }
    .co-group.is-collapsed .co-rows { display:none; }
    .co-rows { list-style:none; margin:0 0 .4rem; padding:0; }
    .co-row { display:flex; align-items:center; gap:10px; padding:9px 0 9px 22px; border-bottom:1px solid #F0F2F1; }
    .co-row:last-child { border-bottom:0; }
    .co-name-wrap { display:flex; flex-direction:column; min-width:0; }
    .co-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:500; }
    .co-meta { font-size:11.5px; color:#8A8A9A; }
    .co-meta em { font-style:normal; color:#94382C; }
    .co-row.is-already .co-name { color:#9A9AAA; }
    .co-row.is-already .co-check { cursor:default; }
    .co-already { flex-shrink:0; font-size:11px; color:#9A9AAA; }
    .co-row .co-tag--row + .co-already { margin-left:8px; }
    .co-foot { display:flex; align-items:center; gap:8px; padding:.9rem 1.5rem; border-top:1px solid #E8EEEA; background:#fff; }
    .co-count { margin-right:auto; font-size:13px; color:#5A5A72; }
    @media (max-width: 520px) {
      .co-head, .co-controls, .co-list, .co-foot { padding-left:1rem; padding-right:1rem; }
      .co-search-row { flex-wrap:wrap; }
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

    <div class="staff-content">

      <div class="sch-page-head">
        <div>
          <p class="page-eyebrow"><?= $role === 'admin' ? 'Admin Portal' : ($is_reviewer ? 'Coordinator Portal' : 'Scheduler Portal') ?></p>
          <h1 class="page-title">Sections</h1>
          <p class="page-sub"><?= $is_reviewer ? 'Review submitted sections and manage capacity.' : 'Create sections, track approval, and manage capacity. Select a capacity to see who\'s in a section.' ?></p>
        </div>
        <div class="sch-page-actions">
          <?php if (count($valid_school_years) > 1): ?>
            <button type="button" class="sch-btn-add sch-btn-add--ghost" id="btnOpenCarry">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h13M13 7l5 5-5 5"/></svg>
              Carry over sections
            </button>
          <?php endif; ?>
          <?php if (!$is_reviewer): ?>
            <button type="button" class="sch-btn-add" id="btnOpenAddSection">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              New section
            </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($success): ?><div class="notice notice-success" data-ui-toast><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error" data-ui-toast><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($is_reviewer && $pending_count > 0 && $filter !== 'pending'): ?>
        <div class="pend-card" role="status">
          <span class="pend-count"><?= $pending_count ?></span>
          <div class="pend-text">
            <p class="pend-title">Section<?= $pending_count === 1 ? '' : 's' ?> awaiting your approval</p>
            <p class="pend-sub">Submitted sections need your review before they can open for enrollment.</p>
          </div>
          <a class="pend-btn" href="?filter=pending">Review now</a>
        </div>
      <?php endif; ?>

      <?php
        // Links keep the viewed year + search/filters; the current year is the
        // default, so it's left out. Page resets unless one is given.
        $sec_url = fn(array $over) => '?' . http_build_query(array_filter(
            array_merge([
                'q'           => $f_q,
                'school_year' => $f_year !== $current_year ? $f_year : '',
                'grade_level' => $f_grade,
                'strand'      => $f_strand,
                'filter'      => $filter,
            ], $over),
            fn($v) => $v !== '' && $v !== null && $v !== 1 && $v !== 'all'));
        $has_filters = $f_q !== '' || $f_grade !== '' || $f_strand !== '';
      ?>

      <?php if ($year_is_past): ?>
        <div class="sec-past-note">
          SY <?= htmlspecialchars($f_year) ?> has ended. Its sections are kept as records and can't take new enrollments.
          Use <b>Carry over sections</b> to copy them into SY <?= htmlspecialchars($current_year) ?>.
        </div>
      <?php endif; ?>

      <!-- Sections — card/toolbar layout shared with curriculum.php: search +
           school year on top, grade / strand filters and the status switch below. -->
      <section class="cu-card" aria-label="Sections">
        <form method="GET" class="cu-bar">
          <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
          <div class="cu-row">
            <div class="cu-field cu-field--grow">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
              <input class="cu-input" type="search" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Search section or room" aria-label="Search sections">
            </div>
            <select class="cu-select" name="school_year" aria-label="School year" onchange="this.form.submit()">
              <?php foreach ($valid_school_years as $sy): ?>
                <option value="<?= htmlspecialchars($sy) ?>" <?= $f_year === $sy ? 'selected' : '' ?>>SY <?= htmlspecialchars($sy) ?><?= $sy === $current_year ? ' (current)' : (is_past_year($sy, $current_year) ? ' (ended)' : '') ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($can_deactivate_year): ?>
              <button type="submit" form="deactivateYearForm" class="sec-btn-danger">Deactivate all in SY <?= htmlspecialchars($f_year) ?></button>
            <?php endif; ?>
          </div>

          <div class="cu-row cu-row--filters">
            <div class="cu-row-l">
              <select class="cu-select" name="grade_level" aria-label="Grade level" onchange="this.form.submit()">
                <option value="">All grades</option>
                <?php foreach (['11', '12'] as $g): ?>
                  <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                <?php endforeach; ?>
              </select>
              <select class="cu-select" name="strand" aria-label="Strand" onchange="this.form.submit()">
                <option value="">All strands</option>
                <?php foreach ($year_strands as $st): ?>
                  <option value="<?= htmlspecialchars($st) ?>" <?= $f_strand === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($has_filters): ?>
                <a class="cu-clear" href="<?= htmlspecialchars($sec_url(['q' => '', 'grade_level' => '', 'strand' => ''])) ?>">Clear</a>
              <?php endif; ?>
            </div>
            <div class="cu-seg" role="group" aria-label="Filter by status">
              <?php foreach ($filter_labels as $key => $label): ?>
                <a href="<?= htmlspecialchars($sec_url(['filter' => $key])) ?>" <?= $filter === $key ? 'aria-current="true"' : '' ?>>
                  <?= $label ?><span class="ct"><?= $tab_counts[$key] ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </form>

        <?php if ($can_deactivate_year): ?>
          <!-- Separate form (a form can't nest in the GET toolbar); its button sits in the toolbar via form="". -->
          <form method="POST" id="deactivateYearForm" action="<?= htmlspecialchars($sec_url([])) ?>" hidden
                data-confirm="Deactivate all <?= $year_active ?> active section<?= $year_active === 1 ? '' : 's' ?> in SY <?= htmlspecialchars($f_year) ?>?<?= $year_enrolled > 0 ? ' ' . $year_enrolled . ' student' . ($year_enrolled === 1 ? ' is' : 's are') . ' still placed in them — they keep their placement, but these sections stop accepting new enrollments.' : ' None of them have students placed.' ?> You can reactivate sections one at a time afterwards."
                data-icon="warning">
            <input type="hidden" name="school_year" value="<?= htmlspecialchars($f_year) ?>">
            <input type="hidden" name="deactivate_year" value="1">
          </form>
        <?php endif; ?>

          <?php if (empty($rows)): ?>
            <div class="cu-empty">
              <h2>No sections found</h2>
              <p>
                <?php if (empty($year_rows)): ?>
                  SY <?= htmlspecialchars($f_year) ?> has no sections yet.<?= count($valid_school_years) > 1 ? ' Use <b>Carry over sections</b> to copy them from another year.' : '' ?>
                <?php else: ?>
                  Nothing matches these filters.
                <?php endif; ?>
              </p>
            </div>
          <?php else: ?>
            <div class="cu-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="col-section">Section</th>
                  <th>Grade / Strand</th>
                  <th>Room</th>
                  <th class="col-cap">Capacity</th>
                  <th>Approval</th>
                  <th>Status</th>
                  <?php if ($is_reviewer): ?><th>Action</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r):
                  $enrolled  = (int)$r['enrolled'];
                  $capacity  = (int)$r['capacity'];
                  $remaining = $capacity - $enrolled;
                  $pct       = $capacity > 0 ? round($enrolled / $capacity * 100) : 0;
                  if ($remaining <= 0)      { $fillClass = 'cap-full'; }
                  elseif ($remaining <= 5)  { $fillClass = 'cap-low'; }
                  else                      { $fillClass = 'cap-ok'; }
                  $slotText = $remaining <= 0 ? 'Full' : "$remaining slots left";

                  $approval = $r['approval_status'];
                ?>
                  <tr>
                    <td>
                      <div class="td-name"><?= htmlspecialchars($r['section_name']) ?></div>
                      <?php if ($approval === 'pending' && $r['req_family']): ?>
                        <div class="td-meta">Requested by <?= htmlspecialchars($r['req_given'] . ' ' . $r['req_family']) ?></div>
                      <?php elseif ($approval === 'rejected'): ?>
                        <div class="td-meta">
                          Rejected<?= $r['rev_family'] ? ' by ' . htmlspecialchars($r['rev_given'] . ' ' . $r['rev_family']) : '' ?><?= $r['rejection_reason'] ? ': ' . htmlspecialchars($r['rejection_reason']) : '' ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>G<?= htmlspecialchars($r['grade_level']) ?> &middot; <span class="strand-badge strand-badge--<?= strand_badge_class($r['strand']) ?>"><?= htmlspecialchars($r['strand']) ?></span></td>
                    <td><?= ($r['room'] ?? '') !== '' ? htmlspecialchars($r['room']) : '<span class="td-meta">—</span>' ?></td>
                    <td>
                      <!-- The student list opens only from here, not from the whole row. -->
                      <button type="button" class="cap-btn js-students"
                              data-id="<?= (int)$r['section_id'] ?>"
                              data-name="<?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>"
                              data-enrolled="<?= $enrolled ?>" data-capacity="<?= $capacity ?>"
                              aria-label="View students in <?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>">
                        <span class="cap-num"><?= $enrolled ?>/<?= $capacity ?></span>
                        <span class="td-meta"><?= $slotText ?></span>
                        <span class="cap-bar"><span class="cap-fill <?= $fillClass ?>" style="width:<?= $pct ?>%"></span></span>
                      </button>
                    </td>
                    <td>
                      <span class="badge badge-<?= $approval ?>"><?= ucfirst($approval) ?></span>
                    </td>
                    <td>
                      <?php if ($year_is_past && $r['is_active'] && $approval === 'approved'): ?>
                        <span class="badge badge-past" title="SY <?= htmlspecialchars($r['school_year']) ?> has ended">Past year</span>
                      <?php else: ?>
                        <span class="badge <?= $r['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                          <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <?php if ($is_reviewer): ?>
                    <td>
                      <?php if ($approval === 'pending'): ?>
                        <form method="POST" style="display:inline;"
                              data-confirm="Approve &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;? Subjects, teachers, and a schedule will be generated automatically." data-icon="question">
                          <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                          <button type="submit" name="approve_section" class="btn-toggle btn-approve">
                            Approve
                          </button>
                        </form>
                        <form method="POST" style="display:inline;" class="reject-form">
                          <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                          <input type="hidden" name="rejection_reason" value="">
                          <button type="button" class="btn-toggle js-reject" style="color:#C0392B;border-color:#F5C6C2;"
                                  data-name="<?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>">
                            Reject
                          </button>
                        </form>
                      <?php elseif ($approval === 'approved'):
                        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES); ?>
                        <!-- ⋮ menu (admin/staff_manage.php's row menu): Edit, then Deactivate/Reactivate.
                             The section's details ride along as data-* for the Edit modal. -->
                        <div class="row-menu"
                             data-id="<?= (int)$r['section_id'] ?>" data-name="<?= $h($r['section_name']) ?>"
                             data-room="<?= $h($r['room'] ?? '') ?>" data-capacity="<?= $capacity ?>" data-enrolled="<?= $enrolled ?>"
                             data-meta="Grade <?= $h($r['grade_level']) ?> · <?= $h($r['strand']) ?> · SY <?= $h($r['school_year']) ?>">
                          <button type="button" class="row-menu-btn" aria-label="More actions for <?= $h($r['section_name']) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle>
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                          </button>
                          <div class="row-menu-dropdown" role="menu">
                            <button type="button" class="row-menu-item" role="menuitem" data-action="edit">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Edit section
                            </button>
                            <div class="row-menu-divider"></div>
                            <?php if ($r['is_active']): ?>
                              <form method="POST"
                                    data-confirm="Deactivate section &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;? Students already enrolled will be unaffected, but the section will no longer accept new enrollments." data-icon="warning">
                                <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                                <button type="submit" name="deactivate_section" class="row-menu-item row-menu-item--danger" role="menuitem">
                                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/></svg>Deactivate
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="POST"
                                    data-confirm="Reactivate section &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;? It will accept new enrollments again." data-icon="question">
                                <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                                <button type="submit" name="reactivate_section" class="row-menu-item" role="menuitem">
                                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>Reactivate
                                </button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>

            <div class="cu-pager">
              <span>Showing <?= ($page - 1) * $per_page + 1 ?>&ndash;<?= min($page * $per_page, $total_count) ?> of <?= $total_count ?></span>
              <?php if ($total_pages > 1): ?>
                <nav class="cu-pages" aria-label="Pages">
                  <?php if ($page > 1): ?><a href="<?= htmlspecialchars($sec_url(['page' => $page - 1])) ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
                  <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <?php if ($p === $page): ?><span class="cur" aria-current="page"><?= $p ?></span>
                    <?php else: ?><a href="<?= htmlspecialchars($sec_url(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?><a href="<?= htmlspecialchars($sec_url(['page' => $page + 1])) ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
          <?php endif; ?>
      </section>

    </div>
  </div>

  <?php if (!$is_reviewer): ?>
  <!-- Add Section modal — opened from the "New section" button; reopens
       itself with the error inside when a submission fails. -->
  <div class="sch-overlay<?= $add_error ? '' : ' hidden' ?>" id="addSectionOverlay" role="dialog" aria-modal="true" aria-labelledby="addSectionTitle">
    <div class="sch-modal">
      <div class="sch-modal-header">
        <div>
          <h2 id="addSectionTitle">New section</h2>
          <p class="sch-modal-sub">Submitted sections need coordinator approval before subjects and a schedule are generated.</p>
        </div>
        <button type="button" class="sch-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($add_error): ?>
        <div class="notice notice-error"><?= htmlspecialchars($add_error) ?></div>
      <?php endif; ?>

      <form method="POST" data-confirm="Submit this section for coordinator approval?" data-icon="question">
        <div class="sch-form-grid">
          <div class="form-group form-group--wide">
            <label for="f_section_name">Section name <span class="req">*</span></label>
            <input id="f_section_name" type="text" name="section_name" placeholder="e.g. STEM 11-3" maxlength="50"
                   value="<?= htmlspecialchars($_POST['section_name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label for="f_grade_level">Grade level <span class="req">*</span></label>
            <select id="f_grade_level" name="grade_level" required>
              <option value="">Select</option>
              <option value="11" <?= ($_POST['grade_level'] ?? '') === '11' ? 'selected' : '' ?>>Grade 11</option>
              <option value="12" <?= ($_POST['grade_level'] ?? '') === '12' ? 'selected' : '' ?>>Grade 12</option>
            </select>
          </div>
          <div class="form-group">
            <label for="f_strand">Strand <span class="req">*</span></label>
            <select id="f_strand" name="strand" required>
              <option value="">Select</option>
              <?php foreach ($valid_strands as $s): ?>
                <option value="<?= $s ?>" <?= ($_POST['strand'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="f_school_year">School year <span class="req">*</span></label>
            <select id="f_school_year" name="school_year" required>
              <?php foreach ($valid_school_years as $sy): ?>
                <option value="<?= $sy ?>" <?= ($_POST['school_year'] ?? '') === $sy ? 'selected' : '' ?>><?= $sy ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="f_capacity">Capacity <span class="req">*</span></label>
            <input id="f_capacity" type="number" name="capacity" min="1" max="999" placeholder="e.g. 40"
                   value="<?= htmlspecialchars($_POST['capacity'] ?? '') ?>" required>
          </div>
          <div class="form-group form-group--wide">
            <label for="f_room">Room <span class="opt">(optional)</span></label>
            <input id="f_room" type="text" name="room" maxlength="50" autocomplete="off"
                   value="<?= htmlspecialchars($_POST['room'] ?? '') ?>">
            <p class="room-hint" id="roomHint" aria-live="polite"></p>
          </div>
        </div>
        <div class="sch-modal-actions">
          <button type="button" class="sch-btn sch-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="add_section" class="sch-btn sch-btn--primary">Submit for approval</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_reviewer): ?>
  <!-- Edit Section modal — filled from the row ⋮ menu's data-*; reopens with
       the error and what was typed when a save fails. -->
  <div class="sch-overlay<?= $edit_error ? '' : ' hidden' ?>" id="editSectionOverlay" role="dialog" aria-modal="true" aria-labelledby="editSectionTitle">
    <div class="sch-modal">
      <div class="sch-modal-header">
        <div>
          <h2 id="editSectionTitle">Edit section</h2>
          <p class="sch-modal-sub" id="es_meta"><?= htmlspecialchars($_POST['meta'] ?? '') ?></p>
        </div>
        <button type="button" class="sch-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($edit_error): ?>
        <div class="notice notice-error"><?= htmlspecialchars($edit_error) ?></div>
      <?php endif; ?>

      <form method="POST" data-confirm="Save changes to this section?" data-icon="question">
        <input type="hidden" name="section_id" id="es_id" value="<?= (int)($_POST['section_id'] ?? 0) ?>">
        <input type="hidden" name="meta" id="es_meta_in" value="<?= htmlspecialchars($_POST['meta'] ?? '') ?>">
        <div class="sch-form-grid">
          <div class="form-group form-group--wide">
            <label for="es_name">Section name <span class="req">*</span></label>
            <input id="es_name" type="text" name="section_name" maxlength="50" required
                   value="<?= htmlspecialchars($_POST['section_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="es_capacity">Capacity <span class="req">*</span></label>
            <input id="es_capacity" type="number" name="capacity" min="1" max="999" required
                   value="<?= htmlspecialchars($_POST['capacity'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="es_room">Room <span class="opt">(optional)</span></label>
            <input id="es_room" type="text" name="room" maxlength="50" autocomplete="off"
                   value="<?= htmlspecialchars($_POST['room'] ?? '') ?>">
          </div>
        </div>
        <div class="sch-modal-actions">
          <button type="button" class="sch-btn sch-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="edit_section" class="sch-btn sch-btn--primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (count($valid_school_years) > 1): ?>
  <!-- Carry Over modal (curriculum.php's pattern): the section list is
       rendered by the script below from #carryData, so changing years,
       search or strand never reloads. Reopens with the error + picks after a
       failed submit. -->
  <div class="sch-overlay<?= $carry_open ? '' : ' hidden' ?>" id="carryOverlay" role="dialog" aria-modal="true" aria-labelledby="carryTitle">
    <div class="sch-modal co-modal">
      <div class="co-head">
        <div class="sch-modal-header co-header">
          <div>
            <h2 id="carryTitle">Carry over sections</h2>
            <p class="sch-modal-sub">Copies each section's name, grade, strand, room and capacity. Subjects come from the new year's curriculum. <?= $is_reviewer ? 'Copies are approved straight away.' : 'Each copy still needs coordinator approval.' ?></p>
          </div>
          <button type="button" class="sch-modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <?php if ($carry_error): ?>
          <div class="notice notice-error"><?= htmlspecialchars($carry_error) ?></div>
        <?php endif; ?>
      </div>

      <form method="POST" id="carryForm" class="co-form" data-confirm="Carry the selected sections over?" data-icon="question">
        <input type="hidden" name="carry_sections" value="1">
        <div class="co-controls">
          <div class="co-years">
            <label for="carrySource">Copy from</label>
            <select id="carrySource" name="source_year">
              <?php foreach ($valid_school_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $carry_source === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="carryTarget">into</label>
            <select id="carryTarget" name="target_year">
              <?php foreach ($valid_school_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $carry_target === $y ? 'selected' : '' ?> <?= is_past_year($y, $current_year) ? 'disabled' : '' ?>>SY <?= htmlspecialchars($y) ?><?= is_past_year($y, $current_year) ? ' (ended)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="co-warn" id="carryWarn" hidden></p>

          <div class="co-search-row">
            <div class="co-search">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
              <input type="search" id="carrySearch" placeholder="Search sections" aria-label="Search sections" autocomplete="off">
            </div>
            <button type="button" class="co-link" id="carrySelectShown">Select all shown</button>
            <button type="button" class="co-link co-link--muted" id="carryClear">Clear</button>
          </div>

          <div class="co-chips" role="group" aria-label="Filter by strand">
            <button type="button" class="co-chip is-on" data-strand="" aria-pressed="true">All strands</button>
            <?php foreach ($carry_strands as $cs): ?>
              <button type="button" class="co-chip" data-strand="<?= htmlspecialchars($cs) ?>" aria-pressed="false"><?= htmlspecialchars($cs) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="co-list" id="carryList" aria-live="polite"></div>
        <div id="carryPicked" hidden></div>

        <div class="co-foot">
          <span class="co-count" id="carryCount">0 sections selected</span>
          <button type="button" class="sch-btn sch-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" class="sch-btn sch-btn--primary" id="carrySubmit" disabled>Carry over</button>
        </div>
      </form>
    </div>
  </div>
  <script type="application/json" id="carryData"><?= json_encode($carry, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>

  <!-- Drill-down Modal (native <dialog>) -->
  <dialog id="sectionModal" aria-labelledby="modalTitle">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Section Students</span>
      <button class="modal-close" aria-label="Close"
              onclick="document.getElementById('sectionModal').close()">&times;</button>
    </div>
    <div class="modal-meta" id="modalMeta"></div>
    <div id="modalBody">
      <p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>
    </div>
  </dialog>

  <script>
    // ── Reject-with-reason: SWAL textarea, then submit ──
    document.querySelectorAll('.js-reject').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('.reject-form');
        const name = btn.dataset.name;
        Swal.fire({
          title: 'Reject "' + name + '"?',
          input: 'textarea',
          inputPlaceholder: 'Reason (optional)',
          inputAttributes: { style: 'resize:vertical;max-width:100%;box-sizing:border-box;' },
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#C0392B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Reject',
          cancelButtonText: 'Cancel'
        }).then((result) => {
          if (!result.isConfirmed) return;
          form.querySelector('input[name="rejection_reason"]').value = (result.value || '').trim();
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'reject_section';
          hidden.value = '1';
          form.appendChild(hidden);
          form.submit();
        });
      });
    });

    // ── Add Section modal (staff_manage.php pattern: hidden until the button) ──
    const addOverlay = document.getElementById('addSectionOverlay');
    const addBtn     = document.getElementById('btnOpenAddSection');
    if (addOverlay && addBtn) {
      const closeAdd = () => addOverlay.classList.add('hidden');
      addBtn.addEventListener('click', () => {
        addOverlay.classList.remove('hidden');
        document.getElementById('f_section_name').focus();
      });
      addOverlay.addEventListener('click', e => {
        if (e.target === addOverlay || e.target.closest('[data-close-modal]')) closeAdd();
      });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAdd(); });

      // Room: live "already in use" check + next-free-room suggestion, per
      // school year. The server re-checks on submit, so this is a convenience.
      const ROOMS   = <?= json_encode($rooms_by_year, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      const SUGGEST = <?= json_encode($room_suggestion, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      const roomEl = document.getElementById('f_room');
      const syEl   = document.getElementById('f_school_year');
      const hintEl = document.getElementById('roomHint');
      const roomKey = v => v.replace(/\s+/g, '').toUpperCase();

      function checkRoom() {
        const sy   = syEl.value;
        const sugg = SUGGEST[sy] || '';
        const used = ROOMS[sy] || {};
        const val  = roomEl.value.trim();
        roomEl.placeholder = sugg ? 'e.g. ' + sugg : '';
        const takenBy = val !== '' ? used[roomKey(val)] : null;
        roomEl.classList.toggle('is-invalid', !!takenBy);
        roomEl.setCustomValidity(takenBy ? 'This room is already in use.' : '');
        hintEl.classList.toggle('is-error', !!takenBy);
        hintEl.innerHTML = '';
        if (takenBy) hintEl.append('Already used by ' + takenBy + ' in SY ' + sy + '. ');
        if (sugg && (takenBy || val === '')) {
          const chip = document.createElement('button');
          chip.type = 'button';
          chip.className = 'room-chip';
          chip.textContent = 'Use ' + sugg;
          chip.addEventListener('click', () => { roomEl.value = sugg; checkRoom(); roomEl.focus(); });
          hintEl.append('Next free room: ', chip);
        }
      }
      roomEl.addEventListener('input', checkRoom);
      syEl.addEventListener('change', checkRoom);
      checkRoom();
    }

    // ── Row ⋮ menu + Edit Section modal ──────────────────────────────────────
    (function () {
      const editOv = document.getElementById('editSectionOverlay');
      if (!editOv) return;
      const closeMenus = () => document.querySelectorAll('.row-menu.open').forEach(m => {
        m.classList.remove('open');
        m.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', 'false');
      });
      // The dropdown is position:fixed — place it under the button, or above
      // it when it wouldn't fit below.
      const place = (menu, btn) => {
        const dd = menu.querySelector('.row-menu-dropdown');
        const r  = btn.getBoundingClientRect();
        const below = window.innerHeight - r.bottom >= dd.offsetHeight + 12;
        dd.style.top  = (below ? r.bottom + 4 : r.top - dd.offsetHeight - 4) + 'px';
        dd.style.left = Math.max(8, r.right - dd.offsetWidth) + 'px';
      };
      const openEdit = d => {
        document.getElementById('es_id').value       = d.id;
        document.getElementById('es_name').value     = d.name;
        document.getElementById('es_room').value     = d.room;
        document.getElementById('es_meta').textContent = d.meta;
        document.getElementById('es_meta_in').value  = d.meta;
        const cap = document.getElementById('es_capacity');
        cap.value = d.capacity;
        cap.min   = Math.max(1, +d.enrolled);   // can't drop below who's already placed
        editOv.querySelector('.notice-error')?.remove();
        editOv.classList.remove('hidden');
        document.getElementById('es_name').focus();
      };

      document.addEventListener('click', e => {
        const toggle = e.target.closest('[data-menu-toggle]');
        if (toggle) {
          const menu = toggle.closest('.row-menu');
          const wasOpen = menu.classList.contains('open');
          closeMenus();
          if (!wasOpen) {
            place(menu, toggle);
            menu.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
          }
          return;
        }
        const act = e.target.closest('.row-menu [data-action="edit"]');
        if (act) { const d = act.closest('.row-menu').dataset; closeMenus(); openEdit(d); return; }
        if (!e.target.closest('.row-menu')) closeMenus();
      });
      // A fixed dropdown would drift from its button on scroll — just close it.
      window.addEventListener('scroll', closeMenus, true);
      window.addEventListener('resize', closeMenus);

      editOv.addEventListener('click', e => {
        if (e.target === editOv || e.target.closest('[data-close-modal]')) editOv.classList.add('hidden');
      });
      document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        closeMenus();
        editOv.classList.add('hidden');
      });
    })();

    // ── Carry Over modal ─────────────────────────────────────────────────────
    // Grouped (by grade), filterable list of the source year's approved
    // sections. Picks live in a Set so searching/filtering never drops one;
    // they're posted through hidden inputs in #carryPicked.
    (function () {
      const dataEl = document.getElementById('carryData');
      const ov = document.getElementById('carryOverlay');
      if (!dataEl || !ov) return;
      const D = JSON.parse(dataEl.textContent);
      const $id = id => document.getElementById(id);
      const srcEl = $id('carrySource'), tgtEl = $id('carryTarget'), searchEl = $id('carrySearch');
      const listEl = $id('carryList'), pickedEl = $id('carryPicked'), countEl = $id('carryCount');
      const submitEl = $id('carrySubmit'), formEl = $id('carryForm'), warnEl = $id('carryWarn');
      const chips = [...ov.querySelectorAll('.co-chip')];
      const picked = new Set((D.picked || []).map(Number));
      const collapsed = new Set();
      let strand = '';

      const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');
      const key = r => r[1].trim().toLowerCase() + '|' + r[2] + '|' + r[3];
      const roomKey = v => String(v).replace(/\s+/g, '').toUpperCase();
      const subjCount = (y, g, s) => ((D.curr[y] || {})[g + '|' + s] || 0) + ((D.curr[y] || {})[g + '|CORE'] || 0);

      function view() {
        const tgt = tgtEl.value, taken = new Set(D.taken[tgt] || []), rooms = D.rooms[tgt] || {};
        const q = searchEl.value.trim().toLowerCase();
        return (D.years[srcEl.value] || [])
          .filter(r => !strand || r[3] === strand)
          .filter(r => !q || r[1].toLowerCase().includes(q) || r[4].toLowerCase().includes(q))
          .map(r => ({ id: r[0], name: r[1], grade: r[2], strand: r[3], room: r[4], cap: r[5],
                       already: taken.has(key(r)), roomTaken: r[4] !== '' && !!rooms[roomKey(r[4])] }));
      }

      function render() {
        const src = srcEl.value, tgt = tgtEl.value, same = src === tgt;
        const rows = same ? [] : view();
        let html = '';
        if (same) html = '<p class="co-empty">Pick two different school years to copy between.</p>';
        else if (!rows.length) html = '<p class="co-empty">' + ((D.years[src] || []).length ? 'No sections match your search.' : 'SY ' + esc(src) + ' has no approved sections to copy.') + '</p>';
        else {
          [...new Set(rows.map(r => r.grade))].forEach(g => {
            const gRows = rows.filter(r => r.grade === g), elig = gRows.filter(r => !r.already);
            const nSel = elig.filter(r => picked.has(r.id)).length, isCol = collapsed.has(g);
            html += '<div class="co-group' + (isCol ? ' is-collapsed' : '') + '" data-grade="' + esc(g) + '">'
              + '<div class="co-group-head"><label class="co-check"><input type="checkbox" class="co-group-box"' + (elig.length ? '' : ' disabled')
              + (elig.length && nSel === elig.length ? ' checked' : '') + ' data-partial="' + (nSel > 0 && nSel < elig.length ? 1 : 0) + '">'
              + '<span class="co-group-title">Grade ' + esc(g) + '</span></label>'
              + '<button type="button" class="co-toggle" aria-expanded="' + !isCol + '">' + nSel + ' of ' + elig.length + ' selected'
              + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>'
              + '</div><ul class="co-rows">';
            gRows.forEach(r => {
              const meta = [r.room ? 'Room ' + esc(r.room) + (r.roomTaken && !r.already ? ' <em>(taken in SY ' + esc(tgt) + ' — copied without a room)</em>' : '') : 'No room', 'Capacity ' + r.cap].join(' · ');
              html += '<li class="co-row' + (r.already ? ' is-already' : '') + '">'
                + '<label class="co-check"><input type="checkbox" class="co-box" value="' + r.id + '"' + (r.already ? ' disabled' : (picked.has(r.id) ? ' checked' : '')) + '>'
                + '<span class="co-name-wrap"><span class="co-name">' + esc(r.name) + '</span><span class="co-meta">' + meta + '</span></span></label>'
                + '<span class="co-tag co-tag--row">' + esc(r.strand) + '</span>'
                + (r.already ? '<span class="co-already">Already in SY ' + esc(tgt) + '</span>' : '')
                + '</li>';
            });
            html += '</ul></div>';
          });
        }
        listEl.innerHTML = html;
        listEl.querySelectorAll('.co-group-box[data-partial="1"]').forEach(b => { b.indeterminate = true; });

        // Warn when the target curriculum can't give the picked sections any subjects.
        const empty = same ? [] : [...new Set((D.years[src] || []).filter(r => picked.has(r[0]) && !subjCount(tgt, r[2], r[3])).map(r => 'G' + r[2] + ' ' + r[3]))];
        warnEl.hidden = !empty.length;
        warnEl.textContent = empty.length ? 'SY ' + tgt + '’s curriculum has no approved subjects for ' + empty.join(', ')
          + '. Those sections would start with no subjects — carry the curriculum over first.' : '';
        syncFoot();
      }

      function syncFoot() {
        const n = picked.size;
        countEl.textContent = plural(n, 'section') + ' selected';
        submitEl.disabled = n === 0 || srcEl.value === tgtEl.value;
        submitEl.textContent = n ? 'Carry over ' + plural(n, 'section') : 'Carry over';
        formEl.dataset.confirm = 'Carry ' + plural(n, 'section') + ' from SY ' + srcEl.value + ' into SY ' + tgtEl.value + '?';
        pickedEl.innerHTML = [...picked].map(id => '<input type="hidden" name="section_id[]" value="' + id + '">').join('');
      }

      listEl.addEventListener('change', e => {
        const box = e.target;
        if (box.classList.contains('co-box')) box.checked ? picked.add(+box.value) : picked.delete(+box.value);
        else if (box.classList.contains('co-group-box')) {
          const g = box.closest('.co-group').dataset.grade;
          view().filter(r => r.grade === g && !r.already).forEach(r => box.checked ? picked.add(r.id) : picked.delete(r.id));
        } else return;
        render();
      });
      listEl.addEventListener('click', e => {
        const t = e.target.closest('.co-toggle');
        if (!t) return;
        const g = t.closest('.co-group').dataset.grade;
        collapsed.has(g) ? collapsed.delete(g) : collapsed.add(g);
        render();
      });
      chips.forEach(c => c.addEventListener('click', () => {
        strand = c.dataset.strand;
        chips.forEach(x => { const on = x === c; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on); });
        render();
      }));
      searchEl.addEventListener('input', render);
      srcEl.addEventListener('change', () => { picked.clear(); render(); });
      tgtEl.addEventListener('change', () => {
        const taken = new Set(D.taken[tgtEl.value] || []);
        (D.years[srcEl.value] || []).forEach(r => { if (taken.has(key(r))) picked.delete(r[0]); });
        render();
      });
      $id('carrySelectShown').addEventListener('click', () => { view().forEach(r => { if (!r.already) picked.add(r.id); }); render(); });
      $id('carryClear').addEventListener('click', () => { picked.clear(); render(); });

      const openBtn = $id('btnOpenCarry');
      openBtn && openBtn.addEventListener('click', () => { ov.classList.remove('hidden'); srcEl.focus(); });
      ov.addEventListener('click', e => { if (e.target === ov || e.target.closest('[data-close-modal]')) ov.classList.add('hidden'); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') ov.classList.add('hidden'); });

      // Drop picks that are already in the target year (e.g. after a failed submit).
      const taken0 = new Set(D.taken[tgtEl.value] || []);
      (D.years[srcEl.value] || []).forEach(r => { if (taken0.has(key(r))) picked.delete(r[0]); });
      render();
    })();

    // ── Section student drill-down modal — opened only from the Capacity cell ──
    const dlg = document.getElementById('sectionModal');
    let dlgOpenedAt = 0, dlgRequest = 0;

    dlg.addEventListener('click', function(e) {
      // The second click of a double-click lands on the backdrop right after
      // the modal opens — that used to close it again instantly ("pressed
      // twice clears it"). Ignore backdrop clicks in the first moment.
      if (Date.now() - dlgOpenedAt < 400) return;
      const rect = dlg.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right ||
          e.clientY < rect.top  || e.clientY > rect.bottom) {
        dlg.close();
      }
    });

    document.querySelectorAll('.js-students').forEach(btn => {
      btn.addEventListener('click', () => {
        const d = btn.dataset;
        openModal(d.id, d.name, d.enrolled, d.capacity);
      });
    });

    function openModal(sectionId, sectionName, enrolled, capacity) {
      if (dlg.open) return;
      dlgOpenedAt = Date.now();
      const req = ++dlgRequest;
      document.getElementById('modalTitle').textContent = sectionName;
      document.getElementById('modalMeta').textContent  = enrolled + ' enrolled / ' + capacity + ' capacity';
      document.getElementById('modalBody').innerHTML    = '<p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>';
      dlg.showModal();

      fetch('sections?action=students&section_id=' + encodeURIComponent(sectionId))
        .then(r => r.json())
        .then(data => {
          if (req !== dlgRequest) return; // a newer open replaced this one
          if (!data.length) {
            document.getElementById('modalBody').innerHTML = '<p style="color:#8A8A9A;font-size:13px;text-align:center;padding:1.5rem 0;">No enrolled students in this section.</p>';
            return;
          }
          let html = '<table class="student-list"><thead><tr><th>#</th><th>Name</th><th>Student Number</th><th>Status</th></tr></thead><tbody>';
          data.forEach((s, i) => {
            const badgeCls = s.status === 'enrolled' ? 'badge-enrolled' : 'badge-pending';
            html += '<tr>'
              + '<td style="color:#8A8A9A;font-size:12px;">' + (i+1) + '</td>'
              + '<td style="font-weight:500">' + esc(s.family_name) + ', ' + esc(s.given_name) + '</td>'
              + '<td style="font-family:monospace;font-size:12px;color:var(--brand-primary)">' + (s.student_number || '—') + '</td>'
              + '<td><span class="badge ' + badgeCls + '">' + s.status + '</span></td>'
              + '</tr>';
          });
          html += '</tbody></table>';
          document.getElementById('modalBody').innerHTML = html;
        })
        .catch(() => {
          document.getElementById('modalBody').innerHTML = '<p style="color:#C0392B;font-size:13px;">Failed to load students.</p>';
        });
    }

    function esc(str) {
      return String(str || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
  </script>

</body>
</html>