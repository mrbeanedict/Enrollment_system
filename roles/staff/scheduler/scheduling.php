<?php
/* ============================================================
   scheduling.php — Class Scheduling
   ------------------------------------------------------------
   Access model:
     - role='admin'                     -> reviewer (Class Scheduling
                                            Approvals table), same as
                                            coordinator
     - staff, department='coordinator'  -> reviewer
     - staff, department='scheduler'    -> builder: section picker +
                                            per-subject schedule editor,
                                            submits the whole section's
                                            schedule for approval as one unit

   Approval is per SECTION, not per subject row. A scheduler fills in every
   subject for the section (one schedule, good for the whole school year),
   then submits once; a reviewer approves or rejects the whole package.
   Editing an already-approved schedule immediately reverts it to 'pending'
   so the coordinator re-reviews the change.
   ============================================================ */

session_start();
require_once __DIR__ . '/../../../bootstrap.php';
include_once '../notify.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . APP_URL . "/login"); exit();
}
guard_password_change(APP_URL . '/roles/staff/change_password');

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── Resolve department up front (same pattern as curriculum.php) ───────────
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

if (!$is_reviewer && !$is_scheduler) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

$valid_days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

// Hourly business-block boundaries, 7:30 AM – 4:30 PM (matches sections.php's
// weekly_slots(), so a schedule built here lines up with the same grid the
// auto-generator uses).
$time_bounds = [];
for ($h = 7; $h <= 16; $h++) { $time_bounds[] = sprintf('%02d:30:00', $h); }
$start_options = array_slice($time_bounds, 0, -1); // 07:30 .. 15:30
$end_options   = array_slice($time_bounds, 1);     // 08:30 .. 16:30

function format_time_label($t) {
    if (!$t) { return ''; }
    return date('g:i A', strtotime($t));
}

// Semester 1 keeps its original sections columns/prefix; Semester 2 uses its
// own shadow columns and tracking-ID prefix so the two lifecycles never
// collide (see plan: sections.schedule_*_sem2).
function sched_col(string $base, int $semester): string {
    return $semester === 2 ? $base . '_sem2' : $base;
}

$semester = (int)($_GET['semester'] ?? $_POST['semester'] ?? 1);
if (!in_array($semester, [1, 2], true)) { $semester = 1; }

// ── Coordinator table: filters / pagination ─────────────────────────────────
$valid_status = ['', 'pending', 'approved', 'rejected'];
$status_param = $_GET['status'] ?? '';
$f_status = in_array($status_param, $valid_status, true) ? $status_param : 'pending';
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

$return_qs = http_build_query(array_filter([
    'status' => $f_status !== '' ? $f_status : null,
    'q'      => $q !== '' ? $q : null,
    'page'   => $page > 1 ? $page : null,
]));

