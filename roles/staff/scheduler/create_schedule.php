<?php
/* ============================================================
   create_schedule.php — Create Schedule (guided, whole-section)
   ------------------------------------------------------------
   Unlike scheduling.php, this page is scheduler-only — coordinators
   only approve/reject via coordinator/approvals.php and are redirected
   away rather than getting a read-only view here.

   Unlike scheduling.php's row-by-row editor (one <form>/POST per
   subject), this page renders every subject of the selected section
   in ONE form and saves the whole set in a single POST, inside one
   transaction — all-or-nothing, so a conflict on one row never leaves
   the schedule half-saved. It reuses scheduling.php's exact
   conflict-check queries and, on a clean save, the exact same
   "submit for approval" transition (tracking ID + schedule_status)
   so both pages feed the identical approval pipeline in
   coordinator/approvals.php.
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

$is_scheduler = ($role === 'staff' && $department === 'scheduler');

if (!$is_scheduler) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

$valid_days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

$time_bounds = [];
for ($h = 7; $h <= 16; $h++) { $time_bounds[] = sprintf('%02d:30:00', $h); }
$start_options = array_slice($time_bounds, 0, -1);
$end_options   = array_slice($time_bounds, 1);

function format_time_label($t) {
    if (!$t) { return ''; }
    return date('g:i A', strtotime($t));
}

// ── Create (save + submit) a whole section's schedule in one go — either
//    for a section that already exists, or by creating a brand-new one
//    (pending coordinator review) at the same time as its schedule. ───────
$post_failed_rows   = null; // set below if create_schedule fails, to redisplay exactly what was typed
$conflict_row_index = null;

// Semester 1 keeps its original columns/prefix untouched; Semester 2 uses
// its own shadow columns and tracking-ID prefix so the two semesters'
// approval lifecycles never collide (see plan: sections.schedule_*_sem2).
function sched_col(string $base, int $semester): string {
    return $semester === 2 ? $base . '_sem2' : $base;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule'])) {
    $is_new_section = ($_POST['is_new_section'] ?? '') === '1';
    $section_id     = (int)($_POST['section_id'] ?? 0);
    $semester       = (int)($_POST['semester'] ?? 1);
    if (!in_array($semester, [1, 2], true)) { $semester = 1; }
    $needs_sem2_insert = ($_POST['needs_sem2_insert'] ?? '') === '1';

    $subj_ids   = $_POST['subject_id']  ?? [];
    $teacher_in = $_POST['teacher_id']  ?? [];
    $day_in     = $_POST['day']         ?? [];
    $start_in   = $_POST['start_time']  ?? [];
    $end_in     = $_POST['end_time']    ?? [];
    $room_in    = $_POST['room']        ?? [];

    if (!$is_scheduler) {
        $error = 'Only a scheduler can create a class schedule.';
    } elseif (empty($subj_ids)) {
        $error = 'This section has no subjects to schedule yet.';
    } elseif (!$is_new_section && !$section_id) {
        $error = 'Invalid section.';
    } elseif ($semester === 2 && $is_new_section) {
        $error = 'A brand-new section always starts with Semester 1.';
    } else {
        $sec_row = null;

        if (!$is_new_section) {
            $status_col = sched_col('schedule_status', $semester);
            $sec_stmt = $conn->prepare("SELECT room, $status_col AS schedule_status, section_name, school_year FROM sections WHERE section_id = ?");
            $sec_stmt->bind_param('i', $section_id);
            $sec_stmt->execute();
            $sec_row = $sec_stmt->get_result()->fetch_assoc();
            $sec_stmt->close();

            if (!$sec_row) {
                $error = 'Section not found.';
            } elseif (!in_array($sec_row['schedule_status'], ['unscheduled', 'rejected'], true)) {
                $error = 'This section\'s schedule has already been submitted, approved, or is locked. Use Class Scheduling to edit it instead.';
            } elseif ($semester === 2) {
                // Sem2 rows may not exist yet for this section — verify by
                // checking whether every Semester 1 subject already has one.
                $chk = $conn->prepare("
                    SELECT COUNT(*) AS c FROM section_subjects
                    WHERE section_id = ? AND semester = 2
                ");
                $chk->bind_param('i', $section_id);
                $chk->execute();
                $needs_sem2_insert = ((int)$chk->get_result()->fetch_assoc()['c']) === 0;
                $chk->close();
            }
        } else {
            // Brand-new section — validate the same fields sections.php's
            // own add_section handler requires before creating anything.
            $ns_strand   = trim($_POST['ns_strand'] ?? '');
            $ns_grade    = $_POST['ns_grade_level'] ?? '';
            $ns_sy       = trim($_POST['ns_school_year'] ?? '');
            $ns_name     = trim($_POST['ns_section_name'] ?? '');
            $ns_capacity = (int)($_POST['ns_capacity'] ?? 0);
            $ns_room     = null;

            $ns_valid_grades = ['11', '12'];
            $ns_valid_years  = [];
            $ns_sy_res = mysqli_query($conn, "SELECT school_year FROM school_year_settings ORDER BY school_year DESC");
            if ($ns_sy_res) { while ($r = mysqli_fetch_row($ns_sy_res)) { $ns_valid_years[] = $r[0]; } }

            if (!$ns_strand || !$ns_grade || !$ns_sy || !$ns_name) {
                $error = 'Strand, grade level, school year, and section name are all required to create a new section.';
            } elseif (!in_array($ns_grade, $ns_valid_grades, true)) {
                $error = 'Please select a valid grade level.';
            } elseif (!in_array($ns_sy, $ns_valid_years, true)) {
                $error = 'Please select a valid school year.';
            } elseif ($ns_capacity < 1) {
                $error = 'Capacity is required (at least 1) to create a new section.';
            } else {
                $ns_strand_id = strand_id($conn, $ns_strand);
                if (!$ns_strand_id) {
                    $error = 'Please select a valid strand.';
                } else {
                    // Someone else may have created this exact section since
                    // the page was loaded — re-check before inserting.
                    $recheck = $conn->prepare("
                        SELECT sec.section_id FROM sections sec
                        WHERE sec.is_active = 1 AND sec.strand = ? AND sec.grade_level = ? AND sec.school_year = ? AND sec.section_name = ?
                    ");
                    $recheck->bind_param('isss', $ns_strand_id, $ns_grade, $ns_sy, $ns_name);
                    $recheck->execute();
                    $dupe = $recheck->get_result()->fetch_assoc();
                    $recheck->close();

                    if ($dupe) {
                        $error = "A section named \"$ns_name\" already exists for that strand/grade/year now — search for it again.";
                    } else {
                        $sec_row = ['room' => null, 'schedule_status' => 'unscheduled', 'section_name' => $ns_name, 'school_year' => $ns_sy];
                    }
                }
            }
        }

        if ($sec_row && !$error) {
            $conn->begin_transaction();
            $conflict = null;

            if ($is_new_section) {
                $ins_sec = $conn->prepare(
                    "INSERT INTO sections
                        (section_name, grade_level, strand, room, capacity, school_year, is_active,
                         approval_status, requested_by, reviewed_by, reviewed_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', ?, NULL, NULL)"
                );
                $ins_sec->bind_param(
                    'ssisisi',
                    $ns_name, $ns_grade, $ns_strand_id, $ns_room, $ns_capacity, $ns_sy, $user_id
                );
                $ins_sec->execute();
                $section_id = $ins_sec->insert_id;
                $ins_sec->close();
            }

            // Subject names for every row up front — used both to prefix a
            // conflict message with the subject it belongs to, and (further
            // down) to redisplay the table with what was actually typed if
            // this submission fails.
            $subj_name_map = [];
            if (!empty($subj_ids)) {
                $ids_int = array_map('intval', $subj_ids);
                $ph = implode(',', array_fill(0, count($ids_int), '?'));
                $name_stmt = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE subject_id IN ($ph)");
                $name_stmt->bind_param(str_repeat('i', count($ids_int)), ...$ids_int);
                $name_stmt->execute();
                foreach ($name_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $nr) {
                    $subj_name_map[(int)$nr['subject_id']] = $nr['subject_name'];
                }
                $name_stmt->close();
            }

            foreach ($subj_ids as $i => $subject_id) {
                $subject_id   = (int)$subject_id;
                $subject_name = $subj_name_map[$subject_id] ?? ('Subject #' . $subject_id);
                $day        = $day_in[$i]   ?? '';
                $start_time = $start_in[$i] ?? '';
                $end_time   = $end_in[$i]   ?? '';
                $room_val   = trim($room_in[$i] ?? '');

                $teacher_id = ($teacher_in[$i] ?? '') !== '' ? (int)$teacher_in[$i] : null;

                if (!$day || !$start_time || !$end_time) {
                    $conflict = "\"$subject_name\": needs a day, start time, and end time before the schedule can be created.";
                    $conflict_row_index = $i;
                    break;
                }
                if (!in_array($day, $valid_days, true)) {
                    $conflict = "\"$subject_name\": invalid day.";
                    $conflict_row_index = $i;
                    break;
                }
                if ($start_time >= $end_time) {
                    $conflict = "\"$subject_name\": end time must be after start time.";
                    $conflict_row_index = $i;
                    break;
                }

                $effective_room = $room_val !== '' ? $room_val : ($sec_row['room'] ?? null);

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
                        $conflict = "\"$subject_name\": this teacher is already scheduled for \"{$trow['subject_name']}\" in {$trow['section_name']} at that day/time.";
                        $conflict_row_index = $i;
                        break;
                    }
                }

                if ($effective_room) {
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
                        $conflict = "\"$subject_name\": room \"{$effective_room}\" is already booked for \"{$rrow['subject_name']}\" ({$rrow['section_name']}) at that day/time.";
                        $conflict_row_index = $i;
                        break;
                    }
                }

                $room_param = $room_val !== '' ? $room_val : null;
                if ($is_new_section || ($semester === 2 && $needs_sem2_insert)) {
                    $up = $conn->prepare("
                        INSERT INTO section_subjects (section_id, subject_id, teacher_id, day, start_time, end_time, room, semester)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $up->bind_param('iiissssi', $section_id, $subject_id, $teacher_id, $day, $start_time, $end_time, $room_param, $semester);
                } else {
                    $up = $conn->prepare("
                        UPDATE section_subjects
                        SET teacher_id = ?, day = ?, start_time = ?, end_time = ?, room = ?
                        WHERE section_id = ? AND subject_id = ? AND semester = ?
                    ");
                    $up->bind_param('issssiii', $teacher_id, $day, $start_time, $end_time, $room_param, $section_id, $subject_id, $semester);
                }
                $up->execute();
                $up->close();

                // Keep teacher_assignments in sync (see sections.php /
                // scheduling.php — same pattern, no unique key to rely on).
                // Scoped by semester so Sem1 and Sem2 each keep their own
                // assignment row even when they share a teacher_id.
                //
                // Read the prior teacher for this exact slot first — this
                // whole delete+insert re-runs on every save even when
                // nothing changed, so a "you've been assigned" notification
                // below only fires when the teacher on this slot is
                // actually new/different, not on every re-save.
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
            }

            if ($conflict) {
                $conn->rollback();
                $error = $conflict;
            } else {
                // Same tracking-ID + status transition as scheduling.php's
                // submit_schedule handler, so this feeds the identical
                // approval pipeline. Semester 2 uses its own shadow columns
                // and a distinct "SCH2-" prefix so the two sequences never collide.
                $req_id_col = sched_col('schedule_request_id', $semester);
                $status_col = sched_col('schedule_status', $semester);
                $sub_by_col = sched_col('schedule_submitted_by', $semester);
                $sub_at_col = sched_col('schedule_submitted_at', $semester);
                $rej_col    = sched_col('schedule_rejection_reason', $semester);

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

                $request_id = $cn_prefix . str_pad((string)$next_seq, 4, '0', STR_PAD_LEFT);
                $rid_stmt = $conn->prepare(
                    "UPDATE sections
                     SET $req_id_col=?, $status_col='pending', $sub_by_col=?, $sub_at_col=NOW(), $rej_col=NULL
                     WHERE section_id=?"
                );
                $rid_stmt->bind_param('sii', $request_id, $user_id, $section_id);
                $rid_stmt->execute();
                $rid_stmt->close();

                $conn->commit();
                $notify_msg = $is_new_section
                    ? "New section \"{$sec_row['section_name']}\" and its schedule are awaiting approval."
                    : "Schedule for section \"{$sec_row['section_name']}\" awaiting approval.";
                notify_coordinators($conn, $notify_msg, 'scheduler/scheduling?status=pending');
                header("Location: create_schedule?created=1&semester=$semester"); exit();
            }
        }
    }

    // On any failure, rebuild the table from what was actually typed —
    // instead of losing it all when the page below re-derives a fresh
    // (blank, for a new section) or last-saved (for an existing one) set.
    if ($error !== '' && !empty($subj_ids)) {
        $pf_ids = array_map('intval', $subj_ids);
        $pf_names = [];
        $ph = implode(',', array_fill(0, count($pf_ids), '?'));
        $pf_stmt = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE subject_id IN ($ph)");
        $pf_stmt->bind_param(str_repeat('i', count($pf_ids)), ...$pf_ids);
        $pf_stmt->execute();
        foreach ($pf_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $nr) {
            $pf_names[(int)$nr['subject_id']] = $nr['subject_name'];
        }
        $pf_stmt->close();

        $post_failed_rows = [];
        foreach ($subj_ids as $i => $sid) {
            $sid = (int)$sid;
            $post_failed_rows[] = [
                'subject_id'      => $sid,
                'subject_name'    => $pf_names[$sid] ?? ('Subject #' . $sid),
                'teacher_id'      => ($teacher_in[$i] ?? '') !== '' ? (int)$teacher_in[$i] : null,
                'day'             => $day_in[$i]   ?? null,
                'start_time'      => $start_in[$i] ?? null,
                'end_time'        => $end_in[$i]   ?? null,
                'room'            => $room_in[$i]  ?? null,
                'is_conflict_row' => ($conflict_row_index !== null && (int)$i === (int)$conflict_row_index),
            ];
        }
    }
}

if (isset($_GET['created'])) {
    $success = 'Schedule created and submitted for coordinator approval.';
}

$all_strands        = mysqli_query($conn, "SELECT strand_code FROM strands ORDER BY strand_code")->fetch_all(MYSQLI_ASSOC);
$valid_grade_levels = ['11', '12'];
$valid_school_years = [];
$sy_res = mysqli_query($conn, "SELECT school_year FROM school_year_settings ORDER BY school_year DESC");
if ($sy_res) { while ($r = mysqli_fetch_row($sy_res)) { $valid_school_years[] = $r[0]; } }

// ── Load step — Strand/Grade/School Year/Section Name (GET, read-only).
//    A match loads the section's real schedule; no match previews the
//    curriculum's eligible subjects with blank fields, ready to fill in —
//    nothing is written to the database until "Create Schedule" is submitted.
$f_strand   = trim($_GET['strand'] ?? '');
$f_grade    = in_array($_GET['grade_level'] ?? '', $valid_grade_levels, true) ? $_GET['grade_level'] : '';
$f_sy       = trim($_GET['school_year'] ?? '');
$f_name     = trim($_GET['section_name'] ?? '');
$f_capacity = trim($_GET['capacity'] ?? '');
$g_semester = (int)($_GET['semester'] ?? 1);
if (!in_array($g_semester, [1, 2], true)) { $g_semester = 1; }
$lookup_attempted = ($f_strand !== '' && $f_grade !== '' && $f_sy !== '' && $f_name !== '');

$selected          = null;
$subject_rows      = [];
$is_new_section    = false;
$needs_sem2_insert = false;

if ($lookup_attempted) {
    $g_status_col = sched_col('schedule_status', $g_semester);
    $sel_stmt = $conn->prepare("
        SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.room, sec.school_year, sec.$g_status_col AS schedule_status
        FROM sections sec
        JOIN strands st ON st.strand_id = sec.strand
        WHERE sec.is_active = 1 AND st.strand_code = ? AND sec.grade_level = ? AND sec.school_year = ? AND sec.section_name = ?
    ");
    $sel_stmt->bind_param('ssss', $f_strand, $f_grade, $f_sy, $f_name);
    $sel_stmt->execute();
    $selected = $sel_stmt->get_result()->fetch_assoc() ?: null;
    $sel_stmt->close();

    if ($selected && $g_semester === 2) {
        $ss_stmt = $conn->prepare("
            SELECT ss.subject_id, sub.subject_name, ss.day, ss.start_time, ss.end_time, ss.room, ss.teacher_id
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            WHERE ss.section_id = ? AND ss.semester = 2
            ORDER BY sub.subject_name
        ");
        $ss_stmt->bind_param('i', $selected['section_id']);
        $ss_stmt->execute();
        $subject_rows = $ss_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ss_stmt->close();

        if (empty($subject_rows)) {
            // No Semester 2 rows yet — preview Semester 1's subjects with
            // each one's teacher prefilled as a starting default (still an
            // editable dropdown) and day/time/room blank, ready to fill in.
            $s1_stmt = $conn->prepare("
                SELECT ss.subject_id, sub.subject_name, ss.teacher_id, ss.day, ss.start_time, ss.end_time, ss.room
                FROM section_subjects ss
                JOIN subjects sub ON sub.subject_id = ss.subject_id
                WHERE ss.section_id = ? AND ss.semester = 1
                ORDER BY sub.subject_name
            ");
            $s1_stmt->bind_param('i', $selected['section_id']);
            $s1_stmt->execute();
            $s1_rows = $s1_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $s1_stmt->close();

            foreach ($s1_rows as $s1) {
                $subject_rows[] = [
                    'subject_id'   => $s1['subject_id'],
                    'subject_name' => $s1['subject_name'],
                    'day'          => null,
                    'start_time'   => null,
                    'end_time'     => null,
                    'room'         => null,
                    'teacher_id'   => $s1['teacher_id'],
                    's1_day'       => $s1['day'],
                    's1_start'     => $s1['start_time'],
                    's1_end'       => $s1['end_time'],
                    's1_room'      => $s1['room'],
                ];
            }
            $needs_sem2_insert = true;
        }
    } elseif ($selected) {
        $ss_stmt = $conn->prepare("
            SELECT ss.subject_id, sub.subject_name, ss.day, ss.start_time, ss.end_time, ss.room, ss.teacher_id
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            WHERE ss.section_id = ? AND ss.semester = 1
            ORDER BY sub.subject_name
        ");
        $ss_stmt->bind_param('i', $selected['section_id']);
        $ss_stmt->execute();
        $subject_rows = $ss_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ss_stmt->close();
    } elseif ($g_semester === 1) {
        // No existing section — preview the curriculum's eligible subjects
        // for this strand/grade/school year, same eligibility rule as
        // sections.php's populate_section_subjects(), with blank fields.
        $f_strand_id = strand_id($conn, $f_strand);
        $core_id     = strand_id($conn, 'CORE');
        if ($f_strand_id) {
            $cur_stmt = $conn->prepare("
                SELECT subject_id, subject_name
                FROM subjects
                WHERE grade_level = ? AND strand IN (?, ?) AND school_year = ? AND is_active = 1 AND review_status = 'approved'
                ORDER BY subject_name
            ");
            $cur_stmt->bind_param('siis', $f_grade, $f_strand_id, $core_id, $f_sy);
            $cur_stmt->execute();
            $curriculum_subjects = $cur_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $cur_stmt->close();

            foreach ($curriculum_subjects as $cs) {
                $subject_rows[] = [
                    'subject_id'   => $cs['subject_id'],
                    'subject_name' => $cs['subject_name'],
                    'day'          => null,
                    'start_time'   => null,
                    'end_time'     => null,
                    'room'         => null,
                    'teacher_id'   => null,
                ];
            }
        }
        $is_new_section = !empty($subject_rows);
    }
    // $g_semester === 2 && !$selected: section doesn't exist yet — Semester 2
    // can't be scheduled ahead of the section itself; $subject_rows stays
    // empty and the view below shows a dedicated hint for this case.
}

// A just-failed submission always wins over the freshly-loaded values —
// keep exactly what the scheduler typed instead of resetting the table.
if ($post_failed_rows !== null) {
    $subject_rows = $post_failed_rows;
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
  <title>Create Schedule — Scheduler</title>
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
    @media (max-width: 900px) { .sched-table { min-width:700px; } }
    .sched-subject { font-size:13px; font-weight:500; color:#1A1A2E; }
    .empty-hint { color:#8A8A9A; font-size:13px; padding:2rem 0; text-align:center; }

    tr.conflict-row { background:#FDF0EF; }
    tr.conflict-row td { border-top:1px solid #F1A9A0; border-bottom:1px solid #F1A9A0; }
    .conflict-tag { display:inline-block; font-size:10px; font-weight:600; color:#C0392B;
      background:#FDF0EF; border-radius:20px; padding:2px 8px; margin-top:3px; }

    .badge { display:inline-block; font-size:10px; font-weight:600; text-transform:uppercase;
      letter-spacing:.03em; border-radius:20px; padding:2px 9px; white-space:nowrap; }
    .badge-unscheduled { background:#F5F5F7; color:#8A8A9A; }
    .badge-rejected    { background:#FDF0EF; color:#C0392B; }

    .btn-create {
      height:38px; padding:0 18px; background:var(--brand-primary); border:none;
      border-radius:6px; color:#fff; font-size:13px; font-weight:600; cursor:pointer;
      font-family:inherit;
    }
    .btn-create:hover { background:var(--brand-primary-hover); }

    .form-group { margin-bottom:.85rem; }
    .form-group label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem; }
    .form-group input, .form-group select {
      width:100%; height:38px; border:0.5px solid #D4D4E0; box-sizing:border-box;
      border-radius:8px; background:#FAFAFC; padding:0 12px; font-size:13px;
      font-family:inherit; color:#1A1A2E; outline:none;
    }
    .form-group input:focus, .form-group select:focus {
      border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); background:#fff;
    }
    .sem-toggle { display:flex; gap:8px; }
    .sem-toggle label {
      position:relative; display:inline-flex; align-items:center; justify-content:center;
      height:28px; box-sizing:border-box; line-height:1; margin:0;
      font-size:12px; font-weight:500; text-transform:none; letter-spacing:normal;
      padding:0 12px; border-radius:20px; border:0.5px solid #D4D4E0;
      background:#fff; color:#5A5A72; cursor:pointer; transition:all .12s;
    }
    .sem-toggle label:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .sem-toggle input[type=radio] { position:absolute; opacity:0; pointer-events:none; width:0; height:0; }
    .sem-toggle label:has(input:checked) {
      background:var(--brand-primary); border-color:var(--brand-primary); color:#fff;
    }

    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72;
    }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
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
          Create Schedule
          <span class="staff-topbar-subtitle">Build a section's whole schedule in one pass</span>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <p class="page-eyebrow">Scheduler Portal</p>
      <h1 class="page-title">Create Schedule</h1>
      <p class="page-sub">Pick a section and assign every subject's teacher, day, time, and room at once, then submit the whole schedule for approval in one step.</p>

      <?php if ($success): ?><div class="notice notice-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="two-col">

        <!-- Find a section — manual entry, not a picked-from-list reuse -->
        <div class="panel">
          <div class="panel-sub">Find a Section</div>
          <p class="td-meta" style="margin:.3rem 0 .9rem;">Choose the strand, grade level, and school year, then type the section name. If it already exists, its schedule loads on the right — otherwise you'll see every curriculum subject for that strand/grade/year ready to set up.</p>
          <form method="GET" class="lookup-form-col">
            <div class="form-group">
              <label>Semester</label>
              <div class="sem-toggle">
                <label>
                  <input type="radio" name="semester" value="1" <?= $g_semester === 1 ? 'checked' : '' ?>>
                  <span>Semester 1</span>
                </label>
                <label>
                  <input type="radio" name="semester" value="2" <?= $g_semester === 2 ? 'checked' : '' ?>>
                  <span>Semester 2</span>
                </label>
              </div>
            </div>
            <div class="form-group">
              <label for="f_strand">Strand</label>
              <select id="f_strand" name="strand" required>
                <option value="">— Select —</option>
                <?php foreach ($all_strands as $st): ?>
                  <option value="<?= htmlspecialchars($st['strand_code']) ?>" <?= $f_strand === $st['strand_code'] ? 'selected' : '' ?>><?= htmlspecialchars($st['strand_code']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="f_grade_level">Grade Level</label>
              <select id="f_grade_level" name="grade_level" required>
                <option value="">— Select —</option>
                <?php foreach ($valid_grade_levels as $g): ?>
                  <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="f_school_year">School Year</label>
              <select id="f_school_year" name="school_year" required>
                <option value="">— Select —</option>
                <?php foreach ($valid_school_years as $sy): ?>
                  <option value="<?= htmlspecialchars($sy) ?>" <?= $f_sy === $sy ? 'selected' : '' ?>><?= htmlspecialchars($sy) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="f_section_name">Section Name</label>
              <input id="f_section_name" type="text" name="section_name" placeholder="e.g. STEM 12-1" value="<?= htmlspecialchars($f_name) ?>" required>
            </div>
            <div class="form-group">
              <label for="f_capacity">Capacity <span style="font-weight:400;text-transform:none;">(only needed if creating new)</span></label>
              <input id="f_capacity" type="number" name="capacity" min="1" max="999" placeholder="e.g. 40" value="<?= htmlspecialchars($f_capacity) ?>">
            </div>
            <button type="submit" class="btn-create" style="width:100%;">Find Section</button>
          </form>
        </div>

        <!-- Schedule builder -->
        <div class="panel">
          <?php if (!$lookup_attempted): ?>
            <p class="empty-hint">Search for a section on the left to build its schedule.</p>
          <?php elseif ($g_semester === 2 && !$selected): ?>
            <p class="empty-hint">That section doesn't exist yet — create its Semester 1 schedule first.</p>
          <?php elseif (empty($subject_rows)): ?>
            <p class="empty-hint">
              <?= $selected
                    ? ($g_semester === 2
                        ? 'This section has no Semester 1 subjects yet — set up Semester 1 first.'
                        : 'This section has no subjects yet — check curriculum approval for this grade/strand.')
                    : 'No curriculum subjects found for that strand, grade level, and school year — check curriculum approval first.' ?>
            </p>
          <?php else: ?>
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.9rem;">
              <span class="panel-sub" style="margin:0;">
                <?= htmlspecialchars($selected ? $selected['section_name'] : $f_name) ?> &middot; <?= htmlspecialchars($f_sy) ?> &middot; Semester <?= $g_semester ?>
              </span>
              <?php if ($selected): ?>
                <span class="badge badge-<?= $selected['schedule_status'] ?>"><?= ucfirst($selected['schedule_status']) ?></span>
              <?php else: ?>
                <span class="badge badge-unscheduled">New Section</span>
              <?php endif; ?>
            </div>

            <form method="POST" data-confirm="Create and submit the Semester <?= $g_semester ?> schedule for &quot;<?= htmlspecialchars($selected ? $selected['section_name'] : $f_name, ENT_QUOTES) ?>&quot;? It will be sent to the coordinator for approval." data-icon="question">
              <input type="hidden" name="semester" value="<?= $g_semester ?>">
              <?php if ($selected): ?>
                <input type="hidden" name="is_new_section" value="0">
                <input type="hidden" name="section_id" value="<?= (int)$selected['section_id'] ?>">
                <input type="hidden" name="needs_sem2_insert" value="<?= $needs_sem2_insert ? '1' : '0' ?>">
              <?php else: ?>
                <input type="hidden" name="is_new_section" value="1">
                <input type="hidden" name="ns_strand" value="<?= htmlspecialchars($f_strand) ?>">
                <input type="hidden" name="ns_grade_level" value="<?= htmlspecialchars($f_grade) ?>">
                <input type="hidden" name="ns_school_year" value="<?= htmlspecialchars($f_sy) ?>">
                <input type="hidden" name="ns_section_name" value="<?= htmlspecialchars($f_name) ?>">
                <input type="hidden" name="ns_capacity" value="<?= htmlspecialchars($f_capacity) ?>">
              <?php endif; ?>
              <?php if ($g_semester === 2 && $needs_sem2_insert && !empty($subject_rows)): ?>
                <div style="margin-bottom:.75rem;">
                  <button type="button" id="copySem1Btn" class="btn-toggle" style="height:32px;padding:0 12px;">Copy Semester 1 times</button>
                </div>
              <?php endif; ?>
              <div class="sched-table-scroll">
                <table class="sched-table">
                  <colgroup>
                    <col style="width:22%"><col style="width:24%"><col style="width:11%">
                    <col style="width:13%"><col style="width:13%"><col style="width:17%">
                  </colgroup>
                  <thead>
                    <tr><th>Subject</th><th>Teacher</th><th>Day</th><th>Start</th><th>End</th><th>Room</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($subject_rows as $row): $is_conflict_row = $row['is_conflict_row'] ?? false; ?>
                      <tr class="<?= $is_conflict_row ? 'conflict-row' : '' ?>"
                          data-s1-day="<?= htmlspecialchars($row['s1_day'] ?? '') ?>"
                          data-s1-start="<?= htmlspecialchars($row['s1_start'] ?? '') ?>"
                          data-s1-end="<?= htmlspecialchars($row['s1_end'] ?? '') ?>"
                          data-s1-room="<?= htmlspecialchars($row['s1_room'] ?? '') ?>">
                        <td>
                          <div class="sched-subject"><?= htmlspecialchars($row['subject_name']) ?></div>
                          <?php if ($is_conflict_row): ?>
                            <span class="conflict-tag">⚠ Conflict</span>
                          <?php endif; ?>
                          <input type="hidden" name="subject_id[]" value="<?= (int)$row['subject_id'] ?>">
                        </td>
                        <td>
                          <select name="teacher_id[]" <?= $is_scheduler ? '' : 'disabled' ?>>
                            <option value="">— Select —</option>
                            <?php foreach ($teachers as $t): ?>
                              <option value="<?= (int)$t['teacher_id'] ?>" <?= (int)$row['teacher_id'] === (int)$t['teacher_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <select name="day[]" <?= $is_scheduler ? '' : 'disabled' ?>>
                            <option value="">— Select —</option>
                            <?php foreach ($valid_days as $d): ?>
                              <option value="<?= $d ?>" <?= $row['day'] === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <select name="start_time[]" <?= $is_scheduler ? '' : 'disabled' ?>>
                            <option value="">— Select —</option>
                            <?php foreach ($start_options as $t): ?>
                              <option value="<?= $t ?>" <?= $row['start_time'] === $t ? 'selected' : '' ?>><?= format_time_label($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <select name="end_time[]" <?= $is_scheduler ? '' : 'disabled' ?>>
                            <option value="">— Select —</option>
                            <?php foreach ($end_options as $t): ?>
                              <option value="<?= $t ?>" <?= $row['end_time'] === $t ? 'selected' : '' ?>><?= format_time_label($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <input type="text" name="room[]" placeholder="<?= htmlspecialchars(($selected['room'] ?? '') ?: 'Homeroom') ?>" value="<?= htmlspecialchars($row['room'] ?? '') ?>" <?= $is_scheduler ? '' : 'disabled' ?>>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php if ($is_scheduler): ?>
                <div style="margin-top:1rem;">
                  <button type="submit" name="create_schedule" class="btn-create">Create Schedule</button>
                </div>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </div>

      </div>

    </div>
  </div>

<script>
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('staffSidebar');
sidebarToggle?.addEventListener('click', () => sidebar.classList.toggle('open'));

document.getElementById('copySem1Btn')?.addEventListener('click', () => {
  document.querySelectorAll('.sched-table tbody tr').forEach(row => {
    const daySel   = row.querySelector('select[name="day[]"]');
    const startSel = row.querySelector('select[name="start_time[]"]');
    const endSel   = row.querySelector('select[name="end_time[]"]');
    const roomInp  = row.querySelector('input[name="room[]"]');
    if (daySel)   { daySel.value   = row.dataset.s1Day   || ''; }
    if (startSel) { startSel.value = row.dataset.s1Start || ''; }
    if (endSel)   { endSel.value   = row.dataset.s1End   || ''; }
    if (roomInp)  { roomInp.value  = row.dataset.s1Room  || ''; }
  });
});
</script>

</body>
</html>
