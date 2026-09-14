<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

// ── Auth guard ─────────────────────────────────────────────────────────────
require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// Pull forward any errors stashed by a prior redirect_wizard() call (PRG
// pattern loses local variables across the redirect, so errors have to
// round-trip through the session instead — otherwise a blocked action
// just silently reloads the same step with no explanation).
if (!empty($_SESSION['enroll_wizard_errors'])) {
    $errors = $_SESSION['enroll_wizard_errors'];
    unset($_SESSION['enroll_wizard_errors']);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('enrollment.php requires a mysqli connection ($conn) from config.php.');
}

function redirect_wizard(): void
{
    global $errors;
    if ($errors) {
        $_SESSION['enroll_wizard_errors'] = $errors;
    }
    header('Location: enrollment');
    exit;
}

/* ─── Small mysqli helpers ───────────────────────────────────────────── */

function db_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function db_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function db_execute(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $insertId = $stmt->insert_id;
    $stmt->close();
    return $insertId;
}

function db_affected(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

// Cycle pulled from school_year_settings. Prefer whichever cycle is
// actually open right now; updated_at is only a tiebreaker, not the
// primary signal — a closed cycle can still have the newest updated_at
// (e.g. an admin flips it closed today), which previously caused the
// wrong school_year to be picked here.
$cycle = db_fetch_one(
    $conn,
    "SELECT school_year, is_admission_open, is_enrollment_open
     FROM school_year_settings
     ORDER BY is_enrollment_open DESC, is_admission_open DESC, updated_at DESC
     LIMIT 1"
);
$enrollOpen = $cycle ? (bool)$cycle['is_enrollment_open'] : false;

/* enrollments.admission_strand can hold 'TVL-ICT' but sections.strand /
   subjects.strand only ever hold 'TVL' (confirmed against strands lookup
   data: id 6 = TVL-ICT is never used by subjects/sections). Translation
   point until the schema is reconciled. */
function curriculum_strand(string $enrollStrand): string
{
    return $enrollStrand === 'TVL-ICT' ? 'TVL' : $enrollStrand;
}

const ENROLL_STRANDS = ['STEM', 'HUMSS', 'ABM', 'GAS', 'TVL-ICT'];

// Collapses an enrollments.status value (or NULL, meaning the student has
// no enrollment row at all for the current cycle yet) down to the four
// buckets the Step 1 directory pills filter on. 'expired' rides along with
// 'cancelled' since neither represents an active claim on a seat.
function directory_status_bucket(?string $status): string
{
    return match ($status) {
        'pre_enrolled' => 'pre_enrolled',
        'enrolled'     => 'enrolled',
        'cancelled', 'expired' => 'cancelled',
        default        => 'pending', // NULL (no row yet) or 'pending'
    };
}

if (!isset($_SESSION['enroll_wizard'])) {
    $_SESSION['enroll_wizard'] = [
        'step'                  => 1,
        'max_step_reached'      => 1,
        'enrollment_id'         => null, // claimed admission-stage row, if any
        'student_id'            => null,
        'student_number'        => null,
        'student_name'          => null,
        'is_new_student'        => null,
        'is_transferee'         => null,
        'prev_completion_status' => null,
        'jhs_is_public'         => null,
        'school_year'           => null,
        'grade_level'           => null,
        'strand'                => null,
        'prev_strand'           => null,
        'jhs_school'            => null,
        'evaluation_type'       => null, // no_previous | continue_normally | needs_crediting | strand_shift
        'credited_subject_ids'  => [],
        'back_subject_ids'      => [],
        'back_subject_sections' => [],   // subject_id => section_id
        'section_id'            => null,
        'finalized'             => false,
        'resumed_pre_enrollment' => false, // re-entered wizard for an already pre_enrolled (unpaid) record
        'is_semester2_pass'     => false, // re-entered wizard for an already-enrolled student's pending Semester 2 continuation
    ];
}
$wizard = &$_SESSION['enroll_wizard'];

/* ─── Helpers ────────────────────────────────────────────────────────── */

// SHS students enroll once per school year and take all 8 subjects for
// that grade level (4 CORE + 4 strand-specific) — no semester split.
function subjects_for_curriculum(mysqli $conn, string $gradeLevel, string $strand): array
{
    $coreId   = strand_id($conn, 'CORE');
    $strandId = strand_id($conn, $strand);
    return db_fetch_all(
        $conn,
        "SELECT sub.subject_id, sub.subject_name, st.strand_code AS strand
         FROM subjects sub
         JOIN strands st ON st.strand_id = sub.strand
         WHERE sub.grade_level = ? AND sub.strand IN (?, ?) AND sub.is_active = 1
         ORDER BY FIELD(sub.strand, ?, ?), sub.subject_name",
        'siiii',
        [$gradeLevel, $coreId, $strandId, $coreId, $strandId]
    );
}

// Maps a grade level to the immediately preceding one in the SHS sequence,
// for the "back subjects" pool. G11 has no prior grade level.
function prior_term(string $gradeLevel): ?array
{
    return $gradeLevel === '12' ? ['11'] : null;
}

// Step 3 is now fully automatic — no manual radio choice. A student needs
// subject validation if: they're a transferee (external records, always
// checked); a returning student who changed strand (strand shifter); or a
// returning student whose most recently completed cycle's academic_status
// was 'failed'. New G11 students, and returning students who passed and
// aren't shifting strand, are "Regular" — no validation, no crediting, no
// back subjects. ('in_progress' — a fresh record straight out of
// admission.php with nothing finalized yet — only ever shows up for a
// brand-new admission claim, which is excluded from this lookup already;
// it never overrides an actual returning student's real passed/failed
// outcome.)
function wizard_needs_validation(array $wizard): bool
{
    if ($wizard['is_transferee']) {
        return true;
    }
    if (wizard_is_returning_in_system($wizard)) {
        if (wizard_is_strand_change($wizard)) {
            return true;
        }
        return $wizard['prev_completion_status'] === 'failed';
    }
    return false; // new student
}

function wizard_is_strand_change(array $wizard): bool
{
    return $wizard['grade_level'] === '12'
        && $wizard['prev_strand']
        && $wizard['strand'] !== $wizard['prev_strand'];
}

function wizard_is_returning_in_system(array $wizard): bool
{
    return !$wizard['is_new_student'] && !$wizard['is_transferee'];
}

// Automatic evaluation for a RETURNING (non-transferee) student flagged as
// needing validation — no manual checklist toggling. A strand shifter only
// ever has the prior term's CORE subjects in play (the old strand's
// specialized subjects are dropped entirely: they don't map onto the new
// strand and aren't owed as back subjects either way). A non-shifter is
// re-evaluated against their own full prior-term curriculum. Either way,
// credited vs. back is read straight from this student's own
// subject_completion_records — 'completed' -> credited, anything else
// (not_completed, or no record at all) -> back subject owed.
function auto_evaluate_returning(mysqli $conn, array $wizard): array
{
    $prior = prior_term($wizard['grade_level']);
    if (!$prior) {
        return ['credited' => [], 'back' => []];
    }

    $priorAll = subjects_for_curriculum($conn, $prior[0], curriculum_strand($wizard['strand']));
    if (wizard_is_strand_change($wizard)) {
        $priorAll = array_filter($priorAll, fn($s) => $s['strand'] === 'CORE');
    }
    $priorIds = array_map('intval', array_column($priorAll, 'subject_id'));
    if (!$priorIds) {
        return ['credited' => [], 'back' => []];
    }

    $placeholders = implode(',', array_fill(0, count($priorIds), '?'));
    $rows = db_fetch_all(
        $conn,
        "SELECT subject_id, status FROM subject_completion_records
         WHERE student_id = ? AND subject_id IN ($placeholders)",
        'i' . str_repeat('i', count($priorIds)),
        array_merge([$wizard['student_id']], $priorIds)
    );
    $statusById = [];
    foreach ($rows as $r) {
        $statusById[(int)$r['subject_id']] = $r['status'];
    }

    $credited = [];
    $back = [];
    foreach ($priorIds as $sid) {
        if (($statusById[$sid] ?? null) === 'completed') {
            $credited[] = $sid;
        } else {
            $back[] = $sid;
        }
    }
    return ['credited' => $credited, 'back' => $back];
}

// Simple same-day interval overlap check. Times are 'HH:MM:SS' strings,
// which compare correctly as plain strings.
function times_overlap(string $dayA, string $startA, string $endA, string $dayB, string $startB, string $endB): bool
{
    if ($dayA !== $dayB) return false;
    return ($startA < $endB) && ($startB < $endA);
}

// Day/time/room/teacher for one subject within one section — used by the
// Review step to show a real schedule before the student commits (finalize
// hasn't run yet, so there's no enrollment_subjects row to join against).
function subject_schedule_text(mysqli $conn, int $sectionId, int $subjectId): string
{
    $rows = db_fetch_all(
        $conn,
        "SELECT ss.day, ss.start_time, ss.end_time, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name,
                COALESCE(ss.room, sec.room) AS room
         FROM section_subjects ss
         JOIN sections sec ON sec.section_id = ss.section_id
         LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
         WHERE ss.section_id = ? AND ss.subject_id = ?",
        'ii',
        [$sectionId, $subjectId]
    );
    if (!$rows) return '—';
    $days = implode(', ', array_map(
        fn($r) => $r['day'] . ' ' . substr($r['start_time'], 0, 5) . '–' . substr($r['end_time'], 0, 5),
        array_filter($rows, fn($r) => $r['day'])
    ));
    $extra = trim(($rows[0]['room'] ? 'Room ' . $rows[0]['room'] : '') . ($rows[0]['teacher_name'] ? ' · ' . $rows[0]['teacher_name'] : ''), ' ·');
    return trim(($days ?: 'No schedule set') . ($extra ? ' — ' . $extra : ''));
}

// All day/start/end slots a section teaches — one schedule, good for the
// whole school year.
function section_schedule_slots(mysqli $conn, int $sectionId): array
{
    return db_fetch_all(
        $conn,
        "SELECT ss.subject_id, ss.day, ss.start_time, ss.end_time
         FROM section_subjects ss
         WHERE ss.section_id = ? AND ss.day IS NOT NULL",
        'i',
        [$sectionId]
    );
}

/* ─── Handle POST actions (PRG pattern) ──────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'reset_wizard') {
        unset($_SESSION['enroll_wizard']);
        redirect_wizard();

    } elseif ($action === 'go_to_step') {
        $target = filter_input(INPUT_POST, 'target_step', FILTER_VALIDATE_INT);
        // Step 5 (Summary) is a landing page, not something to freely
        // re-enter once finalized without going through Step 4 again.
        if ($target && $target >= 1 && $target <= ($wizard['max_step_reached'] ?? 1)) {
            $wizard['step'] = $target;
        }
        redirect_wizard();

    } elseif ($action === 'select_student') {
        $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

        if (!$studentId) {
            $errors[] = 'Invalid student selection.';
        } else {
            $student = db_fetch_one(
                $conn,
                "SELECT st.student_id, st.student_number, st.family_name, st.given_name, st.middle_name,
                        st.suffix, st.admission_status, st.is_transferee,
                        se.is_public AS jhs_is_public, se.school_name AS jhs_school
                 FROM students st
                 LEFT JOIN student_education se ON se.student_id = st.student_id
                 WHERE st.student_id = ?",
                'i',
                [$studentId]
            );

            if (!$student) {
                $errors[] = 'Student record not found. It may have been removed.';
            } elseif ($student['admission_status'] !== 'admitted') {
                $errors[] = 'This student has not completed admission requirements yet and cannot be enrolled.';
            } elseif (!$cycle) {
                $errors[] = 'No school year is configured yet. Ask an admin to set one up.';
            } else {
                // Only a fully 'enrolled' record (paid at least the first
                // quarter, per Treasury) truly locks a student out.
                // 'pre_enrolled' just means registered-but-unpaid — the
                // section (and back-subject classes) can still change, so
                // that case resumes the wizard below instead of blocking.
                // A not-yet-approved Semester 2 continuation is the one
                // exception to the 'enrolled' block: an enrolled Grade
                // 11/12 student who clears the grade/accountability/balance
                // gate is routed straight back into this same wizard
                // (Section + Review only) — there is no separate student-
                // facing request step anymore, the registrar initiates it.
                $enrolledThisYear = db_fetch_one(
                    $conn,
                    "SELECT e.enrollment_id, e.section_id, e.admission_grade_level AS grade_level,
                            e.school_year, st.strand_code AS strand, e.is_new_student, e.semester2_status
                     FROM enrollments e
                     JOIN strands st ON st.strand_id = e.admission_strand
                     WHERE e.student_id = ? AND e.school_year = ? AND e.status = 'enrolled'",
                    'is',
                    [$studentId, $cycle['school_year']]
                );
                $semester2Pass = false;
                $alreadySem2 = $enrolledThisYear && $enrolledThisYear['semester2_status'] === 'approved';
                if ($enrolledThisYear && $alreadySem2) {
                    $errors[] = 'This student is already enrolled and paid for the current school year. Section or subject changes now go through the Registrar, not this wizard.';
                } elseif ($enrolledThisYear && !in_array($enrolledThisYear['grade_level'], ['11', '12'], true)) {
                    $errors[] = 'This student is already enrolled and paid for the current school year. Section or subject changes now go through the Registrar, not this wizard.';
                } elseif ($enrolledThisYear && !$enrolledThisYear['section_id']) {
                    $errors[] = 'This student\'s section assignment isn\'t finalized yet.';
                } elseif ($enrolledThisYear) {
                    $failing = semester1_failing_subjects($conn, $studentId, (int) $enrolledThisYear['section_id'], $enrolledThisYear['school_year']);
                    if (!empty($failing)) {
                        $errors[] = 'This student has a failing Semester 1 grade in: '
                            . implode(', ', array_map(fn($f) => $f['subject_name'], $failing))
                            . '. Semester 2 enrollment is not allowed.';
                    } elseif (has_outstanding_accountabilities($conn, (int) $enrolledThisYear['enrollment_id'], (int) $student['jhs_is_public'])) {
                        $errors[] = 'This student has outstanding requirements on file. They must be resolved (see Accountabilities) before Semester 2 can proceed.';
                    } elseif (has_outstanding_balance($conn, (int) $enrolledThisYear['enrollment_id'])) {
                        $errors[] = 'This student has an outstanding balance. It must be settled with Treasury before Semester 2 can proceed.';
                    } else {
                        $semester2Pass = true;
                    }
                }

                if (!$errors && $semester2Pass) {
                    $nameParts = array_filter([
                        $student['given_name'], $student['middle_name'],
                        $student['family_name'], $student['suffix'],
                    ]);

                    $wizard = [
                        'step'                   => 4,
                        'max_step_reached'       => 6,
                        'enrollment_id'          => (int) $enrolledThisYear['enrollment_id'],
                        'student_id'             => (int) $student['student_id'],
                        'student_number'         => $student['student_number'],
                        'student_name'           => implode(' ', $nameParts),
                        'is_new_student'         => (bool) $enrolledThisYear['is_new_student'],
                        'is_transferee'          => (bool) $student['is_transferee'],
                        'prev_completion_status' => null,
                        'jhs_is_public'          => (bool) $student['jhs_is_public'],
                        'school_year'            => $cycle['school_year'],
                        'grade_level'            => $enrolledThisYear['grade_level'],
                        'strand'                 => $enrolledThisYear['strand'],
                        'prev_strand'            => null,
                        'jhs_school'             => $student['jhs_school'],
                        'evaluation_type'        => null,
                        'credited_subject_ids'   => [],
                        'back_subject_ids'       => [],
                        'back_subject_sections'  => [],
                        'section_id'             => $enrolledThisYear['section_id'] ? (int) $enrolledThisYear['section_id'] : null,
                        'finalized'              => false,
                        'resumed_pre_enrollment' => false,
                        'is_semester2_pass'      => true,
                    ];

                    redirect_wizard();
                }

                $preEnrolled = null;
                if (!$errors) {
                    $preEnrolled = db_fetch_one(
                        $conn,
                        "SELECT e.enrollment_id, e.admission_grade_level AS grade_level, st.strand_code AS strand,
                                e.section_id, e.is_new_student
                         FROM enrollments e
                         JOIN strands st ON st.strand_id = e.admission_strand
                         WHERE e.student_id = ? AND e.school_year = ? AND e.status = 'pre_enrolled'
                         ORDER BY e.enrollment_id DESC LIMIT 1",
                        'is',
                        [$studentId, $cycle['school_year']]
                    );
                }

                // A bare 'pending' row is just the admission-stage
                // placeholder created by admission.php — it isn't a real
                // prior enrollment, so it shouldn't count toward "has this
                // student ever actually been enrolled before" (that would
                // misclassify every brand-new admit as "returning").
                $countRow = db_fetch_one(
                    $conn,
                    "SELECT COUNT(*) AS c FROM enrollments WHERE student_id = ? AND status != 'pending'",
                    'i',
                    [$studentId]
                );
                $isNew = ((int)($countRow['c'] ?? 0) === 0);

                if (!$errors && $preEnrolled) {
                    // Resume: grade/strand/evaluation were already decided
                    // last time — skip straight to Section Selection so
                    // staff can just change the homeroom (or back-subject
                    // classes) without re-running Evaluation from scratch.
                    $nameParts = array_filter([
                        $student['given_name'], $student['middle_name'],
                        $student['family_name'], $student['suffix'],
                    ]);
                    $enrollmentId = (int)$preEnrolled['enrollment_id'];

                    $creditedRows = db_fetch_all(
                        $conn,
                        "SELECT subject_id FROM subject_completion_records
                         WHERE student_id = ? AND source = 'credited' AND remarks = 'Credited during pre-enrollment'",
                        'i',
                        [$studentId]
                    );
                    $creditedIds = array_map('intval', array_column($creditedRows, 'subject_id'));

                    $backRows = db_fetch_all(
                        $conn,
                        "SELECT es.subject_id, es.section_id FROM enrollment_subjects es
                         WHERE es.enrollment_id = ? AND es.subject_type = 'back_subject'",
                        'i',
                        [$enrollmentId]
                    );
                    $backIds = [];
                    $backSections = [];
                    foreach ($backRows as $r) {
                        $sid = (int)$r['subject_id'];
                        $backIds[] = $sid;
                        $backSections[$sid] = (int)$r['section_id'];
                    }

                    // Same prior-cycle lookups as the fresh path below,
                    // purely so Steps 2/3 still render sensibly if staff
                    // navigates back to them.
                    $prevRow = db_fetch_one(
                        $conn,
                        "SELECT e.admission_grade_level AS grade_level, st.strand_code AS strand
                         FROM enrollments e
                         JOIN strands st ON st.strand_id = e.admission_strand
                         WHERE e.student_id = ? AND e.enrollment_id != ? ORDER BY e.enrollment_id DESC LIMIT 1",
                        'ii',
                        [$studentId, $enrollmentId]
                    );
                    $prevCompletionRow = db_fetch_one(
                        $conn,
                        "SELECT academic_status FROM enrollments
                         WHERE student_id = ? AND enrollment_id != ? ORDER BY enrollment_id DESC LIMIT 1",
                        'ii',
                        [$studentId, $enrollmentId]
                    );

                    $wizard = [
                        'step'                   => 4,
                        'max_step_reached'       => 6,
                        'enrollment_id'          => $enrollmentId,
                        'student_id'             => (int)$student['student_id'],
                        'student_number'         => $student['student_number'],
                        'student_name'           => implode(' ', $nameParts),
                        'is_new_student'         => (bool)$preEnrolled['is_new_student'],
                        'is_transferee'          => (bool)$student['is_transferee'],
                        'prev_completion_status' => $prevCompletionRow['academic_status'] ?? null,
                        'jhs_is_public'          => (bool)$student['jhs_is_public'],
                        'school_year'            => $cycle['school_year'],
                        'grade_level'            => $preEnrolled['grade_level'],
                        'strand'                 => $preEnrolled['strand'],
                        'prev_strand'            => $prevRow['strand'] ?? null,
                        'jhs_school'             => $student['jhs_school'],
                        'evaluation_type'        => null,
                        'credited_subject_ids'   => $creditedIds,
                        'back_subject_ids'       => $backIds,
                        'back_subject_sections'  => $backSections,
                        'section_id'             => $preEnrolled['section_id'] ? (int)$preEnrolled['section_id'] : null,
                        'finalized'              => false,
                        'resumed_pre_enrollment' => true,
                        'is_semester2_pass'      => false,
                    ];

                    redirect_wizard();
                }

                if (!$errors && !$preEnrolled) {
                    $nameParts = array_filter([
                        $student['given_name'],
                        $student['middle_name'],
                        $student['family_name'],
                        $student['suffix'],
                    ]);

                    // Claim the admission-stage 'pending' row for this cycle
                    // if one exists (the normal path: admission.php ->
                    // document_review.php already created it). If none
                    // exists, the wizard proceeds
                    // without a claimed id and confirm_enrollment will
                    // INSERT fresh at the end, same as before.
                    $existing = db_fetch_one(
                        $conn,
                        "SELECT e.enrollment_id, e.admission_grade_level AS grade_level, st.strand_code AS strand
                         FROM enrollments e
                         JOIN strands st ON st.strand_id = e.admission_strand
                         WHERE e.student_id = ? AND e.school_year = ? AND e.status = 'pending'
                         ORDER BY e.enrollment_id DESC LIMIT 1",
                        'is',
                        [$studentId, $cycle['school_year']]
                    );

                    $prevGrade  = null;
                    $prevStrand = null;
                    $claimedId  = null;
                    // True when $prevGrade comes from the student's own
                    // current-cycle admission claim (already the correct
                    // target grade — use as-is) rather than a genuinely
                    // completed prior-cycle enrollment (eligible for the
                    // 11->12 auto-advance below).
                    $gradeFromCurrentClaim = false;

                    if ($existing) {
                        $claimedId  = (int)$existing['enrollment_id'];
                        $prevGrade  = $existing['grade_level'] ?: null;
                        $prevStrand = $existing['strand'] ?: null;
                        $gradeFromCurrentClaim = true;
                    } else {
                        // Fall back to the student's last enrollment ever,
                        // purely as a convenience default (no row claimed).
                        $prev = db_fetch_one(
                            $conn,
                            "SELECT e.admission_grade_level AS grade_level, st.strand_code AS strand
                             FROM enrollments e
                             JOIN strands st ON st.strand_id = e.admission_strand
                             WHERE e.student_id = ? ORDER BY e.enrollment_id DESC LIMIT 1",
                            'i',
                            [$studentId]
                        );
                        if ($prev) {
                            $prevGrade  = $prev['grade_level'];
                            $prevStrand = $prev['strand'] ?: null;
                        }
                    }

                    // Completion status of the last *completed* cycle — the
                    // dedicated completion_status column was dropped by the
                    // enroll3_db migration (backed up in
                    // _backup_enrollment_completion_status, no longer
                    // queried). academic_status is now the only remaining
                    // signal for this check. Always taken from the most
                    // recent enrollment outside the current one being
                    // claimed (a freshly claimed pending row starts
                    // 'in_progress' by default and isn't a real prior
                    // outcome).
                    $prevCompletionRow = db_fetch_one(
                        $conn,
                        "SELECT academic_status FROM enrollments
                         WHERE student_id = ? AND enrollment_id != ?
                         ORDER BY enrollment_id DESC LIMIT 1",
                        'ii',
                        [$studentId, $claimedId ?? 0]
                    );
                    $prevCompletionStatus = $prevCompletionRow['academic_status'] ?? null;

                    $wizard = [
                        'step'                  => 2,
                        'max_step_reached'      => 2,
                        'enrollment_id'         => $claimedId,
                        'student_id'            => (int)$student['student_id'],
                        'student_number'        => $student['student_number'],
                        'student_name'          => implode(' ', $nameParts),
                        'is_new_student'        => $isNew,
                        'is_transferee'         => (bool)$student['is_transferee'],
                        'prev_completion_status' => $prevCompletionStatus,
                        'jhs_is_public'         => (bool)$student['jhs_is_public'],
                        'school_year'           => $cycle['school_year'],
                        'grade_level'           => $gradeFromCurrentClaim
                            ? ($prevGrade ?? '11')
                            : ($prevGrade === '11' ? '12' : ($prevGrade ?? '11')),
                        'strand'                => in_array($prevStrand, ENROLL_STRANDS, true) ? $prevStrand : null,
                        'prev_strand'           => in_array($prevStrand, ENROLL_STRANDS, true) ? $prevStrand : null,
                        'jhs_school'            => $student['jhs_school'],
                        'evaluation_type'       => null,
                        'credited_subject_ids'  => [],
                        'back_subject_ids'      => [],
                        'back_subject_sections' => [],
                        'section_id'            => null,
                        'finalized'             => false,
                        'resumed_pre_enrollment' => false,
                        'is_semester2_pass'     => false,
                    ];

                    redirect_wizard();
                }
            }
        }

    } elseif ($action === 'set_details') {
        $gradeLevel = $_POST['grade_level'] ?? '';
        $strand     = $_POST['strand'] ?? '';

        if (!in_array($gradeLevel, ['11', '12'], true)) {
            $errors[] = 'Please select a valid grade level.';
        } elseif (!in_array($strand, ENROLL_STRANDS, true)) {
            $errors[] = 'Please select a valid strand.';
        } else {
            $wizard['grade_level'] = $gradeLevel;
            $wizard['strand']      = $strand;
            $wizard['step']        = 3;
            $wizard['max_step_reached'] = max($wizard['max_step_reached'], 3);
            $wizard['evaluation_type']       = null;
            $wizard['credited_subject_ids']  = [];
            $wizard['back_subject_ids']      = [];
            $wizard['back_subject_sections'] = [];
            $wizard['section_id']            = null;
        }
        redirect_wizard();

    } elseif ($action === 'edit_context') {
        // Only usable while the wizard is still before Step 3 — once
        // evaluation/subjects have been built off the current grade/strand,
        // changing them here would silently orphan those picks.
        if (($wizard['max_step_reached'] ?? 1) >= 3) {
            $errors[] = 'Grade level and strand can no longer be edited at this stage. Use "Start Over" instead.';
        } else {
            $newGrade  = $_POST['new_grade_level'] ?? '';
            $newStrand = $_POST['new_strand'] ?? '';
            $reason    = trim($_POST['edit_reason'] ?? '');

            if (!in_array($newGrade, ['11', '12'], true)) {
                $errors[] = 'Please select a valid grade level.';
            } elseif (!in_array($newStrand, ENROLL_STRANDS, true)) {
                $errors[] = 'Please select a valid strand.';
            } elseif ($reason === '') {
                $errors[] = 'Please provide a reason for this change.';
            } else {
                if ($wizard['enrollment_id']) {
                    // old_strand/new_strand are FKs to strands.strand_id —
                    // $wizard['strand']/$newStrand are strand CODES (e.g.
                    // "GAS"), so they must be resolved first, same as
                    // finalize_preenrollment() already does elsewhere in
                    // this file.
                    $oldStrandId = strand_id($conn, $wizard['strand']);
                    $newStrandId = strand_id($conn, $newStrand);
                    db_execute(
                        $conn,
                        "INSERT INTO enrollment_context_changes
                            (enrollment_id, old_grade_level, old_strand, new_grade_level, new_strand, reason, changed_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        'isisisi',
                        [
                            $wizard['enrollment_id'],
                            $wizard['grade_level'],
                            $oldStrandId,
                            $newGrade,
                            $newStrandId,
                            $reason,
                            $_SESSION['user_id'] ?? null,
                        ]
                    );
                }
                $wizard['grade_level']           = $newGrade;
                $wizard['strand']                = $newStrand;
                $wizard['evaluation_type']       = null;
                $wizard['credited_subject_ids']  = [];
                $wizard['back_subject_ids']      = [];
                $wizard['back_subject_sections'] = [];
                $wizard['section_id']            = null;
            }
        }
        redirect_wizard();

    } elseif ($action === 'set_subject_evaluation') {
        // Step 3 is now automatic — the wizard itself decides Regular vs.
        // Needs Validation (see wizard_needs_validation()). This action
        // just commits that outcome plus whatever subjects it implies.
        $needsValidation = wizard_needs_validation($wizard);

        if (!$needsValidation) {
            $wizard['evaluation_type']       = 'regular';
            $wizard['credited_subject_ids']  = [];
            $wizard['back_subject_ids']      = [];
            $wizard['back_subject_sections'] = [];

        } elseif ($wizard['is_transferee']) {
            // Transferees are the one case still picked by hand — there
            // are no internal subject_completion_records to trust for a
            // student coming from another school.
            $isTransfereeShift = wizard_is_strand_change($wizard);
            $currentSubjects   = subjects_for_curriculum($conn, $wizard['grade_level'], curriculum_strand($wizard['strand']));
            $currentSubjectIds = array_map('intval', array_column($currentSubjects, 'subject_id'));
            // A strand-shifting transferee can only ever credit the shared
            // CORE (minor) subjects — the new strand's major subjects
            // aren't creditable, so they're excluded from what a tampered
            // POST could sneak in as "credited" too.
            $creditableCurrentIds = $isTransfereeShift
                ? array_map('intval', array_column(array_filter($currentSubjects, fn($s) => $s['strand'] === 'CORE'), 'subject_id'))
                : $currentSubjectIds;
            $credited = array_values(array_intersect(
                array_map('intval', $_POST['credited_subject_ids'] ?? []),
                $creditableCurrentIds
            ));

            $back = [];
            $prior = prior_term($wizard['grade_level']);
            if ($prior) {
                $priorSubjectIds = array_map(
                    'intval',
                    array_column(
                        subjects_for_curriculum($conn, $prior[0], curriculum_strand($wizard['strand'])),
                        'subject_id'
                    )
                );
                $back = array_values(array_intersect(
                    array_map('intval', $_POST['back_subject_ids'] ?? []),
                    $priorSubjectIds
                ));

                if ($isTransfereeShift) {
                    // Strand-shifting transferees: prior CORE subjects are
                    // credited automatically (no checkbox of their own),
                    // and the old strand's specialized subjects are never
                    // owed as back subjects either way.
                    $priorCoreIds = array_map(
                        'intval',
                        array_column(
                            array_filter(
                                subjects_for_curriculum($conn, $prior[0], curriculum_strand($wizard['strand'])),
                                fn($s) => $s['strand'] === 'CORE'
                            ),
                            'subject_id'
                        )
                    );
                    $credited = array_values(array_unique(array_merge($credited, $priorCoreIds)));
                    $back = [];
                }
            }

            $currentToTake = count(array_diff($currentSubjectIds, $credited));
            $totalLoad     = $currentToTake + count($back);

            if (count($credited) + count($back) < 1) {
                $errors[] = 'Check at least one Credited or Back Subject box before continuing.';
            } elseif ($totalLoad < 1) {
                $errors[] = 'Select at least one subject to take this term (across current or back subjects).';
            } elseif ($totalLoad > 8) {
                $errors[] = 'Total subject load cannot exceed 8 subjects. Currently: ' . $totalLoad . '.';
            }

            if (!$errors) {
                $wizard['evaluation_type']      = 'needs_validation';
                $wizard['credited_subject_ids'] = $credited;
                $wizard['back_subject_ids']     = $back;
                $wizard['back_subject_sections'] = array_intersect_key(
                    $wizard['back_subject_sections'] ?? [],
                    array_flip($back)
                );
            }

        } else {
            // Returning student needing validation — fully automatic,
            // read straight from this student's own completion records.
            $auto = auto_evaluate_returning($conn, $wizard);
            $wizard['evaluation_type']       = 'needs_validation';
            $wizard['credited_subject_ids']  = $auto['credited'];
            $wizard['back_subject_ids']      = $auto['back'];
            $wizard['back_subject_sections'] = array_intersect_key(
                $wizard['back_subject_sections'] ?? [],
                array_flip($auto['back'])
            );
        }

        if (!$errors) {
            $wizard['step'] = 4;
            $wizard['max_step_reached'] = max($wizard['max_step_reached'], 4);
        }
        redirect_wizard();

    } elseif ($action === 'select_section') {
        $sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
        if (!$sectionId) {
            $errors[] = 'Please select a section.';
        } else {
            $validSection = db_fetch_one(
                $conn,
                "SELECT section_id FROM sections
                 WHERE section_id = ? AND grade_level = ? AND strand = ?
                   AND school_year = ? AND is_active = 1",
                'isis',
                [
                    $sectionId,
                    $wizard['grade_level'],
                    strand_id($conn, curriculum_strand($wizard['strand'])),
                    $wizard['school_year'],
                ]
            );
            if (!$validSection) {
                $errors[] = 'That section is not valid for the selected grade, strand, and school year.';
            } else {
                if ((int)$wizard['section_id'] !== $sectionId) {
                    // Homeroom changed — previously chosen back-subject
                    // sections may now conflict with the new schedule.
                    $wizard['back_subject_sections'] = [];
                }
                $wizard['section_id'] = $sectionId;
            }
        }
        redirect_wizard();

    } elseif ($action === 'go_to_review') {
        // Non-committing — just advances to the Review step. Same
        // readiness check as the old direct-finalize button: a homeroom
        // section and a class for every back subject.
        $backReady = count($wizard['back_subject_sections']) >= count($wizard['back_subject_ids']);
        if (!$wizard['section_id'] || !$backReady) {
            $errors[] = 'Select a homeroom section and a class for every back subject before continuing.';
        } else {
            $wizard['step'] = 5;
            $wizard['max_step_reached'] = max($wizard['max_step_reached'], 5);
        }
        redirect_wizard();

    } elseif ($action === 'set_back_subject_section') {
        $subjectId = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
        $sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
        if ($subjectId && in_array($subjectId, $wizard['back_subject_ids'], true)) {
            if ($sectionId) {
                $wizard['back_subject_sections'][$subjectId] = $sectionId;
            } else {
                unset($wizard['back_subject_sections'][$subjectId]);
            }
        }
        redirect_wizard();

    } elseif ($action === 'finalize_preenrollment') {
        // total_due gets computed and stored below from tuition_fees — if
        // that table has no real amount configured for this grade level yet,
        // it would silently store ₱0, which Treasury's payment screen then
        // reads as "already fully paid" the moment it loads (before anyone
        // clicks anything). Block here instead, at the source.
        $feeCheckRow = db_fetch_one(
            $conn,
            "SELECT tuition_amount, misc_fee FROM tuition_fees WHERE grade_level = ?",
            's',
            [$wizard['grade_level']]
        );
        $feesConfigured = $feeCheckRow
            && ((float)$feeCheckRow['tuition_amount'] > 0.0 || (float)$feeCheckRow['misc_fee'] > 0.0);

        if (!$wizard['section_id']) {
            $errors[] = 'Please select a homeroom section before confirming.';
        } elseif (count($wizard['back_subject_sections']) < count($wizard['back_subject_ids'])) {
            $errors[] = 'Please pick a class for every back subject before confirming.';
        } elseif (!$feesConfigured) {
            $errors[] = "Tuition fees are not configured for Grade {$wizard['grade_level']} yet — ask an admin to set them in Fee Settings before confirming.";
        } else {
            $conn->begin_transaction();
            try {
                $capRow = db_fetch_one(
                    $conn,
                    "SELECT capacity FROM sections WHERE section_id = ? FOR UPDATE",
                    'i',
                    [$wizard['section_id']]
                );
                $capacity = $capRow ? (int)$capRow['capacity'] : null;

                $countRow = db_fetch_one(
                    $conn,
                    "SELECT COUNT(*) AS c FROM enrollments WHERE section_id = ? AND active_lock = 1
                     AND enrollment_id != ?",
                    'ii',
                    [$wizard['section_id'], (int)($wizard['enrollment_id'] ?? 0)]
                );
                $currentCount = (int)($countRow['c'] ?? 0);

                if ($capacity === null || $currentCount >= $capacity) {
                    $conn->rollback();
                    $errors[] = 'That section just became full. Please pick another section.';
                } else {
                    $offeredRows = db_fetch_all(
                        $conn,
                        // DISTINCT matters: section_subjects' own primary key is
                        // (section_id, subject_id, semester), so a section can
                        // legitimately have the same subject scheduled once per
                        // semester — without this, a duplicate subject_id here
                        // propagates into $currentSubjectIds below and the
                        // enrollment_subjects INSERT loop tries to insert the
                        // same (enrollment_id, subject_id) pair twice.
                        "SELECT DISTINCT ss.subject_id FROM section_subjects ss
                         WHERE ss.section_id = ?",
                        'i',
                        [$wizard['section_id']]
                    );
                    $offeredSubjectIds = array_map('intval', array_column($offeredRows, 'subject_id'));

                    $currentSubjectIds = array_values(array_diff(
                        $offeredSubjectIds,
                        $wizard['credited_subject_ids'],
                        $wizard['back_subject_ids']
                    ));

                    // Billing — the voucher only applies once Records has
                    // actually verified the Voucher Eligibility Certificate
                    // for this enrollment (see document_review.php);
                    // jhs_is_public alone just means the student might qualify.
                    $voucherVerified = false;
                    if ($wizard['jhs_is_public'] && $wizard['enrollment_id']) {
                        $vecRow = db_fetch_one(
                            $conn,
                            "SELECT 1 FROM enrollment_requirements er
                             JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                             WHERE er.enrollment_id = ? AND rt.applicable_to = 'public_jhs_only' AND er.status = 'submitted'
                             LIMIT 1",
                            'i',
                            [$wizard['enrollment_id']]
                        );
                        $voucherVerified = (bool)$vecRow;
                    }

                    $feeRow = db_fetch_one(
                        $conn,
                        "SELECT tuition_amount, shs_voucher, misc_fee FROM tuition_fees WHERE grade_level = ?",
                        's',
                        [$wizard['grade_level']]
                    );
                    $totalDue = null;
                    if ($feeRow) {
                        $totalDue = (float)$feeRow['tuition_amount'] + (float)$feeRow['misc_fee']
                            - ($voucherVerified ? (float)$feeRow['shs_voucher'] : 0.0);
                        if ($totalDue < 0) $totalDue = 0.0;
                    }

                    $isStrandShifter = wizard_is_strand_change($wizard) ? 1 : 0;
                    $admissionStrandId = strand_id($conn, $wizard['strand']);
                    $isSemester2Pass = $wizard['is_semester2_pass'] ?? false;

                    if ($isSemester2Pass) {
                        // A continuing student's grade/strand/school_year/subject
                        // list never change for Semester 2 — only the section
                        // (rarely) and the fee, which is ADDED to whatever is
                        // already owed (not overwritten, unlike a fresh
                        // admission which has no prior charge).
                        $enrollmentId = (int)$wizard['enrollment_id'];
                        $ok = db_affected(
                            $conn,
                            "UPDATE enrollments SET
                                section_id = ?, status = 'pre_enrolled', total_due = total_due + ?,
                                semester2_status = 'approved', semester2_reviewed_at = NOW()
                             WHERE enrollment_id = ? AND status = 'enrolled'",
                            'idi',
                            [
                                $wizard['section_id'], $totalDue, $enrollmentId,
                            ]
                        );
                        if ($ok < 1) {
                            throw new Exception('CLAIM_STALE');
                        }
                    } elseif ($wizard['enrollment_id']) {
                        $enrollmentId = (int)$wizard['enrollment_id'];
                        $ok = db_affected(
                            $conn,
                            "UPDATE enrollments SET
                                section_id = ?, admission_strand = ?, admission_grade_level = ?, school_year = ?,
                                status = 'pre_enrolled', user_id = ?, is_new_student = ?, is_strand_shifter = ?,
                                total_due = ?
                             WHERE enrollment_id = ? AND status IN ('pending', 'pre_enrolled')",
                            'iissiiidi',
                            [
                                $wizard['section_id'], $admissionStrandId, $wizard['grade_level'],
                                $wizard['school_year'],
                                $_SESSION['user_id'] ?? null,
                                $wizard['is_new_student'] ? 1 : 0,
                                $isStrandShifter,
                                $totalDue,
                                $enrollmentId,
                            ]
                        );
                        if ($ok < 1) {
                            throw new Exception('CLAIM_STALE');
                        }
                        // Clear any prior picks for this enrollment before
                        // re-inserting (handles a user going back and
                        // re-finalizing).
                        db_execute($conn, "DELETE FROM enrollment_subjects WHERE enrollment_id = ?", 'i', [$enrollmentId]);
                    } else {
                        $enrollmentId = db_execute(
                            $conn,
                            "INSERT INTO enrollments
                                (control_number, student_id, section_id, admission_strand, admission_grade_level,
                                 school_year, enrollment_date, status, user_id,
                                 is_new_student, is_strand_shifter, total_due)
                             VALUES (NULL, ?, ?, ?, ?, ?, CURDATE(), 'pre_enrolled', ?, ?, ?, ?)",
                            'iiissiiid',
                            [
                                $wizard['student_id'],
                                $wizard['section_id'],
                                $admissionStrandId,
                                $wizard['grade_level'],
                                $wizard['school_year'],
                                $_SESSION['user_id'] ?? null,
                                $wizard['is_new_student'] ? 1 : 0,
                                $isStrandShifter,
                                $totalDue,
                            ]
                        );
                    }

                    // A continuing student's subject list doesn't change for
                    // Semester 2 (subjects aren't semester-specific in this
                    // system) — none of the subject-list writes below apply.
                    if (!$isSemester2Pass) {
                    // Current-term subjects -> homeroom section
                    if ($currentSubjectIds) {
                        $stmt = $conn->prepare(
                            "INSERT INTO enrollment_subjects (enrollment_id, subject_id, section_id)
                             VALUES (?, ?, ?)"
                        );
                        foreach ($currentSubjectIds as $sid) {
                            $stmt->bind_param('iii', $enrollmentId, $sid, $wizard['section_id']);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }

                    // Back subjects -> their individually chosen section,
                    // flagged via subject_type = 'back_subject'.
                    if ($wizard['back_subject_ids']) {
                        $esStmt = $conn->prepare(
                            "INSERT INTO enrollment_subjects (enrollment_id, subject_id, section_id, subject_type)
                             VALUES (?, ?, ?, 'back_subject')"
                        );
                        foreach ($wizard['back_subject_ids'] as $sid) {
                            $backSectionId = (int)($wizard['back_subject_sections'][$sid] ?? 0);
                            if (!$backSectionId) throw new Exception('MISSING_BACK_SECTION');
                            $esStmt->bind_param('iii', $enrollmentId, $sid, $backSectionId);
                            $esStmt->execute();
                        }
                        $esStmt->close();
                    }

                    // Credited subjects -> subject_completion_records,
                    // pending coordinator verification. No seat consumed.
                    if ($wizard['credited_subject_ids']) {
                        $credStmt = $conn->prepare(
                            "INSERT INTO subject_completion_records
                                (student_id, subject_id, status, source, credential_status, remarks, recorded_by)
                             VALUES (?, ?, 'completed', 'credited', 'pending', 'Credited during pre-enrollment', ?)
                             ON DUPLICATE KEY UPDATE
                                status = VALUES(status), source = VALUES(source),
                                credential_status = VALUES(credential_status),
                                remarks = VALUES(remarks), recorded_by = VALUES(recorded_by)"
                        );
                        foreach ($wizard['credited_subject_ids'] as $sid) {
                            $credStmt->bind_param('iii', $wizard['student_id'], $sid, $_SESSION['user_id']);
                            $credStmt->execute();
                        }
                        $credStmt->close();
                    }
                    }

                    // A first-time / transferee student has no Student Number
                    // yet — mint one now that pre-enrollment is finalized.
                    // Returning students already have $wizard['student_number']
                    // set from Step 1's lookup, so this only fires once per
                    // student.
                    if (!$wizard['student_number']) {
                        $sn_prefix = date('Ymd');
                        $sn_like   = $sn_prefix . '%';

                        $sn_seq_stmt = $conn->prepare(
                            "SELECT student_number FROM students WHERE student_number LIKE ? ORDER BY student_number DESC LIMIT 1 FOR UPDATE"
                        );
                        $sn_seq_stmt->bind_param('s', $sn_like);
                        $sn_seq_stmt->execute();
                        $sn_last_row = $sn_seq_stmt->get_result()->fetch_assoc();
                        $sn_seq_stmt->close();

                        $sn_next_seq = 1;
                        if ($sn_last_row) {
                            $sn_next_seq = ((int) substr($sn_last_row['student_number'], 8)) + 1;
                        }

                        $sn_attempts = 0;
                        $sn_saved    = false;
                        while (!$sn_saved && $sn_attempts < 3) {
                            $sn_attempts++;
                            $new_student_number = $sn_prefix . str_pad((string)$sn_next_seq, 4, '0', STR_PAD_LEFT);
                            $sn_stmt = $conn->prepare("UPDATE students SET student_number=? WHERE student_id=?");
                            $sn_stmt->bind_param('si', $new_student_number, $wizard['student_id']);
                            if ($sn_stmt->execute()) {
                                $sn_saved = true;
                            } elseif ($conn->errno === 1062) {
                                $sn_next_seq++;
                            } else {
                                $sn_stmt->close();
                                throw new Exception('UPDATE_STUDENT_NUMBER: ' . $conn->error);
                            }
                            $sn_stmt->close();
                        }
                        if (!$sn_saved) {
                            throw new Exception('UPDATE_STUDENT_NUMBER: could not allocate a unique Student Number');
                        }
                        $wizard['student_number'] = $new_student_number;
                    }

                    $conn->commit();

                    if ($isSemester2Pass) {
                        $usidStmt = $conn->prepare("SELECT user_student_id FROM users_student WHERE student_id = ?");
                        $usidStmt->bind_param('i', $wizard['student_id']);
                        $usidStmt->execute();
                        $usidRow = $usidStmt->get_result()->fetch_assoc();
                        $usidStmt->close();
                        notify_student_users(
                            $conn,
                            [(int) ($usidRow['user_student_id'] ?? 0)],
                            'Your Semester 2 request has been approved.',
                            'roles/student/portal/student_enrollment'
                        );
                    }

                    $wizard['enrollment_id'] = $enrollmentId;
                    $wizard['finalized']     = true;
                    $wizard['step']          = 6;
                    $wizard['max_step_reached'] = max($wizard['max_step_reached'], 6);
                    redirect_wizard();
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = $e->getMessage() === 'MISSING_BACK_SECTION'
                    ? 'Please pick a class for every back subject before confirming.'
                    : 'Something went wrong while saving the pre-enrollment. Please try again.';
                error_log('[enrollment.php] ' . $e->getMessage());
            }
        }
        redirect_wizard();
    }
}

$step = $wizard['step'] ?? 1;

$steps = [
    1 => 'Search',
    2 => 'Details',
    3 => 'Evaluation & Subjects',
    4 => 'Section',
    5 => 'Review',
    6 => 'Success',
];

/* Data needed to render the current step */

// Step 1 directory — every admitted student, their status for the *current*
// cycle (cur, via correlated subquery on the latest matching enrollment_id
// — a student can have more than one row per school_year since active_lock
// only dedupes pre_enrolled/enrolled), and their most recent enrollment
// from any *other* school year (prev) for the "Previous Record" column.
$studentDirectory = [];
if ($step === 1 && $cycle) {
    $studentDirectory = db_fetch_all(
        $conn,
        "SELECT s.student_id, s.student_number, s.family_name, s.given_name, s.middle_name, s.suffix,
                s.student_type, s.is_transferee, se.school_name AS jhs_school, se.is_public AS jhs_is_public,
                cur.enrollment_id AS cur_enrollment_id,
                cur.control_number, cur.status AS cur_status, cur.semester2_status,
                prev.admission_grade_level AS prev_grade, prev_st.strand_code AS prev_strand, prev.school_year AS prev_year
         FROM students s
         LEFT JOIN student_education se ON se.student_id = s.student_id
         LEFT JOIN enrollments cur ON cur.enrollment_id = (
             SELECT e2.enrollment_id FROM enrollments e2
             WHERE e2.student_id = s.student_id AND e2.school_year = ?
             ORDER BY e2.enrollment_id DESC LIMIT 1
         )
         LEFT JOIN enrollments prev ON prev.enrollment_id = (
             SELECT e3.enrollment_id FROM enrollments e3
             WHERE e3.student_id = s.student_id AND e3.school_year != ?
             ORDER BY e3.enrollment_id DESC LIMIT 1
         )
         LEFT JOIN strands prev_st ON prev_st.strand_id = prev.admission_strand
         WHERE s.admission_status = 'admitted'
         ORDER BY s.family_name, s.given_name",
        'ss',
        [$cycle['school_year'], $cycle['school_year']]
    );
}

$curriculumSubjects = [];
$priorSubjects       = [];
$priorTermInfo       = null;
$needsValidation     = false;
$autoPreview         = ['credited' => [], 'back' => []]; // returning, non-transferee only
$subjLookupById      = [];
if ($step === 3 && $wizard['grade_level'] && $wizard['strand']) {
    $curriculumSubjects = subjects_for_curriculum($conn, $wizard['grade_level'], curriculum_strand($wizard['strand']));
    $priorTermInfo = prior_term($wizard['grade_level']);
    if ($priorTermInfo) {
        $priorSubjects = subjects_for_curriculum($conn, $priorTermInfo[0], curriculum_strand($wizard['strand']));
    }
    $needsValidation = wizard_needs_validation($wizard);
    if ($needsValidation && !$wizard['is_transferee']) {
        $autoPreview = auto_evaluate_returning($conn, $wizard);
    }
    foreach (array_merge($curriculumSubjects, $priorSubjects) as $s) {
        $subjLookupById[(int)$s['subject_id']] = $s;
    }
}

$availableSections   = [];
$summarySubjects     = ['current' => [], 'back' => [], 'credited' => []];
$backSubjectOptions  = []; // subject_id => [ ['section_id','section_name','slots_txt','disabled','day','start','end'...], ... ]

if (in_array($step, [4, 5], true) && $wizard['grade_level'] && $wizard['strand']) {
    $availableSections = db_fetch_all(
        $conn,
        "SELECT s.section_id, s.section_name, s.room, s.capacity,
                (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.section_id AND e.active_lock = 1) AS enrolled_count
         FROM sections s
         WHERE s.grade_level = ? AND s.strand = ? AND s.school_year = ? AND s.is_active = 1
         ORDER BY s.section_name",
        'sis',
        [$wizard['grade_level'], strand_id($conn, curriculum_strand($wizard['strand'])), $wizard['school_year']]
    );

    $allSubjects = subjects_for_curriculum($conn, $wizard['grade_level'], curriculum_strand($wizard['strand']));
    $byId = [];
    foreach ($allSubjects as $s) {
        $byId[(int)$s['subject_id']] = $s;
    }

    $offeredSubjectIds = [];
    $homeroomSlots      = [];
    if ($wizard['section_id']) {
        $offeredRows = db_fetch_all(
            $conn,
            "SELECT DISTINCT ss.subject_id FROM section_subjects ss
             WHERE ss.section_id = ?",
            'i',
            [$wizard['section_id']]
        );
        $offeredSubjectIds = array_map('intval', array_column($offeredRows, 'subject_id'));
        $homeroomSlots = section_schedule_slots($conn, (int)$wizard['section_id']);
    }

    foreach ($byId as $sid => $subj) {
        if (in_array($sid, $wizard['credited_subject_ids'], true)) {
            $summarySubjects['credited'][] = $subj;
        } elseif (in_array($sid, $wizard['back_subject_ids'], true)) {
            // handled below via priorSubjects lookup, not $byId
        } elseif (!$wizard['section_id'] || in_array($sid, $offeredSubjectIds, true)) {
            if ($wizard['section_id']) {
                $subj['schedule_txt'] = subject_schedule_text($conn, (int)$wizard['section_id'], $sid);
            }
            $summarySubjects['current'][] = $subj;
        }
    }

    // Back-subject names/labels come from the prior term's curriculum.
    if ($wizard['back_subject_ids']) {
        $priorTermInfo2 = prior_term($wizard['grade_level']);
        $priorAll = $priorTermInfo2
            ? subjects_for_curriculum($conn, $priorTermInfo2[0], curriculum_strand($wizard['strand']))
            : [];
        $priorById = [];
        foreach ($priorAll as $p) $priorById[(int)$p['subject_id']] = $p;

        foreach ($wizard['back_subject_ids'] as $sid) {
            if (!isset($priorById[$sid])) continue;
            $backSubj = $priorById[$sid];
            $chosenSectionId = (int)($wizard['back_subject_sections'][$sid] ?? 0);
            if ($chosenSectionId) {
                $backSubj['schedule_txt'] = subject_schedule_text($conn, $chosenSectionId, $sid);
            }
            $summarySubjects['back'][] = $backSubj;
        }

        // Per-back-subject offering dropdown, only meaningful once a
        // homeroom is chosen (need its schedule for conflict filtering).
        if ($wizard['section_id']) {
            // Busy slots = homeroom's own schedule + every other back
            // subject's already-chosen slot (excluding the one being
            // rendered, to avoid self-conflict).
            $chosenBackSlots = []; // subject_id => slots[]
            foreach ($wizard['back_subject_sections'] as $bSid => $bSectionId) {
                $chosenBackSlots[$bSid] = section_schedule_slots($conn, (int)$bSectionId);
            }

            foreach ($wizard['back_subject_ids'] as $sid) {
                $offerings = db_fetch_all(
                    $conn,
                    "SELECT sec.section_id, sec.section_name, ss.day, ss.start_time, ss.end_time, sec.capacity,
                            (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.section_id AND e.active_lock = 1) AS enrolled_count
                     FROM section_subjects ss
                     JOIN sections sec ON sec.section_id = ss.section_id
                     WHERE ss.subject_id = ? AND sec.is_active = 1 AND sec.school_year = ?",
                    'is',
                    [$sid, $wizard['school_year']]
                );

                $busy = $homeroomSlots;
                foreach ($chosenBackSlots as $otherSid => $slots) {
                    if ($otherSid === $sid) continue;
                    $busy = array_merge($busy, $slots);
                }

                // Group offering rows by section (a section/subject can
                // meet multiple times a week).
                $bySection = [];
                foreach ($offerings as $row) {
                    $bySection[$row['section_id']]['section_name'] ??= $row['section_name'];
                    $bySection[$row['section_id']]['capacity'] ??= (int)$row['capacity'];
                    $bySection[$row['section_id']]['enrolled_count'] ??= (int)$row['enrolled_count'];
                    if ($row['day']) {
                        $bySection[$row['section_id']]['slots'][] = [
                            'day' => $row['day'], 'start' => $row['start_time'], 'end' => $row['end_time'],
                        ];
                    }
                }

                foreach ($bySection as $secId => $info) {
                    $conflict = false;
                    foreach ($info['slots'] ?? [] as $slot) {
                        foreach ($busy as $b) {
                            if (times_overlap($slot['day'], $slot['start'], $slot['end'], $b['day'], $b['start_time'], $b['end_time'])) {
                                $conflict = true;
                                break 2;
                            }
                        }
                    }
                    $scheduleTxt = implode(', ', array_map(
                        fn($s) => $s['day'] . ' ' . substr($s['start'], 0, 5) . '–' . substr($s['end'], 0, 5),
                        $info['slots'] ?? []
                    ));

                    $backSubjectOptions[$sid][] = [
                        'section_id'   => (int)$secId,
                        'section_name' => $info['section_name'],
                        'schedule_txt' => $scheduleTxt ?: 'No schedule set',
                        'slots_txt'    => $info['enrolled_count'] . '/' . $info['capacity'],
                        'full'         => $info['enrolled_count'] >= $info['capacity'],
                        'conflict'     => $conflict,
                    ];
                }
            }
        }
    }
}

// Step 5 (Review) — a preview assessment, computed the same way payment.php
// computes it, but nothing is written yet; the voucher is deliberately not
// deducted here since Records hasn't verified eligibility at this stage —
// only a pending-verification note is shown when the student may qualify.
$reviewAssessment = null;
if ($step === 5 && $wizard['grade_level']) {
    $tf = db_fetch_one($conn, "SELECT tuition_amount, misc_fee, shs_voucher FROM tuition_fees WHERE grade_level = ?", 's', [$wizard['grade_level']]);
    $tuitionAmount = (float)($tf['tuition_amount'] ?? 0);
    $miscFee       = (float)($tf['misc_fee'] ?? 0);
    $reviewAssessment = [
        'tuition_amount'  => $tuitionAmount,
        'misc_fee'        => $miscFee,
        'total'           => $tuitionAmount + $miscFee,
        'fees_configured' => $tuitionAmount > 0.0 || $miscFee > 0.0,
        'voucher_pending' => (bool)$wizard['jhs_is_public'],
    ];
}

// Step 6 — pull the finalized record back from the DB (source of truth,
// not the wizard's in-memory picks) for the printable summary.
$finalSummary = null;
if ($step === 6 && $wizard['finalized'] && $wizard['enrollment_id']) {
    $eid = (int)$wizard['enrollment_id'];

    $studentInfo = db_fetch_one(
        $conn,
        "SELECT st.student_number, st.family_name, st.given_name, st.middle_name, st.suffix,
                st.date_of_birth, st.sex, se.is_public AS jhs_is_public, e.total_due, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
                e.school_year, sec.section_name, e.semester2_status
         FROM enrollments e
         JOIN students st ON st.student_id = e.student_id
         LEFT JOIN student_education se ON se.student_id = st.student_id
         JOIN strands strd ON strd.strand_id = e.admission_strand
         LEFT JOIN sections sec ON sec.section_id = e.section_id
         WHERE e.enrollment_id = ?",
        'i',
        [$eid]
    );

    // section_subjects has one schedule row per subject PER SEMESTER —
    // without filtering to one, the join below fans out and every subject
    // shows twice (its Sem1 slot and its Sem2 slot). The finalize action
    // for a Semester 2 pass already sets semester2_status='approved' as
    // part of the same step that lands here, so this is the same live
    // "genuinely in Semester 2" gate used everywhere else (COR pages,
    // teacher rosters, etc.) — Semester 1 for a normal new admission,
    // Semester 2 for a genuine Semester 2 continuation.
    $isGenuinelySem2 = ($studentInfo['semester2_status'] ?? null) === 'approved'
        && !has_outstanding_accountabilities($conn, $eid, (int) ($studentInfo['jhs_is_public'] ?? 0));
    $displaySemester = $isGenuinelySem2 ? 2 : 1;

    $scheduleRows = db_fetch_all(
        $conn,
        "SELECT sub.subject_name, sec.section_name, ss.day, ss.start_time, ss.end_time, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name,
                COALESCE(ss.room, sec.room) AS room,
                (es.subject_type = 'back_subject') AS is_back
         FROM enrollment_subjects es
         JOIN subjects sub ON sub.subject_id = es.subject_id
         JOIN sections sec ON sec.section_id = es.section_id
         LEFT JOIN section_subjects ss ON ss.section_id = es.section_id AND ss.subject_id = es.subject_id AND ss.semester = ?
         LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
         WHERE es.enrollment_id = ?
         ORDER BY is_back ASC, FIELD(ss.day,'Mon','Tue','Wed','Thu','Fri'), ss.start_time",
        'ii',
        [$displaySemester, $eid]
    );

    $creditedRows = db_fetch_all(
        $conn,
        "SELECT sub.subject_name
         FROM subject_completion_records scr
         JOIN subjects sub ON sub.subject_id = scr.subject_id
         WHERE scr.student_id = (SELECT student_id FROM enrollments WHERE enrollment_id = ?)
           AND scr.source = 'credited' AND scr.remarks = 'Credited during pre-enrollment'",
        'i',
        [$eid]
    );

    $paidRow = db_fetch_one(
        $conn,
        "SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE enrollment_id = ?",
        'i',
        [$eid]
    );

    // Itemized fee breakdown for the assessment — pulled fresh from
    // tuition_fees rather than just showing the single stored total_due,
    // so the student can see what it's actually made of (and whether a
    // voucher was applied) instead of one opaque number.
    $feeRow = db_fetch_one(
        $conn,
        "SELECT tuition_amount, misc_fee, shs_voucher FROM tuition_fees WHERE grade_level = ?",
        's',
        [$studentInfo['grade_level'] ?? '']
    );
    $tuitionAmount = (float)($feeRow['tuition_amount'] ?? 0);
    $miscFee       = (float)($feeRow['misc_fee'] ?? 0);
    $rawTotal      = $tuitionAmount + $miscFee;
    $totalDueVal   = $studentInfo['total_due'] !== null ? (float)$studentInfo['total_due'] : $rawTotal;
    $voucherApplied = max(0.0, $rawTotal - $totalDueVal);

    $finalSummary = [
        'student'        => $studentInfo,
        'schedule'       => $scheduleRows,
        'credited'       => $creditedRows,
        'paid'           => (float)($paidRow['paid'] ?? 0),
        'tuition_amount' => $tuitionAmount,
        'misc_fee'       => $miscFee,
        'voucher_applied' => $voucherApplied,
        'quarterly'      => $totalDueVal / 4,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enrollment — Staff</title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
<?php if ($is_admin): ?><link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_admin.css') ?>"><?php endif; ?>
<style>
  .wizard-progress { display: flex; align-items: center; max-width: 820px; margin: 0 auto 1.75rem; padding: 0 1.5rem; }
  .wizard-step { display: flex; flex-direction: column; align-items: center; gap: 6px; flex: 1; position: relative; cursor: default; }
  .wizard-step.clickable { cursor: pointer; }
  .wizard-step:not(:last-child)::after {
    content: ''; position: absolute; top: 13px; left: calc(50% + 22px); right: calc(-50% + 22px);
    height: 2px; background: #E0E0E0;
  }
  .wizard-step.complete:not(:last-child)::after { background: var(--brand-accent); }
  .wizard-step-num {
    width: 26px; height: 26px; border-radius: 50%; background: #EBEBE4; color: #8A8A7E;
    font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; z-index: 1;
  }
  .wizard-step.active .wizard-step-num { background: var(--brand-primary); color: #fff; }
  .wizard-step.complete .wizard-step-num { background: var(--brand-accent); color: #fff; }
  .wizard-step-label { font-size: 11px; font-weight: 500; color: #8A8A9A; text-align: center; }
  .wizard-step.active .wizard-step-label { color: var(--brand-primary); }

  .cycle-banner {
    display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #5A5A72;
    background: var(--brand-tint); border: 0.5px solid #C9C2F0; border-radius: 8px; padding: 9px 14px;
    max-width: 820px; margin: 0 auto 1.25rem;
  }
  .cycle-banner strong { color: var(--brand-primary); }

  .student-identity-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 18px; margin-top: 10px; font-size: 12px; }
  .student-identity-grid dt { color: #8A8A9A; }
  .student-identity-grid dd { color: #1A1A2E; font-weight: 500; margin-bottom: 6px; }

  .locked-context { display: flex; gap: 8px; margin-bottom: 1rem; }
  .locked-pill { flex: 1; background: #FAFAFC; border: 0.5px solid #D4D4E0; border-radius: 8px; padding: 9px 12px; display:flex; justify-content:space-between; align-items:center; }
  .locked-pill-label { font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.06em; color: #8A8A9A; margin-bottom: 2px; }
  .locked-pill-val { font-size: 13px; font-weight: 600; color: #1A1A2E; }
  .eval-opt {
    display: block; border: 0.5px solid #D4D4E0; border-radius: 8px; padding: 12px 14px; margin-bottom: 8px;
    cursor: pointer; background: #FAFAFC; transition: border-color 0.12s, background 0.12s;
  }
  .eval-opt:hover { border-color: #ADADBD; }
  .eval-opt input { margin-right: 8px; accent-color: var(--brand-primary); }
  .eval-opt.checked { border-color: var(--brand-accent); background: var(--brand-tint); }
  .eval-opt-label { font-size: 13px; font-weight: 500; color: #1A1A2E; }

  .subj-eval-row td { vertical-align: middle; }
  .subj-eval-controls { display: flex; gap: 14px; font-size: 12px; }
  .subj-eval-controls label { display: flex; align-items: center; gap: 4px; margin: 0; font-weight: 400; color: #5A5A72; }
  .subj-eval-controls input { accent-color: var(--brand-primary); width: 14px; height: 14px; cursor: pointer; }

  .eval-status-card { border-radius: 10px; padding: 14px 16px; margin-bottom: 1.1rem; border: 0.5px solid; }
  .eval-status-regular { background: #F0F7F2; border-color: #B9DDC4; }
  .eval-status-warn { background: #FEF6EC; border-color: #F0CE9A; }
  .eval-status-title { font-size: 13.5px; font-weight: 700; color: #1A1A2E; margin-bottom: 3px; }
  .eval-status-desc { font-size: 12.5px; color: #5A5A72; margin: 0; }

  .subj-section-title { font-size: 12px; font-weight: 600; color: #1A1A2E; margin: 1rem 0 0.5rem; }
  .subj-section-hint { font-size: 11.5px; color: #8A8A9A; margin-bottom: 0.5rem; }
  .load-meter { font-size: 12px; color: #5A5A72; margin-top: 0.5rem; }
  .load-meter.over { color: #C0392B; font-weight: 600; }

  .summary-block { margin-bottom: 1.25rem; }
  .summary-block:last-child { margin-bottom: 0; }
  .summary-block-title { font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.06em; color: #8A8A9A; margin-bottom: 0.6rem; }
  .summary-empty { font-size: 12px; color: #ADADBD; }

  .back-subj-card { border: 0.5px solid #D4D4E0; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; background: #FAFAFC; }
  .back-subj-name { font-size: 13px; font-weight: 600; color: #1A1A2E; margin-bottom: 6px; }
  .back-subj-card select { width: 100%; height: 34px; border: 0.5px solid #D4D4E0; border-radius: 6px; padding: 0 10px; font-family: inherit; font-size: 12.5px; background: #fff; }
  .back-subj-warn { font-size: 11.5px; color: #C06A10; margin-top: 4px; }

  /* ── Summary / print ──────────────────────────────────────────────── */
  .summary-print-card { border: 0.5px solid #D4D4E0; border-radius: 10px; padding: 1.25rem 1.5rem; background: #fff; }
  .summary-print-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 1rem; }
  .summary-print-title { font-size: 16px; font-weight: 700; color:#1A1A2E; }
  .summary-print-sub { font-size: 12px; color:#5A5A72; }
  .sched-table { width:100%; border-collapse:collapse; font-size: 12.5px; margin-bottom: 1rem; }
  .sched-table th { text-align:left; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#8A8A9A; padding:6px 8px; border-bottom:1px solid #D4D4E0; }
  .sched-table td { padding:7px 8px; border-bottom:0.5px solid #EBEBF0; }
  .bill-row { display:flex; justify-content:space-between; font-size:13px; padding:4px 0; }
  .bill-row.total { font-weight:700; border-top:1px solid #D4D4E0; margin-top:6px; padding-top:8px; }
  .next-step-notice { background:#FEF6EC; border:0.5px solid #F0CE9A; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#5A5A72; margin-top:1rem; }
  .next-step-notice strong { color:#1A1A2E; }
  .btn-print { height:36px; padding:0 16px; border:none; border-radius:8px; background:var(--brand-primary); color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }

  /* ── Change-strand modal ──────────────────────────────────────────── */
  .btn-link-change { background:none; border:none; color:var(--brand-primary); font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; text-decoration:underline; padding:0; }

  /* ── Step 1: student directory ───────────────────────────────────── */
  /* css_staff.css caps .page-wrap and .panel at 760px; these two rules
     are scoped to Step 1 only, so the directory table can use the full
     content width instead of the narrow form-column width. */
  .page-wrap.page-wrap-directory { max-width: none; }
  .panel.panel-full { max-width: none; }
  .directory-toolbar { display:flex; gap:10px; margin-bottom:0.9rem; flex-wrap:wrap; align-items:center; }
  .directory-search-input { flex:1; min-width:180px; height:36px; border:0.5px solid #D4D4E0; border-radius:8px; padding:0 12px; font-family:inherit; font-size:13px; background:#FAFAFC; outline:none; }
  .directory-search-input:focus { border-color:var(--brand-accent); background:#fff; }
  .directory-table-wrap { max-height:420px; overflow-y:auto; overflow-x:hidden; margin-top:0.25rem; width:100%; }
  .directory-table { width:100%; table-layout:fixed; }
  .directory-table thead th { position:sticky; top:0; background:#fff; }
  .directory-table th, .directory-table td { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .directory-table td:nth-child(1), .directory-table td:nth-child(4) { white-space:normal; word-break:break-word; }
  .directory-select-btn { height:28px; padding:0 12px; font-size:12px; }
  .status-pill { display:inline-block; font-size:11px; font-weight:600; border-radius:20px; padding:3px 10px; white-space:nowrap; }
  .status-pending { background:#FEF6EC; color:#B5650F; }
  .status-pre_enrolled { background:#EAF3EE; color:#1E7A46; }
  .status-enrolled { background:#EBF7F2; color:#1A6B4A; }
  .status-cancelled { background:#FDF0EF; color:#C0392B; }
  .mono { font-family:'Courier New', monospace; font-size:12.5px; }
  .directory-row.hidden-row { display:none; }
  .directory-count { font-size:11px; color:#8A8A9A; margin-bottom:0.6rem; }
  @media print {
    .staff-sidebar, .staff-topbar, .wizard-progress, .cycle-banner, .btn-print, .btn-secondary, .no-print { display:none !important; }
    .summary-print-card { border:none; padding:0; }
  }
</style>
</head>
<body<?php if (!$is_admin): ?> class="staff-layout"<?php endif; ?>>

<?php if ($is_admin): ?>
  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>
  <div class="main"><div class="content">
    <p class="page-eyebrow">Admin Portal</p>
    <h1 class="page-title">Enrollment</h1>
    <p class="page-sub">Step <?= $step ?> of 5 — <?= htmlspecialchars($steps[$step] ?? '') ?></p>
<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <div class="staff-topbar-title">
          Enrollment
          <span class="staff-topbar-subtitle">Step <?= $step ?> of 5 — <?= htmlspecialchars($steps[$step] ?? '') ?></span>
        </div>
      </div>
    </div>
    <div class="staff-content">
<?php endif; ?>

    <div class="page-wrap<?= $step === 1 ? ' page-wrap-directory' : '' ?>" style="padding-top:0;">

      <div class="wizard-progress no-print">
        <?php foreach ($steps as $num => $label): ?>
          <?php $reachable = $num <= ($wizard['max_step_reached'] ?? 1); ?>
          <form method="post" style="display:contents;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="go_to_step">
            <input type="hidden" name="target_step" value="<?= $num ?>">
            <button type="submit" class="wizard-step <?= $num < $step ? 'complete' : ($num === $step ? 'active' : '') ?> <?= $reachable ? 'clickable' : '' ?>"
                    style="background:none;border:none;padding:0;" <?= $reachable ? '' : 'disabled' ?>>
              <div class="wizard-step-num"><?= $num < $step ? '&check;' : $num ?></div>
              <div class="wizard-step-label"><?= htmlspecialchars($label) ?></div>
            </button>
          </form>
        <?php endforeach; ?>
      </div>

      <?php if ($cycle): ?>
        <div class="cycle-banner no-print">
          <span>Current cycle: <strong><?= htmlspecialchars($cycle['school_year']) ?></strong></span>
          <span><?= $enrollOpen ? 'Enrollment open' : 'Enrollment closed' ?></span>
        </div>
      <?php else: ?>
        <div class="notice notice-error">No school year is configured yet. Ask an admin to set one up in School Year Settings.</div>
      <?php endif; ?>

      <?php foreach ($errors as $err): ?>
        <div class="notice notice-error"><?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>

      <?php if (!$enrollOpen): ?>
        <div class="panel">
          <p class="page-sub" style="margin-bottom:0;">Enrollment is currently closed for this cycle. Contact an administrator if this is unexpected.</p>
        </div>

      <?php elseif ($step === 1): ?>
        <div class="panel">
          <div class="panel-sub">Step 1 — Student Lookup</div>
          <p class="field-hint" style="margin-bottom:0.75rem; display:block;">Search by Student Number (returning students) or Control Number (new students &amp; transferees). Only students who have completed admission requirements will be found.</p>
          <div class="student-number-search-wrap">
            <div class="student-number-row">
              <input type="text" id="student-number-input" maxlength="20"
                     placeholder="Enter Student Number or Control Number" autocomplete="off">
              <button type="button" class="btn-search" id="student-number-search-btn">Search</button>
            </div>
            <div class="student-number-suggestions hidden" id="student-number-suggestions"></div>
          </div>
          <div id="student-number-result"></div>
          <form method="post" id="select-student-form" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="select_student">
            <input type="hidden" name="student_id" id="selected-student-id">
          </form>
        </div>

        <div class="panel panel-full">
          <div class="panel-sub">Student Directory — <?= htmlspecialchars($cycle['school_year']) ?></div>

          <?php if (!$studentDirectory): ?>
            <p class="summary-empty">No admitted students found yet.</p>
          <?php else: ?>
            <div class="directory-toolbar">
              <input type="text" id="directory-search" class="directory-search-input" placeholder="Filter by name or Student Number / Control No…">
              <button type="button" class="btn-search" id="directory-filter-btn">Filter</button>
              <button type="button" class="btn-secondary" id="directory-clear-btn">Clear</button>
            </div>

            <div class="directory-table-wrap">
              <table class="subj-table directory-table" id="directory-table">
                <colgroup>
                  <col style="width:22%;">
                  <col style="width:12%;">
                  <col style="width:10%;">
                  <col style="width:20%;">
                  <col style="width:12%;">
                  <col style="width:12%;">
                  <col style="width:12%;">
                </colgroup>
                <thead>
                  <tr>
                    <th>Applicant</th>
                    <th>ID</th>
                    <th>Student Type</th>
                    <th>Previous Record</th>
                    <th>Status</th>
                    <th>Semester</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $statusLabels = ['pending' => 'Pending', 'pre_enrolled' => 'Pre-enrolled', 'enrolled' => 'Enrolled', 'cancelled' => 'Cancelled'];
                    $typeLabels   = ['new' => 'New', 'returning' => 'Returning', 'transferee' => 'Transferee'];
                  ?>
                  <?php foreach ($studentDirectory as $row): ?>
                    <?php
                      $bucket = directory_status_bucket($row['cur_status']);
                      $nameParts = array_filter([
                          $row['family_name'] . ',',
                          $row['given_name'],
                          $row['middle_name'] ? mb_substr($row['middle_name'], 0, 1) . '.' : null,
                          $row['suffix'],
                      ]);
                      $fullName = implode(' ', $nameParts);
                      $idLabel  = $row['student_number'] ?: ($row['control_number'] ?: '—');

                      if ($row['prev_grade']) {
                          $prevLabel = 'Grade ' . $row['prev_grade'] . ' — ' . $row['prev_strand'] . ' · ' . $row['prev_year'];
                      } elseif ($row['is_transferee']) {
                          $prevLabel = 'External' . ($row['jhs_school'] ? ' — ' . $row['jhs_school'] : '');
                      } else {
                          $prevLabel = 'New — Admission';
                      }

                      $searchBlob = mb_strtolower($fullName . ' ' . $idLabel);
                    ?>
                    <tr class="directory-row" data-status="<?= $bucket ?>" data-type="<?= htmlspecialchars($row['student_type']) ?>" data-id="<?= (int)$row['student_id'] ?>" data-search="<?= htmlspecialchars($searchBlob) ?>">
                      <td><?= htmlspecialchars($fullName) ?></td>
                      <td class="mono"><?= htmlspecialchars($idLabel) ?></td>
                      <td><?= htmlspecialchars($typeLabels[$row['student_type']] ?? $row['student_type']) ?></td>
                      <td><?= htmlspecialchars($prevLabel) ?></td>
                      <td>
                        <span class="status-pill status-<?= $bucket ?>"><?= $statusLabels[$bucket] ?></span>
                      </td>
                      <td>
                        <?php
                          $sem2Status = $row['semester2_status'] ?? null;
                          // "Genuinely in Semester 2" is re-checked live against
                          // current accountabilities, not just trusted from
                          // whenever it was approved — so a still-outstanding
                          // document correctly keeps this at "Semester 1".
                          $isGenuinelySem2 = $sem2Status === 'approved' && $row['cur_enrollment_id']
                              && !has_outstanding_accountabilities($conn, (int) $row['cur_enrollment_id'], (int) $row['jhs_is_public']);
                          $semesterLabel = $isGenuinelySem2 ? 'Semester 2' : 'Semester 1';
                        ?>
                        <span class="td-meta"><?= htmlspecialchars($semesterLabel) ?></span>
                      </td>
                      <td>
                        <?php if ($bucket === 'enrolled' && $sem2Status === 'approved'): ?>
                          <span class="td-meta">Enrolled</span>
                        <?php else: ?>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="select_student">
                            <input type="hidden" name="student_id" value="<?= (int)$row['student_id'] ?>">
                            <button type="submit" class="btn-secondary directory-select-btn">Select</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <p class="summary-empty hidden" id="directory-no-results" style="padding:1rem 0 0.25rem;">No students match this filter.</p>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="panel">
          <div class="panel-sub">Student</div>
          <p style="font-size:13px;"><strong><?= htmlspecialchars($wizard['student_name'] ?? '') ?></strong> — Student Number <?= htmlspecialchars($wizard['student_number'] ?? '') ?></p>
          <?php if ($wizard['resumed_pre_enrollment'] ?? false): ?>
            <div class="notice" style="margin-top:.75rem;">
              This student is already pre-enrolled for <strong><?= htmlspecialchars($wizard['school_year']) ?></strong> — Grade <strong><?= htmlspecialchars($wizard['grade_level']) ?></strong>, <strong><?= htmlspecialchars($wizard['strand']) ?></strong> strand. Payment is still pending, so you can change their homeroom section (and back-subject classes) below, then re-confirm.
            </div>
          <?php elseif ($wizard['is_semester2_pass'] ?? false): ?>
            <div class="notice" style="margin-top:.75rem;">
              <span class="badge" style="background:var(--brand-tint,#EAF3EE);color:var(--brand-primary,#1E4D3B);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;border-radius:20px;padding:2px 9px;margin-right:6px;">Semester 2</span>
              Continuing into Semester 2 of <strong><?= htmlspecialchars($wizard['school_year']) ?></strong> — Grade <strong><?= htmlspecialchars($wizard['grade_level']) ?></strong>, <strong><?= htmlspecialchars($wizard['strand']) ?></strong> strand. The homeroom section below is pre-selected to their <strong>current</strong> section on purpose — the Semester 2 fee will be added on top of what's already on file.
            </div>
            <div class="notice notice-error" style="margin-top:.5rem;">
              <strong>Careful:</strong> a section applies to the student's whole school year, not just Semester 2. Picking a <em>different</em> section here also changes their already-completed Semester 1 schedule, teachers, and grades — only change it if that's genuinely intended.
            </div>
          <?php endif; ?>
        </div>

        <?php if ($step === 2): ?>
          <div class="panel">
            <div class="panel-sub">Step 2 — Enrollment Details</div>

            <div class="locked-context">
              <div class="locked-pill">
                <div>
                  <div class="locked-pill-label">School Year</div>
                  <div class="locked-pill-val"><?= htmlspecialchars($wizard['school_year']) ?></div>
                </div>
              </div>
            </div>
            <p class="field-hint" style="margin-bottom:1rem; display:block;">School year follows the active cycle and can't be changed here — update School Year Settings instead.</p>

            <?php if ($wizard['grade_level'] && $wizard['strand']): ?>
              <!-- Already picked once — show locked values + inline Strand Shift edit. -->
              <?php $canEditStrand = ($wizard['max_step_reached'] ?? 1) < 3; ?>
              <div class="locked-context">
                <div class="locked-pill">
                  <div>
                    <div class="locked-pill-label">Grade Level</div>
                    <div class="locked-pill-val">Grade <?= htmlspecialchars($wizard['grade_level']) ?></div>
                  </div>
                </div>
                <div class="locked-pill">
                  <div>
                    <div class="locked-pill-label">Strand</div>
                    <div class="locked-pill-val"><?= htmlspecialchars($wizard['strand']) ?></div>
                  </div>
                  <?php if ($canEditStrand): ?>
                    <button type="button" class="btn-link-change" onclick="toggleStrandShift()">Strand Shift</button>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($wizard['prev_strand']): $willChangeStrand = wizard_is_strand_change($wizard); ?>
                <p class="field-hint" style="margin:-.5rem 0 1rem;">Strand shift: <strong><?= $willChangeStrand ? 'Yes' : 'No' ?></strong> (vs. previous strand <?= htmlspecialchars($wizard['prev_strand']) ?>)<?= !$canEditStrand ? ' — locked past Step 2, use "Start Over" to change it now.' : '' ?></p>
              <?php elseif (!$canEditStrand): ?>
                <p class="field-hint" style="margin:-.5rem 0 1rem;">Strand is locked past Step 2 — use "Start Over" to change it now.</p>
              <?php endif; ?>

              <?php if ($canEditStrand): ?>
              <div id="strand-shift-row" style="display:none; margin-bottom:1.25rem; border:0.5px solid #D4D4E0; border-radius:8px; padding:12px 14px; background:#FAFAFC;">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="edit_context">
                  <input type="hidden" name="new_grade_level" value="<?= htmlspecialchars($wizard['grade_level']) ?>">

                  <label style="display:block; font-size:11px; font-weight:500; text-transform:uppercase; letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem;">New Strand <span class="req">*</span></label>
                  <select name="new_strand" required style="width:100%; height:36px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 10px; font-family:inherit; font-size:12.5px; background:#fff; margin-bottom:.75rem;">
                    <option value="">Select strand…</option>
                    <?php foreach (ENROLL_STRANDS as $s): ?>
                      <?php if ($s === $wizard['strand']) continue; ?>
                      <option value="<?= $s ?>"><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                  </select>

                  <label style="display:block; font-size:11px; font-weight:500; text-transform:uppercase; letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem;">Reason for Shift <span class="req">*</span></label>
                  <textarea name="edit_reason" rows="2" required
                    style="width:100%; border:0.5px solid #D4D4E0; border-radius:8px; padding:8px 10px; font-family:inherit; font-size:12.5px; resize:vertical;"
                    placeholder="e.g. Student requested strand shift after re-evaluation"></textarea>

                  <div class="proceed-row" style="border-top:none; padding-top:0; margin-top:.75rem;">
                    <button type="button" class="btn-secondary" onclick="toggleStrandShift()">Cancel</button>
                    <button type="submit" class="btn-primary">Confirm Strand Shift</button>
                  </div>
                </form>
              </div>
              <?php endif; ?>

              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="set_details">
                <input type="hidden" name="grade_level" value="<?= htmlspecialchars($wizard['grade_level']) ?>">
                <input type="hidden" name="strand" value="<?= htmlspecialchars($wizard['strand']) ?>">
                <div class="proceed-row" style="border-top:none; padding-top:0; margin-top:1rem;">
                  <span></span>
                  <button type="submit" class="btn-primary">Continue to Evaluation</button>
                </div>
              </form>

            <?php else: ?>
              <!-- First-time pick — plain radios, no reason needed yet. -->
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="set_details">

                <label>Grade Level <span class="req">*</span></label>
                <div class="radio-row">
                  <?php foreach (['11' => 'Grade 11', '12' => 'Grade 12'] as $val => $label): ?>
                    <div class="radio-opt">
                      <input type="radio" name="grade_level" id="grade-<?= $val ?>" value="<?= $val ?>"
                             <?= (string)$wizard['grade_level'] === $val ? 'checked' : '' ?>>
                      <label for="grade-<?= $val ?>"><?= $label ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>

                <label>Strand <span class="req">*</span></label>
                <div class="strand-row">
                  <?php foreach (ENROLL_STRANDS as $s): ?>
                    <div class="strand-opt">
                      <input type="radio" name="strand" id="strand-<?= $s ?>" value="<?= $s ?>"
                             <?= $wizard['strand'] === $s ? 'checked' : '' ?>>
                      <label for="strand-<?= $s ?>"><span class="strand-name"><?= htmlspecialchars($s) ?></span></label>
                    </div>
                  <?php endforeach; ?>
                </div>

                <div class="proceed-row" style="border-top:none; padding-top:0; margin-top:1.25rem;">
                  <span></span>
                  <button type="submit" class="btn-primary">Continue to Evaluation</button>
                </div>
              </form>
            <?php endif; ?>
          </div>

        <?php elseif ($step === 3): ?>
          <div class="panel">
            <div class="panel-sub">Step 3 — Academic Evaluation</div>
            <p class="page-sub">For <?= htmlspecialchars('Grade ' . $wizard['grade_level'] . ' — ' . $wizard['strand']) ?></p>
            <?php if ($wizard['jhs_school']): ?>
              <p class="field-hint" style="margin-bottom:.75rem;">Prev. School: <?= htmlspecialchars($wizard['jhs_school']) ?></p>
            <?php endif; ?>

            <?php if (!$needsValidation): ?>
              <div class="eval-status-card eval-status-regular">
                <div class="eval-status-title">Regular Student</div>
                <p class="eval-status-desc">No subject validation required. This student may continue with the standard curriculum.</p>
              </div>
            <?php else: ?>
              <div class="eval-status-card eval-status-warn">
                <div class="eval-status-title">Subject Validation Required</div>
                <p class="eval-status-desc">This student requires previous SHS subjects to be evaluated before section assignment.</p>
              </div>
            <?php endif; ?>

            <form method="post" id="eval-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="set_subject_evaluation">

              <?php if ($needsValidation && $wizard['is_transferee']): ?>
                <!-- Transferee: manual checklist — no internal completion
                     records exist yet for a student coming from another school. -->
                <?php
                  $isTransfereeShift = wizard_is_strand_change($wizard);
                  // A strand-shifting transferee couldn't have taken the new
                  // strand's major/specialized subjects anywhere else, so
                  // those aren't creditable and don't belong in this
                  // checklist at all — only the shared CORE (minor)
                  // subjects can be credited. A non-shifting transferee is
                  // continuing the same strand, so their full current-term
                  // curriculum (CORE + major) is still creditable.
                  $transfereeCreditable = $isTransfereeShift
                      ? array_filter($curriculumSubjects, fn($s) => $s['strand'] === 'CORE')
                      : $curriculumSubjects;
                ?>
                <div class="subj-section-title">Current Term Subjects<?= $isTransfereeShift ? ' — Minor (Core) Only' : '' ?></div>
                <p class="subj-section-hint"><?= $isTransfereeShift
                    ? 'Only Core subjects can be credited for a strand shifter — the new strand\'s major subjects aren\'t creditable and will simply be taken this term.'
                    : 'Check "Credited" for subjects the student already completed elsewhere. Unchecked subjects will be taken this term.' ?></p>
                <table class="subj-table">
                  <thead><tr><th>Subject</th><th>Track</th><th>Credited</th></tr></thead>
                  <tbody>
                    <?php foreach ($transfereeCreditable as $subj): ?>
                      <?php $sid = (int)$subj['subject_id']; ?>
                      <tr class="subj-eval-row">
                        <td><?= htmlspecialchars($subj['subject_name']) ?></td>
                        <td><?= htmlspecialchars($subj['strand']) ?></td>
                        <td>
                          <div class="subj-eval-controls">
                            <label>
                              <input type="checkbox" class="load-input" name="credited_subject_ids[]" value="<?= $sid ?>"
                                     <?= in_array($sid, $wizard['credited_subject_ids'], true) ? 'checked' : '' ?>>
                              Credited
                            </label>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$transfereeCreditable): ?>
                      <tr><td colspan="3" class="summary-empty">No subjects found for this grade/strand yet.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>

                <?php if ($priorTermInfo && !wizard_is_strand_change($wizard)): ?>
                  <div class="subj-section-title">Back Subjects (owed from Grade <?= htmlspecialchars($priorTermInfo[0]) ?>)</div>
                  <p class="subj-section-hint">Check subjects the student still needs to complete from the prior grade level.</p>
                  <table class="subj-table">
                    <thead><tr><th>Previous Subject</th><th>Origin</th><th>Back Subject</th></tr></thead>
                    <tbody>
                      <?php foreach ($priorSubjects as $subj): ?>
                        <?php $sid = (int)$subj['subject_id']; ?>
                        <tr class="subj-eval-row">
                          <td><?= htmlspecialchars($subj['subject_name']) ?></td>
                          <td>G<?= htmlspecialchars($priorTermInfo[0]) ?></td>
                          <td>
                            <label>
                              <input type="checkbox" class="load-input" name="back_subject_ids[]" value="<?= $sid ?>"
                                     <?= in_array($sid, $wizard['back_subject_ids'], true) ? 'checked' : '' ?>>
                            </label>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!$priorSubjects): ?>
                        <tr><td colspan="3" class="summary-empty">No prior-term curriculum found.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                <?php elseif (wizard_is_strand_change($wizard) && $priorTermInfo):
                  $creditedCoreNames = array_values(array_filter(array_map(
                      fn($p) => in_array((int)$p['subject_id'], $wizard['credited_subject_ids'], true) ? $p['subject_name'] : null,
                      $priorSubjects
                  )));
                ?>
                  <div class="notice" style="margin:1rem 0;">
                    Strand shifter — Grade <?= htmlspecialchars($priorTermInfo[0]) ?> Core subjects already credited automatically (<?= $creditedCoreNames ? htmlspecialchars(implode(', ', $creditedCoreNames)) : '—' ?>). No back subjects apply — the old strand's specialized subjects are dropped, and the new strand's specialized subjects are just part of this term's regular load above.
                  </div>
                <?php endif; ?>

                <div class="load-meter" id="load-meter">Total load: — (min 1, max 8)</div>

              <?php elseif ($needsValidation): ?>
                <!-- Returning student (not a transferee): fully automatic,
                     read straight from subject_completion_records — nothing
                     to toggle. -->
                <div class="subj-section-title">Credited Subjects</div>
                <p class="subj-section-hint">Automatically credited from this student's academic record.</p>
                <table class="subj-table">
                  <thead><tr><th>Subject</th><th>Track</th></tr></thead>
                  <tbody>
                    <?php foreach ($autoPreview['credited'] as $sid): $s = $subjLookupById[$sid] ?? null; ?>
                      <tr>
                        <td><?= htmlspecialchars($s['subject_name'] ?? ('Subject #' . $sid)) ?></td>
                        <td><?= htmlspecialchars($s['strand'] ?? '—') ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$autoPreview['credited']): ?>
                      <tr><td colspan="2" class="summary-empty">None</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>

                <?php if ($autoPreview['back']): ?>
                  <div class="subj-section-title">Back Subjects (owed from Grade <?= htmlspecialchars($priorTermInfo[0] ?? '') ?>)</div>
                  <p class="subj-section-hint">Automatically identified as not yet completed.</p>
                  <table class="subj-table">
                    <thead><tr><th>Subject</th><th>Track</th></tr></thead>
                    <tbody>
                      <?php foreach ($autoPreview['back'] as $sid): $s = $subjLookupById[$sid] ?? null; ?>
                        <tr>
                          <td><?= htmlspecialchars($s['subject_name'] ?? ('Subject #' . $sid)) ?></td>
                          <td><?= htmlspecialchars($s['strand'] ?? '—') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <p class="summary-empty" style="margin-top:.5rem;">No back subjects — everything from the prior term checks out.</p>
                <?php endif; ?>
              <?php endif; ?>

              <div class="proceed-row" style="margin-top:1.25rem;">
                <span></span>
                <button type="submit" class="btn-primary" id="eval-continue-btn">Continue<?= $needsValidation ? ' to Section Selection' : ' →' ?></button>
              </div>
            </form>
          </div>

        <?php elseif ($step === 4): ?>
          <div class="sections-card">
            <div class="sections-head">
              <div class="sections-title">Step 4 — Select Homeroom Section</div>
              <?php if ($wizard['section_id']): ?><span class="open-badge">Selected</span><?php endif; ?>
            </div>

            <?php if (!$availableSections): ?>
              <p class="summary-empty">No active sections found for Grade <?= htmlspecialchars($wizard['grade_level']) ?> — <?= htmlspecialchars($wizard['strand']) ?> in <?= htmlspecialchars($wizard['school_year']) ?>. Use Sections in the sidebar to open one.</p>
            <?php else: ?>
              <div class="sections-grid">
                <?php foreach ($availableSections as $sec): ?>
                  <?php
                    $full = (int)$sec['enrolled_count'] >= (int)$sec['capacity'];
                    $ratio = $sec['capacity'] > 0 ? $sec['enrolled_count'] / $sec['capacity'] : 1;
                    $slotClass = $full ? 'slots-full' : ($ratio >= 0.8 ? 'slots-low' : 'slots-ok');
                    $selected = (int)$wizard['section_id'] === (int)$sec['section_id'];
                  ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="select_section">
                    <input type="hidden" name="section_id" value="<?= (int)$sec['section_id'] ?>">
                    <button type="submit" class="sec-card <?= $selected ? 'sec-selected' : '' ?> <?= $full && !$selected ? 'sec-full' : '' ?>"
                            style="width:100%; text-align:left; border:none; font-family:inherit;" <?= $full && !$selected ? 'disabled' : '' ?>>
                      <div class="sec-name"><?= htmlspecialchars($sec['section_name']) ?></div>
                      <div class="sec-room"><?= htmlspecialchars($sec['room'] ?? '') ?></div>
                      <div class="slots-txt <?= $slotClass ?>"><?= (int)$sec['enrolled_count'] ?>/<?= (int)$sec['capacity'] ?> slots</div>
                    </button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($wizard['back_subject_ids']): ?>
            <div class="panel">
              <div class="panel-sub">Back Subject Classes</div>
              <?php if (!$wizard['section_id']): ?>
                <p class="summary-empty">Select a homeroom section above first — back subject classes are filtered against its schedule.</p>
              <?php else: ?>
                <?php foreach ($wizard['back_subject_ids'] as $sid):
                  $subjName = null;
                  foreach ($priorSubjects as $p) { if ((int)$p['subject_id'] === $sid) { $subjName = $p['subject_name']; break; } }
                  if ($subjName === null) {
                      // priorSubjects only populated on step 3 render; refetch label here for step 4.
                      $lbl = db_fetch_one($conn, "SELECT subject_name FROM subjects WHERE subject_id = ?", 'i', [$sid]);
                      $subjName = $lbl['subject_name'] ?? ('Subject #' . $sid);
                  }
                  $options = $backSubjectOptions[$sid] ?? [];
                  $chosen = (int)($wizard['back_subject_sections'][$sid] ?? 0);
                ?>
                  <div class="back-subj-card">
                    <div class="back-subj-name"><?= htmlspecialchars($subjName) ?></div>
                    <?php if (!$options): ?>
                      <p class="back-subj-warn">No class offers this subject at all yet. Ask Scheduler to open one.</p>
                    <?php else: ?>
                      <form method="post" onchange="this.submit()">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="set_back_subject_section">
                        <input type="hidden" name="subject_id" value="<?= $sid ?>">
                        <select name="section_id">
                          <option value="">Select Class</option>
                          <?php foreach ($options as $opt): ?>
                            <option value="<?= $opt['section_id'] ?>" <?= $chosen === $opt['section_id'] ? 'selected' : '' ?> <?= ($opt['conflict'] || ($opt['full'] && $chosen !== $opt['section_id'])) ? 'disabled' : '' ?>>
                              <?= htmlspecialchars($opt['section_name']) ?> — <?= htmlspecialchars($opt['schedule_txt']) ?> (<?= htmlspecialchars($opt['slots_txt']) ?><?= $opt['full'] ? ', full' : '' ?><?= $opt['conflict'] ? ', conflicts with homeroom schedule' : '' ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </form>
                      <?php if (!array_filter($options, fn($o) => !$o['conflict'] && !$o['full'])): ?>
                        <p class="back-subj-warn">Every offering above conflicts with the homeroom schedule or is full — try a different homeroom section.</p>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php
            $backReady = count($wizard['back_subject_sections']) >= count($wizard['back_subject_ids']);
            $readyToConfirm = $wizard['section_id'] && $backReady;
          ?>
          <div class="panel">
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="go_to_review">
              <div class="proceed-row" style="border-top:none; padding-top:0;">
                <span class="proceed-info">
                  <?= !$wizard['section_id'] ? 'Select a homeroom section above first.' : (!$backReady ? 'Pick a class for every back subject above.' : 'Ready to review.') ?>
                </span>
                <button type="submit" class="btn-primary" <?= $readyToConfirm ? '' : 'disabled' ?>>Continue to Review</button>
              </div>
            </form>
          </div>

        <?php elseif ($step === 5): ?>
          <div class="panel">
            <div class="panel-sub">Step 5 — Review Summary</div>
            <p class="review-note">Grade <?= htmlspecialchars($wizard['grade_level']) ?> — <?= htmlspecialchars($wizard['strand']) ?> · <?= htmlspecialchars($wizard['school_year']) ?></p>

            <div class="summary-block">
              <div class="summary-block-title">Current Subjects</div>
              <?php if ($summarySubjects['current']): ?>
                <table class="subj-table">
                  <thead><tr><th>Subject</th><th>Track</th><th>Schedule</th></tr></thead>
                  <tbody>
                  <?php foreach ($summarySubjects['current'] as $s): ?>
                    <tr><td><?= htmlspecialchars($s['subject_name']) ?></td><td><?= htmlspecialchars($s['strand']) ?></td><td><?= htmlspecialchars($s['schedule_txt'] ?? '—') ?></td></tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <p class="summary-empty">None</p>
              <?php endif; ?>
            </div>

            <div class="summary-block">
              <div class="summary-block-title">Back Subjects</div>
              <?php if ($summarySubjects['back']): ?>
                <table class="subj-table">
                  <thead><tr><th>Subject</th><th>Track</th><th>Schedule</th></tr></thead>
                  <tbody>
                  <?php foreach ($summarySubjects['back'] as $s): ?>
                    <tr><td><?= htmlspecialchars($s['subject_name']) ?></td><td><?= htmlspecialchars($s['strand']) ?></td><td><?= htmlspecialchars($s['schedule_txt'] ?? '—') ?></td></tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <p class="summary-empty">None</p>
              <?php endif; ?>
            </div>

            <div class="summary-block">
              <div class="summary-block-title">Credited Subjects</div>
              <?php if ($summarySubjects['credited']): ?>
                <table class="subj-table"><tbody>
                  <?php foreach ($summarySubjects['credited'] as $s): ?>
                    <tr><td><?= htmlspecialchars($s['subject_name']) ?></td><td><?= htmlspecialchars($s['strand']) ?></td></tr>
                  <?php endforeach; ?>
                </tbody></table>
              <?php else: ?>
                <p class="summary-empty">None</p>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($reviewAssessment): ?>
          <div class="panel">
            <div class="panel-sub">Assessment</div>
            <?php if (!$reviewAssessment['fees_configured']): ?>
              <div class="notice notice-error">Tuition fees are not configured for Grade <?= htmlspecialchars($wizard['grade_level']) ?> yet — ask an admin to set them in Fee Settings before confirming.</div>
            <?php else: ?>
              <table class="subj-table"><tbody>
                <tr><td>Tuition Fee</td><td>₱<?= number_format($reviewAssessment['tuition_amount'], 2) ?></td></tr>
                <tr><td>Miscellaneous Fee</td><td>₱<?= number_format($reviewAssessment['misc_fee'], 2) ?></td></tr>
                <tr><td style="font-weight:600;">Total Assessment</td><td style="font-weight:600;">₱<?= number_format($reviewAssessment['total'], 2) ?></td></tr>
              </table>
              <?php if ($reviewAssessment['voucher_pending']): ?>
                <p class="field-hint" style="margin-top:.6rem;">Voucher-eligible — subject to Records verification. The SHS Voucher subsidy is not deducted here and will apply automatically once the Voucher Eligibility Certificate is verified.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="panel">
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="finalize_preenrollment">
              <div class="proceed-row" style="border-top:none; padding-top:0;">
                <span></span>
                <button type="submit" class="btn-primary" <?= ($reviewAssessment && !$reviewAssessment['fees_configured']) ? 'disabled' : '' ?>>Confirm Enrollment</button>
              </div>
            </form>
          </div>

        <?php elseif ($step === 6): ?>
          <?php if (!$finalSummary): ?>
            <div class="panel"><p class="summary-empty">Nothing to show yet — complete Step 5 first.</p></div>
          <?php else: ?>
            <?php
              $st = $finalSummary['student'];
              $fullName = trim(($st['family_name'] ?? '') . ', ' . ($st['given_name'] ?? '') . (($st['middle_name'] ?? '') ? ' ' . $st['middle_name'] : '') . (($st['suffix'] ?? '') ? ' ' . $st['suffix'] : ''));
              $totalDue = $st['total_due'] !== null ? (float)$st['total_due'] : null;
            ?>
            <div class="summary-print-card">
              <div class="summary-print-head">
                <div>
                  <div class="summary-print-title">Pre-Enrollment Summary</div>
                  <div class="summary-print-sub"><?= htmlspecialchars($st['school_year'] ?? '') ?> · Grade <?= htmlspecialchars($st['grade_level'] ?? '') ?> — <?= htmlspecialchars($st['strand'] ?? '') ?> · <?= htmlspecialchars($st['section_name'] ?? '') ?></div>
                </div>
                <button type="button" class="btn-print no-print" onclick="window.print()">Print</button>
              </div>

              <dl class="student-identity-grid">
                <dt>Full Name</dt><dd><?= htmlspecialchars($fullName) ?></dd>
                <dt>Student Number</dt><dd><?= htmlspecialchars($st['student_number'] ?? '—') ?></dd>
                <dt>Date of Birth</dt><dd><?= htmlspecialchars($st['date_of_birth'] ?? '—') ?></dd>
                <dt>Sex</dt><dd><?= htmlspecialchars($st['sex'] ?? '—') ?></dd>
              </dl>

              <div class="summary-block-title" style="margin-top:1.25rem;">Class Schedule</div>
              <table class="sched-table">
                <thead><tr><th>Subject</th><th>Section</th><th>Day</th><th>Time</th><th>Room</th><th>Teacher</th><th>Type</th></tr></thead>
                <tbody>
                  <?php foreach ($finalSummary['schedule'] as $row): ?>
                    <tr>
                      <td><?= htmlspecialchars($row['subject_name']) ?></td>
                      <td><?= htmlspecialchars($row['section_name']) ?></td>
                      <td><?= htmlspecialchars($row['day'] ?? '—') ?></td>
                      <td><?= $row['start_time'] ? htmlspecialchars(substr($row['start_time'],0,5) . '–' . substr($row['end_time'],0,5)) : '—' ?></td>
                      <td><?= htmlspecialchars($row['room'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($row['teacher_name'] ?? '—') ?></td>
                      <td><?= $row['is_back'] ? 'Back Subject' : 'Current' ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$finalSummary['schedule']): ?>
                    <tr><td colspan="7" class="summary-empty">No scheduled subjects.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>

              <?php if ($finalSummary['credited']): ?>
                <div class="summary-block-title">Credited Subjects (pending verification)</div>
                <ul style="font-size:12.5px; color:#1A1A2E; margin:0 0 1rem 1.1rem;">
                  <?php foreach ($finalSummary['credited'] as $c): ?>
                    <li><?= htmlspecialchars($c['subject_name']) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <div class="summary-block-title">Billing Assessment</div>
              <div class="bill-row"><span>Tuition Fee</span><span>₱<?= number_format($finalSummary['tuition_amount'], 2) ?></span></div>
              <div class="bill-row"><span>Miscellaneous Fee</span><span>₱<?= number_format($finalSummary['misc_fee'], 2) ?></span></div>
              <?php if ($finalSummary['voucher_applied'] > 0): ?>
                <div class="bill-row"><span>Less: SHS Voucher Subsidy</span><span>-₱<?= number_format($finalSummary['voucher_applied'], 2) ?></span></div>
              <?php endif; ?>
              <div class="bill-row total"><span>Total Assessment</span><span><?= $totalDue !== null ? '₱' . number_format($totalDue, 2) : '—' ?></span></div>
              <div class="bill-row" style="margin-top:8px; padding-top:8px; border-top:0.5px dashed #D4D4E0;"><span>Quarterly Installment (4 payments)</span><span>₱<?= number_format($finalSummary['quarterly'], 2) ?> / quarter</span></div>

              <div class="next-step-notice no-print">
                <strong>Next step:</strong> Please proceed to the Cashier's Office to settle payment (in full or by quarterly installment above) to complete enrollment.
              </div>
            </div>

            <div class="no-print" style="margin-top:1rem; display:flex; justify-content:flex-end;">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="reset_wizard">
                <button type="submit" class="btn-primary">Enroll Another Student →</button>
              </form>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <div style="display:flex; gap:8px; margin-top:0.5rem;" class="no-print">
          <?php if ($step > 1 && !$wizard['finalized']): ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="go_to_step">
              <input type="hidden" name="target_step" value="<?= $step - 1 ?>">
              <button type="submit" class="btn-secondary">&larr; Back</button>
            </form>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="reset_wizard">
            <button type="submit" class="btn-secondary">Start Over</button>
          </form>
        </div>
      <?php endif; ?>

    </div>

<?php if ($is_admin): ?>
  </div></div><!-- .content .main -->
<?php else: ?>
    </div>
  </div><!-- .staff-content .staff-main -->
<?php endif; ?>

<script>
// ── Step 2: inline Strand Shift edit toggle ─────────────────────────────
function toggleStrandShift() {
  const row = document.getElementById('strand-shift-row');
  if (!row) return;
  row.style.display = row.style.display === 'none' ? 'block' : 'none';
}

// ── Step 3 (transferee checklist only): live load meter ─────────────────
function updateLoadMeter() {
  const meter = document.getElementById('load-meter');
  if (!meter) return;
  const creditedCount = document.querySelectorAll('input[name="credited_subject_ids[]"]:checked').length;
  const backCount     = document.querySelectorAll('input[name="back_subject_ids[]"]:checked').length;
  const currentTotal  = document.querySelectorAll('input[name="credited_subject_ids[]"]').length;
  const currentToTake = currentTotal - creditedCount;
  const total = currentToTake + backCount;
  meter.textContent = 'Total load: ' + total + ' (min 1, max 8)';
  meter.classList.toggle('over', total < 1 || total > 8);

  const btn = document.getElementById('eval-continue-btn');
  if (btn) btn.disabled = (creditedCount + backCount) < 1;
}
document.querySelectorAll('.load-input').forEach(el => el.addEventListener('change', updateLoadMeter));
updateLoadMeter();

<?php if ($step === 1 && $enrollOpen): ?>
const studentNumberInput = document.getElementById('student-number-input');
const suggestionsBox = document.getElementById('student-number-suggestions');
const resultBox      = document.getElementById('student-number-result');
const searchBtn      = document.getElementById('student-number-search-btn');
const selectForm     = document.getElementById('select-student-form');
const selectedIdInput = document.getElementById('selected-student-id');

let debounceTimer = null;

studentNumberInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  const q = studentNumberInput.value.trim();
  if (q.length < 3) {
    suggestionsBox.classList.add('hidden');
    suggestionsBox.innerHTML = '';
    return;
  }
  debounceTimer = setTimeout(() => fetchSuggestions(q), 250);
});

document.addEventListener('click', (e) => {
  if (!suggestionsBox.contains(e.target) && e.target !== studentNumberInput) {
    suggestionsBox.classList.add('hidden');
  }
});

async function fetchSuggestions(q) {
  try {
    const res = await fetch('../ajax/student_number_autocomplete?q=' + encodeURIComponent(q));
    const data = await res.json();
    renderSuggestions(data.results || []);
  } catch (e) {
    suggestionsBox.classList.add('hidden');
  }
}

function renderSuggestions(results) {
  if (!results.length) {
    suggestionsBox.classList.add('hidden');
    suggestionsBox.innerHTML = '';
    return;
  }
  suggestionsBox.innerHTML = results.map(r => `
    <div class="suggestion-item" data-value="${r.match_value}">
      <span class="suggestion-student-number">${r.match_label}</span>
      <span class="suggestion-name">${r.name}</span>
      <span class="suggestion-meta">Admitted</span>
    </div>
  `).join('');
  suggestionsBox.classList.remove('hidden');

  suggestionsBox.querySelectorAll('.suggestion-item').forEach(el => {
    el.addEventListener('click', () => {
      studentNumberInput.value = el.dataset.value;
      suggestionsBox.classList.add('hidden');
      runSearch(el.dataset.value);
    });
  });
}

searchBtn.addEventListener('click', () => runSearch(studentNumberInput.value.trim()));
studentNumberInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); runSearch(studentNumberInput.value.trim()); }
});

async function runSearch(query) {
  if (!query) return;
  suggestionsBox.classList.add('hidden');
  resultBox.innerHTML = '<p class="page-sub">Searching…</p>';
  selectForm.classList.add('hidden');

  try {
    const res = await fetch('../ajax/student_number_search?q=' + encodeURIComponent(query));
    const data = await res.json();
    renderResult(data);
  } catch (e) {
    resultBox.innerHTML = '<div class="notice notice-error">Something went wrong. Please try again.</div>';
  }
}

function renderResult(data) {
  if (!data.found) {
    resultBox.innerHTML = `
      <div class="student-number-not-found">
        <span>No admitted student found for this Student Number or Control Number.</span>
        <a href="../records/document_review" class="btn-admit-link">Go to Admission</a>
      </div>`;
    return;
  }

  if (data.admission_status !== 'admitted') {
    resultBox.innerHTML = `
      <div class="student-number-not-found">
        <span>${data.name} has pending admission requirements and cannot be enrolled yet.</span>
        <a href="../records/document_review" class="btn-admit-link">Review Requirements</a>
      </div>`;
    return;
  }

  // NOTE: has_active_enrollment here reflects whatever
  // ajax/student_number_search.php considers "active" — that endpoint is
  // outside this file, so we can't be sure it already distinguishes paid
  // ('enrolled') from unpaid ('pre_enrolled'). Rather than hard-dead-end
  // on a flag we don't fully control (which was blocking legitimate
  // pre_enrolled/unpaid students from coming back to change their
  // section), show a non-blocking notice and let the actual gating happen
  // server-side in the select_student handler below, which correctly only
  // blocks a truly 'enrolled' (paid) record and resumes a 'pre_enrolled'
  // one straight into Section Selection.
  const activeNotice = data.has_active_enrollment
    ? `<div class="notice" style="margin:8px 0 0;">This student may already have an enrollment record for this cycle. Continuing will resume/update it if unpaid, or block with an explanation if already paid.</div>`
    : '';

  const studentTypeLabel = { new: 'New', returning: 'Returning', transferee: 'Transferee' }[data.student_type] || data.student_type;

  resultBox.innerHTML = `
    <div class="student-number-found" style="align-items:flex-start; flex-direction:column;">
      <div class="student-number-found-name">${data.name}</div>
      <div class="student-number-found-meta">${data.matched_by === 'control_number' ? 'Control No. ' + data.matched_value : 'Student Number ' + data.matched_value}</div>
      <dl class="student-identity-grid">
        <dt>Student Type</dt><dd>${studentTypeLabel}</dd>
        <dt>Grade</dt><dd>${data.grade_level ? 'Grade ' + data.grade_level : '—'}</dd>
        <dt>Current Strand</dt><dd>${data.strand ?? '—'}</dd>
        <dt>Status</dt><dd>${data.academic_status ?? '—'}</dd>
      </dl>
      ${activeNotice}
      <button type="button" class="btn-primary" style="margin-top:12px;" id="continue-btn">Continue to Enrollment Details</button>
    </div>`;

  document.getElementById('continue-btn').addEventListener('click', () => {
    selectedIdInput.value = data.student_id;
    selectForm.classList.remove('hidden');
    selectForm.submit();
  });
}

const dirSearchInput = document.getElementById('directory-search');
const dirFilterBtn   = document.getElementById('directory-filter-btn');
const dirClearBtn    = document.getElementById('directory-clear-btn');
const dirTableBody   = document.querySelector('#directory-table tbody');
const dirNoResults   = document.getElementById('directory-no-results');

// Server's default (alphabetical) DOM order — the only ordering now
// that the New/Transferee-Returning type pills are gone.
const dirAlphabeticalOrder = Array.from(document.querySelectorAll('#directory-table .directory-row'));

function applyDirectoryFilter() {
  const q = (dirSearchInput?.value || '').trim().toLowerCase();

  let visibleCount = 0;
  dirAlphabeticalOrder.forEach(row => {
    const show = !q || row.dataset.search.includes(q);
    row.classList.toggle('hidden-row', !show);
    if (show) visibleCount++;
  });
  dirNoResults?.classList.toggle('hidden', visibleCount !== 0);
}

dirSearchInput?.addEventListener('input', applyDirectoryFilter);
dirSearchInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyDirectoryFilter(); } });
dirFilterBtn?.addEventListener('click', applyDirectoryFilter);
dirClearBtn?.addEventListener('click', () => {
  if (dirSearchInput) dirSearchInput.value = '';
  applyDirectoryFilter();
});
<?php endif; ?>
</script>

</body>
</html>