// ── Update one subject's schedule for one section (SCHEDULER-ONLY, while editable) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $teacher_id = $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;
    $day        = $_POST['day'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time   = $_POST['end_time'] ?? '';
    $room_in    = trim($_POST['room'] ?? '');

    if (!$is_scheduler) {
        $error = 'Only a scheduler can edit a class schedule.';
    } elseif (!$section_id || !$subject_id) {
        $error = 'Invalid section or subject.';
    } elseif (!$day || !$start_time || !$end_time) {
        $error = 'Day, start time, and end time are required to save a schedule slot.';
    } elseif (!in_array($day, $valid_days, true)) {
        $error = 'Invalid day.';
    } elseif ($start_time >= $end_time) {
        $error = 'End time must be after start time.';
    } else {
        $status_col = sched_col('schedule_status', $semester);
        $sec_stmt = $conn->prepare("SELECT room, $status_col AS schedule_status, section_name, school_year FROM sections WHERE section_id = ?");
        $sec_stmt->bind_param('i', $section_id);
        $sec_stmt->execute();
        $sec_row = $sec_stmt->get_result()->fetch_assoc();
        $sec_stmt->close();

        if (!$sec_row) {
            $error = 'Section not found.';
        } elseif (!in_array($sec_row['schedule_status'], ['unscheduled', 'rejected', 'approved'], true)) {
            $error = 'This section\'s schedule is locked while it is pending review.';
        } else {
            $effective_room = $room_in !== '' ? $room_in : ($sec_row['room'] ?? null);

            $conn->begin_transaction();
            $conflict = null;

            if ($teacher_id) {
                $tc = $conn->prepare("
                    SELECT sec2.section_name, sub2.subject_name
                    FROM section_subjects ss2
                    JOIN sections sec2 ON sec2.section_id = ss2.section_id
                    JOIN subjects sub2 ON sub2.subject_id = ss2.subject_id
                    WHERE ss2.teacher_id = ? AND ss2.day = ?
                      AND NOT (ss2.section_id = ? AND ss2.subject_id = ?)
                      AND ss2.start_time < ? AND ss2.end_time > ?
                    FOR UPDATE
                ");
                $tc->bind_param('isiiss', $teacher_id, $day, $section_id, $subject_id, $end_time, $start_time);
                $tc->execute();
                $trow = $tc->get_result()->fetch_assoc();
                $tc->close();
                if ($trow) {
                    $conflict = "This teacher is already scheduled for \"{$trow['subject_name']}\" in {$trow['section_name']} at that day/time.";
                }
            }

            if (!$conflict && $effective_room) {
                $rc = $conn->prepare("
                    SELECT sec2.section_name, sub2.subject_name
                    FROM section_subjects ss2
                    JOIN sections sec2 ON sec2.section_id = ss2.section_id
                    JOIN subjects sub2 ON sub2.subject_id = ss2.subject_id
                    WHERE COALESCE(ss2.room, sec2.room) = ? AND ss2.day = ?
                      AND NOT (ss2.section_id = ? AND ss2.subject_id = ?)
                      AND ss2.start_time < ? AND ss2.end_time > ?
                    FOR UPDATE
                ");
                $rc->bind_param('ssiiss', $effective_room, $day, $section_id, $subject_id, $end_time, $start_time);
                $rc->execute();
                $rrow = $rc->get_result()->fetch_assoc();
                $rc->close();
                if ($rrow) {
                    $conflict = "Room \"{$effective_room}\" is already booked for \"{$rrow['subject_name']}\" ({$rrow['section_name']}) at that day/time.";
                }
            }

            if ($conflict) {
                $conn->rollback();
                $error = $conflict;
            } else {
                $room_param = $room_in !== '' ? $room_in : null;
                $up = $conn->prepare("
                    UPDATE section_subjects
                    SET teacher_id = ?, day = ?, start_time = ?, end_time = ?, room = ?
                    WHERE section_id = ? AND subject_id = ? AND semester = ?
                ");
                $up->bind_param('issssiii', $teacher_id, $day, $start_time, $end_time, $room_param, $section_id, $subject_id, $semester);
                $up->execute();
                $up->close();

                // Keep teacher_assignments (what the teacher portal actually
                // reads) in sync with section_subjects.teacher_id — no
                // unique key on this table, so clear any stale row for this
                // slot first, then insert the current teacher (if any).
                // Scoped by semester so Sem1 and Sem2 each keep their own
                // assignment row even when they share a teacher_id.
                //
                // Read the prior teacher first — this delete+insert re-runs
                // on every save even when nothing changed, so a "you've been
                // assigned" notification below only fires when the teacher
                // on this slot is actually new/different.
                $prev_ta = $conn->prepare(
                    "SELECT teacher_id FROM teacher_assignments WHERE subject_id = ? AND section_id = ? AND school_year = ? AND semester = ?"
                );
                $prev_ta->bind_param('iisi', $subject_id, $section_id, $sec_row['school_year'], $semester);
                $prev_ta->execute();
                $prev_teacher_id = (int) ($prev_ta->get_result()->fetch_assoc()['teacher_id'] ?? 0);
                $prev_ta->close();

                $del_ta = $conn->prepare(
                    "DELETE FROM teacher_assignments WHERE subject_id = ? AND section_id = ? AND school_year = ? AND semester = ?"
                );
                $del_ta->bind_param('iisi', $subject_id, $section_id, $sec_row['school_year'], $semester);
                $del_ta->execute();
                $del_ta->close();

                if ($teacher_id) {
                    $ins_ta = $conn->prepare(
                        "INSERT INTO teacher_assignments (teacher_id, subject_id, section_id, school_year, semester) VALUES (?, ?, ?, ?, ?)"
                    );
                    $ins_ta->bind_param('iiisi', $teacher_id, $subject_id, $section_id, $sec_row['school_year'], $semester);
                    $ins_ta->execute();
                    $ins_ta->close();

                    if ($teacher_id !== $prev_teacher_id) {
                        $subj_stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
                        $subj_stmt->bind_param('i', $subject_id);
                        $subj_stmt->execute();
                        $subject_name = $subj_stmt->get_result()->fetch_assoc()['subject_name'] ?? '';
                        $subj_stmt->close();

                        $tu_stmt = $conn->prepare("SELECT user_id FROM teachers WHERE teacher_id = ?");
                        $tu_stmt->bind_param('i', $teacher_id);
                        $tu_stmt->execute();
                        $tu_row = $tu_stmt->get_result()->fetch_assoc();
                        $tu_stmt->close();
                        notify_teacher_users(
                            $conn,
                            [(int) ($tu_row['user_id'] ?? 0)],
                            "You've been assigned to teach \"$subject_name\" — {$sec_row['section_name']}.",
                            'roles/teacher/teacher_schedule'
                        );
                    }
                }

                // Editing an already-approved schedule un-approves it — the
                // coordinator needs to review the change before it's live
                // again, same mechanics as a first-time submission.
                if ($sec_row['schedule_status'] === 'approved') {
                    $sub_by_col = sched_col('schedule_submitted_by', $semester);
                    $sub_at_col = sched_col('schedule_submitted_at', $semester);
                    $rev_by_col = sched_col('schedule_reviewed_by', $semester);
                    $rev_at_col = sched_col('schedule_reviewed_at', $semester);
                    $rej_col    = sched_col('schedule_rejection_reason', $semester);
                    $revert = $conn->prepare(
                        "UPDATE sections SET $status_col='pending', $sub_by_col=?, $sub_at_col=NOW(),
                         $rev_by_col=NULL, $rev_at_col=NULL, $rej_col=NULL
                         WHERE section_id=?"
                    );
                    $revert->bind_param('ii', $user_id, $section_id);
                    $revert->execute();
                    $revert->close();
                }

                $conn->commit();

                if ($sec_row['schedule_status'] === 'approved') {
                    notify_coordinators($conn, "Schedule for section \"{$sec_row['section_name']}\" was edited and needs re-approval.", 'scheduler/scheduling?status=pending');
                }

                header("Location: scheduling?section_id=" . $section_id . "&semester=" . $semester); exit();
            }
        }
    }
}

// ── Submit a section's schedule for approval (SCHEDULER-ONLY) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    if (!$is_scheduler) {
        $error = 'Only a scheduler can submit a section schedule for approval.';
    } else {
        $status_col = sched_col('schedule_status', $semester);
        $req_id_col = sched_col('schedule_request_id', $semester);
        $sec_stmt = $conn->prepare("SELECT $status_col AS schedule_status, $req_id_col AS schedule_request_id, section_name FROM sections WHERE section_id = ?");
        $sec_stmt->bind_param('i', $section_id);
        $sec_stmt->execute();
        $sec_row = $sec_stmt->get_result()->fetch_assoc();
        $sec_stmt->close();

        if (!$sec_row) {
            $error = 'Section not found.';
        } elseif (!in_array($sec_row['schedule_status'], ['unscheduled', 'rejected'], true)) {
            $error = 'This schedule has already been submitted or approved.';
        } else {
            // Completeness check across every subject row for this section —
            // one schedule, good for the whole semester.
            $chk = $conn->prepare("
                SELECT COUNT(*) AS c FROM section_subjects ss
                WHERE ss.section_id = ? AND ss.semester = ?
                  AND (ss.teacher_id IS NULL OR ss.day IS NULL OR ss.start_time IS NULL OR ss.end_time IS NULL)
            ");
            $chk->bind_param('ii', $section_id, $semester);
            $chk->execute();
            $incomplete = (int)$chk->get_result()->fetch_assoc()['c'];
            $chk->close();

            $total_chk = $conn->prepare("SELECT COUNT(*) AS c FROM section_subjects ss WHERE ss.section_id = ? AND ss.semester = ?");
            $total_chk->bind_param('ii', $section_id, $semester);
            $total_chk->execute();
            $total = (int)$total_chk->get_result()->fetch_assoc()['c'];
            $total_chk->close();

            if ($total === 0) {
                $error = 'This section has no subjects to schedule yet.';
            } elseif ($incomplete > 0) {
                $error = "$incomplete of $total subjects still need a teacher, day, and time before you can submit.";
            } else {
                // A tracking ID is generated once and stays stable across
                // reject-then-resubmit cycles — only assign one if this
                // section doesn't already have one. Semester 2 uses its own
                // "SCH2-" prefix so the two sequences never collide.
                $request_id = $sec_row['schedule_request_id'];
                if (!$request_id) {
                    $cn_year   = date('Y');
                    $cn_prefix = $semester === 2 ? "SCH2-$cn_year-" : "SCH-$cn_year-";
                    $cn_like   = $cn_prefix . '%';

                    $seq_stmt = $conn->prepare(
                        "SELECT $req_id_col AS rid FROM sections
                         WHERE $req_id_col LIKE ?
                         ORDER BY $req_id_col DESC LIMIT 1 FOR UPDATE"
                    );
                    $seq_stmt->bind_param('s', $cn_like);
                    $seq_stmt->execute();
                    $last_row = $seq_stmt->get_result()->fetch_assoc();
                    $seq_stmt->close();

                    $next_seq = 1;
                    if ($last_row) {
                        $parts    = explode('-', $last_row['rid']);
                        $next_seq = ((int) end($parts)) + 1;
                    }

                    $attempts = 0;
                    $saved    = false;
                    while (!$saved && $attempts < 3) {
                        $attempts++;
                        $request_id = $cn_prefix . str_pad((string)$next_seq, 4, '0', STR_PAD_LEFT);
                        $rid_stmt = $conn->prepare("UPDATE sections SET $req_id_col=? WHERE section_id=?");
                        $rid_stmt->bind_param('si', $request_id, $section_id);
                        if ($rid_stmt->execute()) {
                            $saved = true;
                        } elseif ($conn->errno === 1062) {
                            $next_seq++;
                        }
                        $rid_stmt->close();
                    }
                }

                $sub_by_col = sched_col('schedule_submitted_by', $semester);
                $sub_at_col = sched_col('schedule_submitted_at', $semester);
                $rej_col    = sched_col('schedule_rejection_reason', $semester);
                $upd = $conn->prepare(
                    "UPDATE sections SET $status_col='pending', $sub_by_col=?, $sub_at_col=NOW(), $rej_col=NULL WHERE section_id=?"
                );
                $upd->bind_param('ii', $user_id, $section_id);
                $upd->execute();
                $upd->close();
                notify_coordinators($conn, "Schedule for section \"{$sec_row['section_name']}\" awaiting approval.", 'scheduler/scheduling?status=pending');
                header("Location: scheduling?section_id=" . $section_id . "&semester=" . $semester); exit();
            }
        }
    }
}

// ── Approve a submitted schedule, whole section at once (REVIEWER-ONLY) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can approve a section schedule.';
    } else {
        $status_col = sched_col('schedule_status', $semester);
        $sub_by_col = sched_col('schedule_submitted_by', $semester);
        $rev_by_col = sched_col('schedule_reviewed_by', $semester);
        $rev_at_col = sched_col('schedule_reviewed_at', $semester);

        $info_stmt = $conn->prepare("SELECT section_name, $sub_by_col AS schedule_submitted_by FROM sections WHERE section_id = ?");
        $info_stmt->bind_param('i', $section_id);
        $info_stmt->execute();
        $sched_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare(
            "UPDATE sections SET $status_col='approved', $rev_by_col=?, $rev_at_col=NOW()
             WHERE section_id=? AND $status_col='pending'"
        );
        $stmt->bind_param('ii', $user_id, $section_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            if ($sched_row && $sched_row['schedule_submitted_by']) {
                notify_users($conn, [$sched_row['schedule_submitted_by']], "Schedule for section \"{$sched_row['section_name']}\" was approved.", 'scheduler/scheduling');
            }
            header("Location: scheduling?" . $return_qs); exit();
        }
        $error = 'Could not approve — this schedule may have already been reviewed.';
    }
}

// ── Reject a submitted schedule, whole section at once (REVIEWER-ONLY) ─────
// Also used for "Deactivate" on an already-approved schedule — same effect
// (revert to rejected, scheduler can revise and resubmit), just an
// auto-filled reason and a different entry point (guard below allows it).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $reason     = trim($_POST['rejection_reason'] ?? '');
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can reject a section schedule.';
    } elseif (!$reason) {
        $error = 'Please provide a reason for rejecting this schedule.';
    } else {
        $status_col = sched_col('schedule_status', $semester);
        $sub_by_col = sched_col('schedule_submitted_by', $semester);
        $rev_by_col = sched_col('schedule_reviewed_by', $semester);
        $rev_at_col = sched_col('schedule_reviewed_at', $semester);
        $rej_col    = sched_col('schedule_rejection_reason', $semester);

        $info_stmt = $conn->prepare("SELECT section_name, $sub_by_col AS schedule_submitted_by FROM sections WHERE section_id = ?");
        $info_stmt->bind_param('i', $section_id);
        $info_stmt->execute();
        $sched_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare(
            "UPDATE sections SET $status_col='rejected', $rev_by_col=?, $rev_at_col=NOW(), $rej_col=?
             WHERE section_id=? AND $status_col IN ('pending','approved')"
        );
        $stmt->bind_param('isi', $user_id, $reason, $section_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            if ($sched_row && $sched_row['schedule_submitted_by']) {
                notify_users($conn, [$sched_row['schedule_submitted_by']], "Schedule for section \"{$sched_row['section_name']}\" was rejected: $reason", 'scheduler/scheduling');
            }
            header("Location: scheduling?" . $return_qs); exit();
        }
        $error = 'Could not reject — this schedule may have already been reviewed.';
    }
}

// ── Coordinator table: schedule requests (filtered/paginated) ──────────────
// Merges Semester 1 and Semester 2 requests (each tracked in its own shadow
// columns) into one list, tagged per row with which semester it belongs to
// so "View"/Approve/Reject links carry the right &semester= through.
$status_value_map = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'];

$requests = [];
$total_count = 0;
$total_pages = 1;
$pending_count = 0;
if ($is_reviewer) {
    $pc = mysqli_query($conn, "SELECT
        (SELECT COUNT(*) FROM sections WHERE schedule_status = 'pending') +
        (SELECT COUNT(*) FROM sections WHERE schedule_status_sem2 = 'pending') AS c");
    $pending_count = $pc ? (int)mysqli_fetch_assoc($pc)['c'] : 0;

    $all_requests = [];
    foreach ([1, 2] as $sem) {
        $status_col = sched_col('schedule_status', $sem);
        $req_id_col = sched_col('schedule_request_id', $sem);
        $sub_at_col = sched_col('schedule_submitted_at', $sem);

        $where  = ["sec.$status_col != 'unscheduled'"];
        $types  = '';
        $params = [];
        if ($f_status !== '' && isset($status_value_map[$f_status])) {
            $where[] = "sec.$status_col = ?";
            $types  .= 's';
            $params[] = $status_value_map[$f_status];
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(sec.section_name LIKE ? OR req.family_name LIKE ? OR req.given_name LIKE ?)';
            $types  .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $where_sql = implode(' AND ', $where);
        $sub_by_col = sched_col('schedule_submitted_by', $sem);

        $sql = "
            SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.school_year,
                   sec.$status_col AS schedule_status, sec.$req_id_col AS schedule_request_id, sec.$sub_at_col AS schedule_submitted_at,
                   req.family_name AS req_family, req.given_name AS req_given
            FROM sections sec
            JOIN strands st ON st.strand_id = sec.strand
            LEFT JOIN staff req ON req.user_id = sec.$sub_by_col
            WHERE $where_sql
        ";

        $stmt = $conn->prepare($sql);
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $row['semester'] = $sem;
            $all_requests[] = $row;
        }
        $stmt->close();
    }

    usort($all_requests, function ($a, $b) {
        $ap = $a['schedule_status'] === 'pending' ? 1 : 0;
        $bp = $b['schedule_status'] === 'pending' ? 1 : 0;
        if ($ap !== $bp) { return $bp - $ap; }
        return strcmp((string)$b['schedule_submitted_at'], (string)$a['schedule_submitted_at']);
    });

    $total_count = count($all_requests);
    $total_pages = max(1, (int)ceil($total_count / $per_page));
    if ($page > $total_pages) { $page = $total_pages; }
    $offset = ($page - 1) * $per_page;
    $requests = array_slice($all_requests, $offset, $per_page);
}

$status_labels = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];

// ── Scheduler view: sections list, with scheduling progress per section ────
$sections = [];
if ($is_scheduler) {
    $status_col = sched_col('schedule_status', $semester);
    $rej_col    = sched_col('schedule_rejection_reason', $semester);
    $sql = "
        SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand,
               sec.room, sec.school_year, sec.$status_col AS schedule_status, sec.$rej_col AS schedule_rejection_reason,
               COUNT(DISTINCT ss.subject_id) AS subject_count,
               COUNT(DISTINCT CASE WHEN ss.day IS NOT NULL AND ss.start_time IS NOT NULL
                    AND ss.end_time IS NOT NULL AND ss.teacher_id IS NOT NULL
                    THEN ss.subject_id END) AS scheduled_count
        FROM sections sec
        JOIN strands st ON st.strand_id = sec.strand
        LEFT JOIN section_subjects ss ON ss.section_id = sec.section_id AND ss.semester = ?
        WHERE sec.is_active = 1
        GROUP BY sec.section_id
        ORDER BY sec.grade_level ASC, sec.strand ASC, sec.section_name ASC
    ";
    $sec_stmt2 = $conn->prepare($sql);
    $sec_stmt2->bind_param('i', $semester);
    $sec_stmt2->execute();
    $sections = $sec_stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $sec_stmt2->close();
}

// ── Selected section detail (used by both roles: scheduler's editor, and
//    the reviewer's "View" drill-down from the requests table) ─────────────
$selected = null;
$subject_rows = [];
$can_edit = false;

$sel_id = (int)($_GET['section_id'] ?? 0);
if ($sel_id) {
    $status_col = sched_col('schedule_status', $semester);
    $req_id_col = sched_col('schedule_request_id', $semester);
    $rej_col    = sched_col('schedule_rejection_reason', $semester);
    $sel_stmt = $conn->prepare("
        SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.room, sec.school_year,
               sec.$status_col AS schedule_status, sec.$req_id_col AS schedule_request_id, sec.$rej_col AS schedule_rejection_reason
        FROM sections sec
        JOIN strands st ON st.strand_id = sec.strand
        WHERE sec.section_id = ?
    ");
    $sel_stmt->bind_param('i', $sel_id);
    $sel_stmt->execute();
    $selected = $sel_stmt->get_result()->fetch_assoc() ?: null;
    $sel_stmt->close();
}

if ($selected) {
    $can_edit = $is_scheduler && in_array($selected['schedule_status'], ['unscheduled', 'rejected', 'approved'], true);

    $ss_stmt = $conn->prepare("
        SELECT ss.subject_id, sub.subject_name, ss.day, ss.start_time, ss.end_time,
               ss.room, ss.teacher_id, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
        FROM section_subjects ss
        JOIN subjects sub ON sub.subject_id = ss.subject_id
        LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
        WHERE ss.section_id = ? AND ss.semester = ?
        ORDER BY sub.subject_name
    ");
    $ss_stmt->bind_param('ii', $selected['section_id'], $semester);
    $ss_stmt->execute();
    $subject_rows = $ss_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ss_stmt->close();

    // Existing-conflict scan (read-only) — surfaces any teacher/room clash
    // regardless of who caused it, so nothing hides once a schedule is set.
    foreach ($subject_rows as &$row) {
        $row['conflict'] = null;
        if ($row['day'] && $row['start_time'] && $row['end_time']) {
            if ($row['teacher_id']) {
                $tc = $conn->prepare("
                    SELECT sec2.section_name, sub2.subject_name
                    FROM section_subjects ss2
                    JOIN sections sec2 ON sec2.section_id = ss2.section_id
                    JOIN subjects sub2 ON sub2.subject_id = ss2.subject_id
                    WHERE ss2.teacher_id = ? AND ss2.day = ?
                      AND NOT (ss2.section_id = ? AND ss2.subject_id = ?)
                      AND ss2.start_time < ? AND ss2.end_time > ?
                    LIMIT 1
                ");
                $tc->bind_param('isiiss', $row['teacher_id'], $row['day'], $selected['section_id'], $row['subject_id'], $row['end_time'], $row['start_time']);
                $tc->execute();
                $trow = $tc->get_result()->fetch_assoc();
                $tc->close();
                if ($trow) { $row['conflict'] = "Teacher double-booked with \"{$trow['subject_name']}\" ({$trow['section_name']})"; }
            }
            if (!$row['conflict']) {
                $eff_room = $row['room'] ?: $selected['room'];
                if ($eff_room) {
                    $rc = $conn->prepare("
                        SELECT sec2.section_name, sub2.subject_name
                        FROM section_subjects ss2
                        JOIN sections sec2 ON sec2.section_id = ss2.section_id
                        JOIN subjects sub2 ON sub2.subject_id = ss2.subject_id
                        WHERE COALESCE(ss2.room, sec2.room) = ? AND ss2.day = ?
                          AND NOT (ss2.section_id = ? AND ss2.subject_id = ?)
                          AND ss2.start_time < ? AND ss2.end_time > ?
                        LIMIT 1
                    ");
                    $rc->bind_param('ssiiss', $eff_room, $row['day'], $selected['section_id'], $row['subject_id'], $row['end_time'], $row['start_time']);
                    $rc->execute();
                    $rrow = $rc->get_result()->fetch_assoc();
                    $rc->close();
                    if ($rrow) { $row['conflict'] = "Room double-booked with \"{$rrow['subject_name']}\" ({$rrow['section_name']})"; }
                }
            }
        }
    }
    unset($row);
}

$teachers = mysqli_query($conn, "SELECT teacher_id, CONCAT(given_name, ' ', family_name) AS name FROM teachers WHERE is_active = 1 ORDER BY family_name, given_name")->fetch_all(MYSQLI_ASSOC);

// NOTE: do NOT close $conn here — staff_sidebar.php (included below, in the
// HTML body) runs its own queries for nav badges/status and needs the
// connection still open. Let PHP close it automatically at script end.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Class Scheduling <?= $is_reviewer ? 'Approvals — Coordinator' : '— Scheduler' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    .staff-content .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    .two-col { display:grid; grid-template-columns:280px 1fr; gap:14px; align-items:start; }
    @media (max-width: 900px) { .two-col { grid-template-columns:1fr; } }

    tr.clickable { cursor:pointer; }
    tr.clickable:hover td { background:#FAFAFC; }
    tr.sec-selected td { background: var(--brand-tint); }

    .prog-bar { height:5px; border-radius:3px; background:#EBEBF0; margin-top:4px; overflow:hidden; width:100%; }
    .prog-fill { height:100%; border-radius:3px; }
    .prog-done    { background:#2ECC71; }
    .prog-partial { background:#E67E22; }
    .prog-none    { background:#C0392B; }

    .sched-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed; }
    .sched-table th {
      text-align:left; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#8A8A9A; padding:8px 8px 8px 0; border-bottom:0.5px solid #EBEBF0;
    }
    .sched-table td { padding:10px 8px 10px 0; border-bottom:0.5px solid #F5F5F7; vertical-align:middle; }
    .sched-table tr:last-child td { border-bottom:none; }
    .sched-table select, .sched-table input[type=text] {
      height:36px; font-size:12px; width:100%; box-sizing:border-box;
      border:0.5px solid #D4D4E0; border-radius:6px; background:#FAFAFC; padding:0 8px;
    }
    .sched-table-scroll { overflow-x:auto; }
    @media (max-width: 900px) { .sched-table { min-width:760px; } }
    .sched-subject { font-size:13px; font-weight:500; color:#1A1A2E; }
    .conflict-tag {
      display:inline-block; font-size:10px; font-weight:600; color:#C0392B;
      background:#FDF0EF; border-radius:20px; padding:2px 8px; margin-top:3px;
    }
    .btn-save-row {
      height:30px; padding:0 12px; background:var(--brand-primary); border:none;
      border-radius:6px; color:#fff; font-size:11px; font-weight:500; cursor:pointer;
      font-family:inherit; white-space:nowrap;
    }
    .btn-save-row:hover { background:var(--brand-primary-hover); }

    .empty-hint { color:#8A8A9A; font-size:13px; padding:2rem 0; text-align:center; }

    /* Approvals table (coordinator view) */
    .filter-row { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:.75rem; }
    .filter-tab {
      display:inline-flex; align-items:center; justify-content:center;
      height:28px; box-sizing:border-box; line-height:1; text-transform:none;
      font-size:12px; font-weight:500; padding:0 12px; border-radius:20px;
      border:0.5px solid #D4D4E0; background:#fff; color:#5A5A72;
      text-decoration:none; white-space:nowrap; transition:all .12s;
    }
    .filter-tab:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .filter-tab.active { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }
    .filter-tab .cnt { opacity:.85; }

    .toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:1rem; flex-wrap:wrap; }
    .search-box { position:relative; flex:0 0 260px; }
    .search-box input {
      width:100%; height:36px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC; padding:0 12px 0 34px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none;
      box-sizing:border-box;
    }
    .search-box svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#8A8A9A; }

    .data-table { width:100%; border-collapse:collapse; font-size:13px; }
    .data-table th {
      text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.05em; color:#8A8A9A; padding:8px 12px; border-bottom:0.5px solid #EBEBF0;
    }
    .data-table td { padding:10px 12px; border-bottom:0.5px solid #F5F5F7; vertical-align:top; }
    .data-table th:first-child, .data-table td:first-child { padding-left:0; }
    .data-table th:last-child,  .data-table td:last-child  { padding-right:0; }
    .data-table tr:last-child td { border-bottom:none; }
    .td-name { font-weight:500; color:#1A1A2E; }
    .td-meta { font-size:11px; color:#8A8A9A; margin-top:2px; }
    .req-id  { font-family:monospace; font-size:12px; color:var(--brand-primary); font-weight:600; }

    .badge { display:inline-block; font-size:10px; font-weight:600; text-transform:uppercase;
      letter-spacing:.03em; border-radius:20px; padding:2px 9px; margin:1px 4px 1px 0; white-space:nowrap; }
    .badge-unscheduled { background:#F5F5F7; color:#8A8A9A; }
    .badge-pending     { background:#FFF4E6; color:#C06A10; }
    .badge-approved    { background:#EAF3EE; color:var(--brand-primary); }
    .badge-rejected    { background:#FDF0EF; color:#C0392B; }

    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72; margin:2px 4px 2px 0; text-decoration:none;
      display:inline-flex; align-items:center; justify-content:center;
      box-sizing:border-box; line-height:1; white-space:nowrap; vertical-align:middle;
      -webkit-appearance:none; appearance:none;
    }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }

    .app-pagination { display:flex; justify-content:space-between; align-items:center; margin-top:1rem; font-size:12px; color:#8A8A9A; }
    .app-pagination .pages { display:flex; gap:4px; }
    .app-pagination .pages a, .app-pagination .pages span {
      display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:26px;
      border-radius:6px; text-decoration:none; color:#5A5A72; font-size:12px;
    }
    .app-pagination .pages a:hover { background:#F5F5F7; }
    .app-pagination .pages span.current { background:var(--brand-primary); color:#fff; font-weight:600; }

    /* View-schedule modal (coordinator "View" drill-down) */
    .modal-overlay {
      position:fixed; inset:0; background:rgba(26,26,46,0.45);
      display:flex; align-items:flex-start; justify-content:center;
      padding:5vh 20px; z-index:500; overflow-y:auto;
    }
    .modal-box {
      background:#fff; border-radius:12px; max-width:760px; width:100%;
      padding:1.5rem; box-shadow:0 20px 60px rgba(20,20,30,.25);
      animation: modal-pop .15s ease-out;
    }
    @keyframes modal-pop { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
    .modal-header {
      display:flex; justify-content:space-between; align-items:flex-start;
      gap:12px; margin-bottom:.9rem;
    }
    .modal-header-main { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .modal-close {
      flex-shrink:0; width:28px; height:28px; display:flex; align-items:center; justify-content:center;
      font-size:20px; line-height:1; color:#8A8A9A; text-decoration:none; border-radius:7px;
      transition:background .12s, color .12s;
    }
    .modal-close:hover { background:#F5F5F7; color:#1A1A2E; }
  </style>
</head>
<body class="staff-layout">

  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>

  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <button class="btn-sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
          <span></span><span></span><span></span>
        </button>
        <div class="staff-topbar-title">
          Class Scheduling<?= $is_reviewer ? ' Approvals' : '' ?>
          <span class="staff-topbar-subtitle"><?= $is_reviewer ? 'Review and approve scheduling requests' : 'Assign teachers, rooms, and time slots' ?></span>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <p class="page-eyebrow"><?= $is_reviewer ? 'Coordinator Portal' : 'Scheduler Portal' ?></p>
      <h1 class="page-title">Class Scheduling<?= $is_reviewer ? ' Approvals' : '' ?></h1>
      <p class="page-sub">
        <?= $is_reviewer
            ? 'Review schedule sets submitted by schedulers. View details and approve or reject requests.'
            : 'Pick a section to assign teachers, rooms, and time slots for its subjects. Saves are checked for teacher and room clashes against every other section.' ?>
      </p>

      <?php if ($success): ?><div class="notice notice-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($is_reviewer): ?>

        <!-- ═══════════════ Coordinator: Class Scheduling Approvals ═══════════════ -->
        <div class="panel">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.9rem;">
            <span class="card-title" style="font-size:13px;font-weight:600;color:#1A1A2E;">Schedule Requests (<?= $total_count ?>)</span>
          </div>

          <div class="toolbar">
            <div class="filter-row" style="margin-bottom:0;">
              <?php foreach ($status_labels as $skey => $slabel): ?>
                <a class="filter-tab <?= $f_status === $skey ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_filter(['status' => $skey, 'q' => $q !== '' ? $q : null])) ?>">
                  <?= $slabel ?><?php if ($skey === 'pending' && $pending_count > 0): ?> <span class="cnt">(<?= $pending_count ?>)</span><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
            <form method="GET" class="search-box">
              <input type="hidden" name="status" value="<?= htmlspecialchars($f_status) ?>">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M19 11a8 8 0 11-16 0 8 8 0 0116 0z"/>
              </svg>
              <input type="text" name="q" placeholder="Search section or scheduler…" value="<?= htmlspecialchars($q) ?>">
            </form>
          </div>

          <?php if (!$requests): ?>
            <div class="empty-hint">No schedule requests found for this filter.</div>
          <?php else: ?>
            <div class="sched-table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Request ID</th>
                  <th>Section</th>
                  <th>Sem.</th>
                  <th>S.Y.</th>
                  <th>Submitted By</th>
                  <th>Submitted On</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $r): ?>
                  <tr>
                    <td class="req-id"><?= htmlspecialchars($r['schedule_request_id'] ?: '—') ?></td>
                    <td>
                      <div class="td-name"><?= htmlspecialchars($r['section_name']) ?></div>
                      <div class="td-meta">G<?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></div>
                    </td>
                    <td class="td-meta">Sem <?= (int)$r['semester'] ?></td>
                    <td class="td-meta"><?= htmlspecialchars($r['school_year']) ?></td>
                    <td>
                      <?php if ($r['req_family']): ?>
                        <div class="td-name"><?= htmlspecialchars($r['req_given'] . ' ' . $r['req_family']) ?></div>
                        <div class="td-meta">Scheduler</div>
                      <?php else: ?>
                        <span class="td-meta">&mdash;</span>
                      <?php endif; ?>
                    </td>
                    <td class="td-meta"><?= $r['schedule_submitted_at'] ? date('M j, Y g:i A', strtotime($r['schedule_submitted_at'])) : '—' ?></td>
                    <td>
                      <span class="badge badge-<?= $r['schedule_status'] ?>"><?= ucfirst($r['schedule_status']) ?></span>
                    </td>
                    <td onclick="event.stopPropagation()">
                      <a href="?section_id=<?= (int)$r['section_id'] ?>&semester=<?= (int)$r['semester'] ?>&<?= $return_qs ?>" class="btn-toggle">View</a>
                      <?php if ($r['schedule_status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;" data-confirm="Approve the schedule for &quot;<?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>&quot;?" data-icon="question">
                          <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                          <input type="hidden" name="semester" value="<?= (int)$r['semester'] ?>">
                          <button type="submit" name="approve_schedule" class="btn-toggle" style="color:#1A7A5E;border-color:#A8D9C5;">Approve</button>
                        </form>
                        <form method="POST" style="display:inline;" class="reject-form">
                          <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                          <input type="hidden" name="semester" value="<?= (int)$r['semester'] ?>">
                          <input type="hidden" name="rejection_reason" value="">
                          <button type="button" class="btn-toggle js-reject" style="color:#C0392B;border-color:#F5C6C2;"
                                  data-name="<?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>">Reject</button>
                        </form>
                      <?php elseif ($r['schedule_status'] === 'approved'): ?>
                        <form method="POST" style="display:inline;"
                              data-confirm="Deactivate the approved schedule for &quot;<?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>&quot;? The scheduler will need to revise and resubmit it." data-icon="warning">
                          <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                          <input type="hidden" name="semester" value="<?= (int)$r['semester'] ?>">
                          <input type="hidden" name="rejection_reason" value="Deactivated by coordinator">
                          <button type="submit" name="reject_schedule" class="btn-toggle" style="color:#C0392B;border-color:#F5C6C2;">Deactivate</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>

            <?php if ($total_pages > 1):
              $base_params = array_filter(['status' => $f_status !== '' ? $f_status : null, 'q' => $q !== '' ? $q : null]);
            ?>
              <div class="app-pagination">
                <span>Page <?= $page ?> of <?= $total_pages ?> &middot; <?= $total_count ?> total</span>
                <div class="pages">
                  <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($base_params, ['page' => $page - 1])) ?>">&larr;</a><?php endif; ?>
                  <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <?php if ($p === $page): ?><span class="current"><?= $p ?></span>
                    <?php else: ?><a href="?<?= http_build_query(array_merge($base_params, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?><a href="?<?= http_build_query(array_merge($base_params, ['page' => $page + 1])) ?>">&rarr;</a><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if ($selected):
          $close_href = '?' . $return_qs;
        ?>
          <!-- Read-only detail (coordinator "View") — shown as a modal -->
          <div class="modal-overlay" id="viewScheduleModal" onclick="if(event.target===this) window.location.href='<?= htmlspecialchars($close_href) ?>';">
            <div class="modal-box">
              <div class="modal-header">
                <div class="modal-header-main">
                  <span class="card-title" style="font-size:14px;font-weight:600;color:#1A1A2E;">
                    <?= htmlspecialchars($selected['section_name']) ?> &middot; <?= htmlspecialchars($selected['school_year']) ?> &middot; Semester <?= $semester ?>
                    <?php if ($selected['schedule_request_id']): ?> &middot; <span class="req-id"><?= htmlspecialchars($selected['schedule_request_id']) ?></span><?php endif; ?>
                  </span>
                  <span class="badge badge-<?= $selected['schedule_status'] ?>"><?= ucfirst($selected['schedule_status']) ?></span>
                </div>
                <a href="<?= htmlspecialchars($close_href) ?>" class="modal-close" aria-label="Close">&times;</a>
              </div>
              <?php if ($selected['schedule_status'] === 'rejected' && $selected['schedule_rejection_reason']): ?>
                <div class="notice notice-error" style="margin-left:0;margin-right:0;">Rejected: <?= htmlspecialchars($selected['schedule_rejection_reason']) ?></div>
              <?php endif; ?>
              <?php if (empty($subject_rows)): ?>
                <p class="empty-hint">No subjects assigned to this section yet.</p>
              <?php else: ?>
                <div class="sched-table-scroll">
                <table class="sched-table">
                  <thead><tr><th>Subject</th><th>Teacher</th><th>Day</th><th>Start</th><th>End</th><th>Room</th></tr></thead>
                  <tbody>
                    <?php foreach ($subject_rows as $row): ?>
                      <tr>
                        <td>
                          <div class="sched-subject"><?= htmlspecialchars($row['subject_name']) ?></div>
                          <?php if ($row['conflict']): ?><div class="conflict-tag">⚠ <?= htmlspecialchars($row['conflict']) ?></div><?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['teacher_name'] ?: 'Not set') ?></td>
                        <td><?= htmlspecialchars($row['day'] ?: 'Not set') ?></td>
                        <td><?= $row['start_time'] ? format_time_label($row['start_time']) : 'Not set' ?></td>
                        <td><?= $row['end_time'] ? format_time_label($row['end_time']) : 'Not set' ?></td>
                        <td><?= htmlspecialchars($row['room'] ?: ($selected['room'] ?: 'Homeroom')) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      <?php else: ?>

        <!-- ═══════════════ Scheduler: section picker + editor (unchanged) ═══════════════ -->
        <div class="two-col">

          <!-- Section picker -->
          <div class="panel">
            <div class="panel-sub" style="display:flex;flex-direction:column;gap:8px;">
              <span>Sections</span>
              <div style="display:flex;gap:6px;">
                <a class="filter-tab <?= $semester === 1 ? 'active' : '' ?>" href="?semester=1" style="flex:1;">Semester 1</a>
                <a class="filter-tab <?= $semester === 2 ? 'active' : '' ?>" href="?semester=2" style="flex:1;">Semester 2</a>
              </div>
            </div>
            <table class="sched-table">
              <thead>
                <tr><th>Section</th><th>Progress</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php foreach ($sections as $sec):
                  $sid   = (int)$sec['section_id'];
                  $total = (int)$sec['subject_count'];
                  $done  = (int)$sec['scheduled_count'];
                  $pct   = $total > 0 ? round($done / $total * 100) : 0;
                  if ($total === 0)        { $progClass = 'prog-none'; }
                  elseif ($done < $total)  { $progClass = 'prog-partial'; }
                  else                     { $progClass = 'prog-done'; }
                  $rowClass = 'clickable' . ((int)($selected['section_id'] ?? 0) === $sid ? ' sec-selected' : '');
                ?>
                  <tr class="<?= $rowClass ?>" onclick="location.href='scheduling?section_id=<?= $sid ?>&semester=<?= $semester ?>'">
                    <td>
                      <div class="sched-subject"><?= htmlspecialchars($sec['section_name']) ?></div>
                      <div style="font-size:11px;color:#8A8A9A;">G<?= htmlspecialchars($sec['grade_level']) ?> &middot; <?= htmlspecialchars($sec['strand']) ?> &middot; <?= htmlspecialchars($sec['school_year']) ?></div>
                    </td>
                    <td style="width:90px;">
                      <div style="font-size:11px;color:#8A8A9A;"><?= $done ?>/<?= $total ?> set</div>
                      <div class="prog-bar"><div class="prog-fill <?= $progClass ?>" style="width:<?= $pct ?>%"></div></div>
                    </td>
                    <td><span class="badge badge-<?= $sec['schedule_status'] ?>"><?= ucfirst($sec['schedule_status']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($sections)): ?>
                  <tr><td colspan="3" class="empty-hint">No active sections yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Schedule editor -->
          <div class="panel">
            <?php if (!$selected): ?>
              <p class="empty-hint">Select a section on the left to manage its schedule.</p>
            <?php else: ?>
              <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.9rem;">
                <span class="panel-sub" style="margin:0;">
                  <?= htmlspecialchars($selected['section_name']) ?> &middot; <?= htmlspecialchars($selected['school_year']) ?> &middot; Semester <?= $semester ?>
                </span>
                <span class="badge badge-<?= $selected['schedule_status'] ?>"><?= ucfirst($selected['schedule_status']) ?></span>
              </div>

              <?php if ($selected['schedule_status'] === 'rejected' && $selected['schedule_rejection_reason']): ?>
                <div class="notice notice-error">Rejected: <?= htmlspecialchars($selected['schedule_rejection_reason']) ?> — make the needed changes and resubmit.</div>
              <?php elseif ($selected['schedule_status'] === 'pending'): ?>
                <div class="notice notice-info">Submitted — awaiting coordinator approval. Editing is locked until it's reviewed.</div>
              <?php elseif ($selected['schedule_status'] === 'approved'): ?>
                <div class="notice notice-info">This schedule has been approved. Editing any field will send it back to the coordinator for re-approval.</div>
              <?php endif; ?>

              <?php if (empty($subject_rows)): ?>
                <p class="empty-hint">
                  <?= $semester === 2
                        ? 'No Semester 2 subjects set up yet for this section — use Create Schedule to start Semester 2.'
                        : 'No subjects assigned to this section yet — check curriculum approval for this grade/strand.' ?>
                </p>
              <?php else: ?>
                <div class="sched-table-scroll">
                <table class="sched-table">
                  <colgroup>
                    <col style="width:22%"><col style="width:22%"><col style="width:10%">
                    <col style="width:12%"><col style="width:12%"><col style="width:14%">
                    <?php if ($can_edit): ?><col style="width:8%"><?php endif; ?>
                  </colgroup>
                  <thead>
                    <tr>
                      <th>Subject</th><th>Teacher</th><th>Day</th><th>Start</th><th>End</th><th>Room</th>
                      <?php if ($can_edit): ?><th></th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($subject_rows as $row):
                      $fid = 'schedForm_' . $row['subject_id'];
                    ?>
                      <tr>
                        <td>
                          <div class="sched-subject"><?= htmlspecialchars($row['subject_name']) ?></div>
                          <?php if ($row['conflict']): ?><div class="conflict-tag">⚠ <?= htmlspecialchars($row['conflict']) ?></div><?php endif; ?>
                        </td>
                        <?php if ($can_edit): ?>
                          <td>
                            <select name="teacher_id" form="<?= $fid ?>">
                              <option value="">— Select —</option>
                              <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['teacher_id'] ?>" <?= (int)$row['teacher_id'] === (int)$t['teacher_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <select name="day" form="<?= $fid ?>">
                              <option value="">— Select —</option>
                              <?php foreach ($valid_days as $d): ?>
                                <option value="<?= $d ?>" <?= $row['day'] === $d ? 'selected' : '' ?>><?= $d ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <select name="start_time" form="<?= $fid ?>">
                              <option value="">— Select —</option>
                              <?php foreach ($start_options as $t): ?>
                                <option value="<?= $t ?>" <?= $row['start_time'] === $t ? 'selected' : '' ?>><?= format_time_label($t) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <select name="end_time" form="<?= $fid ?>">
                              <option value="">— Select —</option>
                              <?php foreach ($end_options as $t): ?>
                                <option value="<?= $t ?>" <?= $row['end_time'] === $t ? 'selected' : '' ?>><?= format_time_label($t) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <input type="text" name="room" form="<?= $fid ?>"
                                   placeholder="<?= htmlspecialchars($selected['room'] ?: 'Homeroom') ?>"
                                   value="<?= htmlspecialchars($row['room'] ?? '') ?>">
                          </td>
                          <td>
                            <form id="<?= $fid ?>" method="POST" style="display:inline;">
                              <input type="hidden" name="section_id" value="<?= (int)$selected['section_id'] ?>">
                              <input type="hidden" name="subject_id" value="<?= (int)$row['subject_id'] ?>">
                              <input type="hidden" name="semester" value="<?= $semester ?>">
                              <button type="submit" name="update_schedule" class="btn-save-row">Save</button>
                            </form>
                          </td>
                        <?php else: ?>
                          <td><?= htmlspecialchars($row['teacher_name'] ?: 'Not set') ?></td>
                          <td><?= htmlspecialchars($row['day'] ?: 'Not set') ?></td>
                          <td><?= $row['start_time'] ? format_time_label($row['start_time']) : 'Not set' ?></td>
                          <td><?= $row['end_time'] ? format_time_label($row['end_time']) : 'Not set' ?></td>
                          <td><?= htmlspecialchars($row['room'] ?: ($selected['room'] ?: 'Homeroom')) ?></td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                </div>

                <?php if ($can_edit && $selected['schedule_status'] !== 'approved'): ?>
                  <div style="margin-top:1rem;">
                    <form method="POST" data-confirm="Submit this section's full schedule for coordinator approval? Editing will be locked until it's reviewed." data-icon="question">
                      <input type="hidden" name="section_id" value="<?= (int)$selected['section_id'] ?>">
                      <input type="hidden" name="semester" value="<?= $semester ?>">
                      <button type="submit" name="submit_schedule" class="btn-save-row" style="height:38px;padding:0 18px;">Submit Schedule for Approval</button>
                    </form>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

        </div>
      <?php endif; ?>

    </div>
  </div>

<script>
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('staffSidebar');
sidebarToggle?.addEventListener('click', () => sidebar.classList.toggle('open'));

// View-schedule modal: lock scroll while open, close on Escape
(() => {
  const modal = document.getElementById('viewScheduleModal');
  if (!modal) return;
  document.body.style.overflow = 'hidden';
  const closeHref = modal.querySelector('.modal-close')?.getAttribute('href');
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && closeHref) window.location.href = closeHref;
  });
})();

// Reject-with-reason: SWAL textarea (required), then submit
document.querySelectorAll('.js-reject').forEach(btn => {
  btn.addEventListener('click', () => {
    const form = btn.closest('.reject-form');
    const name = btn.dataset.name;
    Swal.fire({
      title: 'Reject schedule for "' + name + '"?',
      input: 'textarea',
      inputPlaceholder: 'Reason (required)',
      inputAttributes: { style: 'resize:vertical;max-width:100%;box-sizing:border-box;' },
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#C0392B',
      cancelButtonColor: '#aaa',
      confirmButtonText: 'Reject',
      cancelButtonText: 'Cancel',
      inputValidator: (value) => {
        if (!value || !value.trim()) return 'A reason is required.';
      }
    }).then((result) => {
      if (!result.isConfirmed) return;
      form.querySelector('input[name="rejection_reason"]').value = result.value.trim();
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'reject_schedule';
      hidden.value = '1';
      form.appendChild(hidden);
      form.submit();
    });
  });
});
</script>

</body>
</html>