<?php
// Site-root URL prefix — lets shared includes and cross-folder redirects
// (e.g. shared/includes/*_sidebar.php, login/logout links) use an
// absolute path that works regardless of how deep the requesting page
// sits under roles/, instead of a relative '../../' chain that breaks
// every time a page moves during the directory reorganization.
if (!defined('APP_URL')) {
    define('APP_URL', '/Enrollment_system');
}

$servername = "localhost";
$username   = "root";
$password   = "";
$database   = "enroll6_db";

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

// Auto-expire enrollments left unpaid for 3+ days — frees the section seat
// (every capacity/occupancy query already excludes non pending/enrolled rows).
mysqli_query($conn, "
    UPDATE enrollments
    SET status = 'expired'
    WHERE status = 'pending'
      AND enrollment_date < (CURDATE() - INTERVAL 3 DAY)
");

/* ─── departments/strands lookup helpers ─────────────────────────────────
   staff.department_id, teachers.department_id/strand, subjects.strand,
   sections.strand and enrollments.admission_strand are int FKs into the
   departments/strands tables. These small caches (6-7 rows each, rarely
   change) let call sites resolve name<->id at the query boundary without
   a round trip per call. */
function department_id(mysqli $conn, string $name): ?int
{
    static $byName = null;
    if ($byName === null) {
        $byName = [];
        $res = mysqli_query($conn, "SELECT department_id, department_name FROM departments");
        while ($row = mysqli_fetch_assoc($res)) {
            $byName[$row['department_name']] = (int)$row['department_id'];
        }
    }
    return $byName[$name] ?? null;
}

function department_name(mysqli $conn, int $id): ?string
{
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        $res = mysqli_query($conn, "SELECT department_id, department_name FROM departments");
        while ($row = mysqli_fetch_assoc($res)) {
            $byId[(int)$row['department_id']] = $row['department_name'];
        }
    }
    return $byId[$id] ?? null;
}

function strand_id(mysqli $conn, string $code): ?int
{
    static $byCode = null;
    if ($byCode === null) {
        $byCode = [];
        $res = mysqli_query($conn, "SELECT strand_id, strand_code FROM strands");
        while ($row = mysqli_fetch_assoc($res)) {
            $byCode[$row['strand_code']] = (int)$row['strand_id'];
        }
    }
    return $byCode[$code] ?? null;
}

function strand_name(mysqli $conn, int $id): ?string
{
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        $res = mysqli_query($conn, "SELECT strand_id, strand_code FROM strands");
        while ($row = mysqli_fetch_assoc($res)) {
            $byId[(int)$row['strand_id']] = $row['strand_code'];
        }
    }
    return $byId[$id] ?? null;
}

// Staff/teacher accounts created with a system-generated temporary password
// must change it before touching anything else. Call this right after the
// existing "not logged in" check, before any HTML output — a header()
// redirect issued after output has started fails silently instead of
// actually redirecting (same reasoning as staff_dashboard.php's own early
// role redirect). $redirectPath is relative to the calling file (e.g.
// "staff_change_password" from staff/, "../staff/staff_change_password"
// from a sibling department folder). $role is 'staff' (default) or
// 'teacher' — the two account types that support a forced first-login
// password change; admin and student accounts don't use this.
function guard_password_change(string $redirectPath, string $role = 'staff'): void
{
    if (($_SESSION['role'] ?? '') === $role && !empty($_SESSION['must_change_password'])) {
        header("Location: $redirectPath");
        exit();
    }
}

// PH SHS convention: a school year starting in June is labeled
// "X-(X+1)" from June through the following May. Used to gate admission
// so an admin-toggled year only actually opens the public form when
// it's genuinely the current one — not a year that hasn't started yet
// or one that's already over.
function current_real_school_year(): string
{
    $y = (int) date('Y');
    $m = (int) date('n');
    return $m >= 6 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
}

// Requirement document uploads — shared by every place a requirement file
// gets uploaded (student/admission.php, records/document_review.php), so
// files created by any of them are indistinguishable to every downstream
// consumer (records/view_document.php). Default constraints; a couple of
// requirement types need something tighter (see req_doc_constraints()
// below), e.g. the 2x2 Picture, which is a photo, not a scanned document —
// no PDFs, and a smaller cap.
const REQ_DOC_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'pdf'];
const REQ_DOC_MAX_BYTES   = 5 * 1024 * 1024; // 5 MB

// Per-requirement-type override of the default upload constraints above,
// matched by exact requirement_name (matches the seeded name in
// requirement_types). Falls back to the site-wide default for everything
// else.
function req_doc_constraints(string $requirement_name): array {
    if ($requirement_name === '2x2 Picture') {
        return [
            'ext'       => ['jpg', 'png'],
            'max_bytes' => 3 * 1024 * 1024, // 3 MB
            'hint'      => 'JPG or PNG — 3 MB max.',
            'accept'    => '.jpg,.png',
        ];
    }
    return [
        'ext'       => REQ_DOC_ALLOWED_EXT,
        'max_bytes' => REQ_DOC_MAX_BYTES,
        'hint'      => 'JPG, PNG, or PDF — 5 MB max.',
        'accept'    => '.pdf,.jpg,.jpeg,.png',
    ];
}


// Semester 1 = Quarters 1+2. Subjects are whole-year in this system
// (not semester-split — see registrar/enrollment.php), so this checks
// every subject the section offers. Only subjects where BOTH quarters
// already have a real Grade Management total are evaluated — a subject
// with no grades yet has nothing to average, so it's silently skipped
// rather than treated as failing. 75 is DepEd's passing grade. Uses
// grade_management_grade() (Quiz/Seatwork/Exam) rather than the old
// Assessment-based gradebook_quarterly_grade(), since Assessment no
// longer collects per-student scores itself — Grade Management is the
// only place real grades get entered now.
function semester1_failing_subjects(mysqli $conn, int $studentId, int $sectionId, string $schoolYear): array
{
    $passingGrade = 75.0;

    $stmt = $conn->prepare("
        SELECT DISTINCT sub.subject_id, sub.subject_name
        FROM section_subjects ss
        JOIN subjects sub ON sub.subject_id = ss.subject_id
        WHERE ss.section_id = ?
        ORDER BY sub.subject_name
    ");
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $failing = [];
    foreach ($subjects as $s) {
        $subjectId = (int) $s['subject_id'];
        $q1 = grade_management_grade($conn, $studentId, $subjectId, $sectionId, $schoolYear, '1')['total'];
        $q2 = grade_management_grade($conn, $studentId, $subjectId, $sectionId, $schoolYear, '2')['total'];
        if ($q1 === null || $q2 === null) continue;

        $average = round(($q1 + $q2) / 2, 2);
        if ($average < $passingGrade) {
            $failing[] = ['subject_name' => $s['subject_name'], 'average' => $average];
        }
    }
    return $failing;
}

// Fixed, no-admin-setup calendar convention mapping a quarter to a
// 3-month window of the school year (independent of the DepEd
// Written Work/Performance Task/Quarterly Assessment weights used
// elsewhere in this file): Q1=Jun-Aug, Q2=Sep-Nov, Q3=Dec-Feb,
// Q4=Mar-May. Used only to bucket gradebook_attendance's raw
// session_date rows into a quarter for grade_management_grade() —
// Assessment/Grade Management's own gradebook_items.quarter column is
// unaffected and still used directly for everything else.
function quarter_calendar_range(string $schoolYear, string $quarter): array
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m)) {
        return [null, null];
    }
    $y1 = (int) $m[1];
    $y2 = (int) $m[2];
    $ranges = [
        '1' => ["$y1-06-01", "$y1-08-31"],
        '2' => ["$y1-09-01", "$y1-11-30"],
        '3' => ["$y1-12-01", (new DateTime("$y2-02-01"))->modify('last day of this month')->format('Y-m-d')],
        '4' => ["$y2-03-01", "$y2-05-31"],
    ];
    return $ranges[$quarter] ?? [null, null];
}

// Maps a DepEd quarter to the semester it falls in — Q1/Q2 are Semester 1,
// Q3/Q4 are Semester 2. Used to scope teacher_assignments ownership checks
// to the right semester's teacher for a given quarter's grade/score data.
function quarter_to_semester(string $quarter): int
{
    return in_array($quarter, ['1', '2'], true) ? 1 : 2;
}

// Same semester boundary as quarter_calendar_range()'s Q1+Q2 vs Q3+Q4 split,
// but keyed off a plain date instead of a quarter — used where the action
// (e.g. attendance) is tied to a calendar date rather than a quarter.
function semester_for_date(string $schoolYear, string $date): int
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m)) {
        return 1;
    }
    $y1 = (int) $m[1];
    $sem1End = new DateTime("$y1-11-30");
    $d = new DateTime($date);
    return $d <= $sem1End ? 1 : 2;
}

// True if any applicable requirement row for this enrollment isn't
// 'submitted' yet — required or optional. Semester 2 continuation blocks
// on any outstanding item, a stricter standard than admission completion
// (records/document_review.php's dr_finalize_admission(), which only
// requires is_required=1 rows) since by Semester 2 a student has had a
// full term to clear even optional accountabilities.
function has_outstanding_accountabilities(mysqli $conn, int $enrollment_id, int $jhsIsPublic): bool
{
    $stmt = $conn->prepare("
        SELECT er.status, rt.applicable_to
        FROM enrollment_requirements er
        JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
        WHERE er.enrollment_id = ? AND rt.is_active = 1
    ");
    $stmt->bind_param('i', $enrollment_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $r) {
        if ($r['applicable_to'] === 'public_jhs_only' && !$jhsIsPublic) { continue; }
        if ($r['status'] !== 'submitted') {
            return true;
        }
    }
    return false;
}

// Same "block Semester 2 on anything still owed" spirit as
// has_outstanding_accountabilities() above, but for the payment side —
// total_due vs. sum of payments on file. A small epsilon avoids floating
// point rounding on a genuinely-settled balance reading as still owing.
function has_outstanding_balance(mysqli $conn, int $enrollment_id): bool
{
    $stmt = $conn->prepare("
        SELECT e.total_due, COALESCE(SUM(p.amount), 0) AS paid
        FROM enrollments e
        LEFT JOIN payments p ON p.enrollment_id = e.enrollment_id
        WHERE e.enrollment_id = ?
        GROUP BY e.enrollment_id, e.total_due
    ");
    $stmt->bind_param('i', $enrollment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['total_due'] === null) {
        return false;
    }

    $balance = round((float) $row['total_due'] - (float) $row['paid'], 2);
    return $balance > 0.01;
}

// ── Online payment simulation (GCash / Bank Transfer) ───────────────────
// Staging table only — a submission here never touches `payments` (the
// trusted ledger every balance calc reads from) until Treasury confirms
// it. Auto-created here (loaded on every page) rather than duplicated in
// each of the two pages that use it.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS online_payment_submissions (
        submission_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id     INT NOT NULL,
        amount            DECIMAL(10,2) NOT NULL,
        payment_method    ENUM('GCash','Bank Transfer','Card','Maya','GrabPay') NOT NULL,
        reference_no      VARCHAR(50) NOT NULL,
        status            ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
        submitted_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by       INT UNSIGNED NULL,
        reviewed_at       TIMESTAMP NULL,
        rejection_reason  VARCHAR(255) NULL,
        FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id)
    )
");

// Cosmetic simulation reference number only (e.g. "SIM-GC-20260830-A1B2")
// — not a real financial identifier, so no strict sequence/uniqueness
// guarantee is needed.
function generate_sim_reference(string $prefix): string
{
    return 'SIM-' . strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

// Grade Management's Total — Attendance 10% / Quizzes 10% / Seatwork
// 10% / Assignment 10% / Performance Task 20% / Exam 40%, independent
// of the DepEd Written Work/Performance Task/Quarterly Assessment
// weights used elsewhere in this file.
//
// The 5 item-based groups are real gradebook_items (the exact same
// items/scores Assessment manages), classified as:
//   is_quiz=1                                        -> Quizzes
//   category='quarterly_assessment' (non-quiz)        -> Exam
//   category='performance_task' (non-quiz)            -> Performance Task
//   category='written_work', accepts_submission=1     -> Assignment
//   category='written_work', accepts_submission=0     -> Seatwork
// Each group's percent is sum-of-points-earned / sum-of-points-possible
// across that group's scored items — so a perfect group always caps at
// exactly its own weight, never more (no additive-per-item overflow).
//
// Attendance has no quarter of its own (gradebook_attendance only
// carries a raw session_date — see quarter_calendar_range() above) —
// its percent is the average of that quarter's P/L/A entries, scored
// Present=100, Late=95 (a flat 5-point deduction), Absent=0.
//
// Shared by teacher_grade_management.php, ajax/teacher_save_score.php
// (so a save recomputes the identical Total/Remarks), and
// student_grades.php. Returns remarks='no_record' only when
// absolutely nothing has been entered anywhere for this student yet.
function grade_management_grade(mysqli $conn, int $studentId, int $subjectId, int $sectionId, string $schoolYear, string $quarter): array
{
    $stmt = $conn->prepare("
        SELECT
          CASE WHEN gi.is_quiz = 1 THEN 'quiz'
               WHEN gi.category = 'quarterly_assessment' THEN 'exam'
               WHEN gi.category = 'performance_task' THEN 'performance_task'
               WHEN gi.accepts_submission = 1 THEN 'assignment'
               ELSE 'seatwork' END AS grp,
          SUM(gs.raw_score) AS earned,
          SUM(gi.max_score) AS possible
        FROM gradebook_items gi
        JOIN gradebook_scores gs ON gs.item_id = gi.item_id AND gs.student_id = ?
        WHERE gi.subject_id = ? AND gi.section_id = ? AND gi.school_year = ? AND gi.quarter = ?
          AND gs.raw_score IS NOT NULL
        GROUP BY grp
    ");
    $stmt->bind_param('iiiss', $studentId, $subjectId, $sectionId, $schoolYear, $quarter);
    $stmt->execute();
    $byGroup = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $possible = (float) $row['possible'];
        $byGroup[$row['grp']] = $possible > 0 ? ((float) $row['earned'] / $possible) * 100 : null;
    }
    $stmt->close();

    $quizPct   = $byGroup['quiz']              ?? null;
    $seatPct   = $byGroup['seatwork']           ?? null;
    $assignPct = $byGroup['assignment']         ?? null;
    $ptPct     = $byGroup['performance_task']   ?? null;
    $examPct   = $byGroup['exam']               ?? null;

    // Whether the CLASS has any items in each of the 5 buckets at all
    // (student-independent) — a category with items that simply
    // haven't been scored yet for this one student still counts as 0
    // below; only a category with zero items for the whole class gets
    // excluded and has its weight redistributed to the others.
    $exists_stmt = $conn->prepare("
        SELECT DISTINCT
          CASE WHEN gi.is_quiz = 1 THEN 'quiz'
               WHEN gi.category = 'quarterly_assessment' THEN 'exam'
               WHEN gi.category = 'performance_task' THEN 'performance_task'
               WHEN gi.accepts_submission = 1 THEN 'assignment'
               ELSE 'seatwork' END AS grp
        FROM gradebook_items gi
        WHERE gi.subject_id = ? AND gi.section_id = ? AND gi.school_year = ? AND gi.quarter = ?
    ");
    $exists_stmt->bind_param('iiss', $subjectId, $sectionId, $schoolYear, $quarter);
    $exists_stmt->execute();
    $classHas = array_column($exists_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'grp');
    $exists_stmt->close();

    [$rangeStart, $rangeEnd] = quarter_calendar_range($schoolYear, $quarter);
    $attPct = null;
    if ($rangeStart !== null) {
        $att_stmt = $conn->prepare("
            SELECT value, COUNT(*) AS c FROM gradebook_attendance
            WHERE subject_id=? AND section_id=? AND school_year=? AND student_id=?
              AND session_date BETWEEN ? AND ?
            GROUP BY value
        ");
        $att_stmt->bind_param('iisiss', $subjectId, $sectionId, $schoolYear, $studentId, $rangeStart, $rangeEnd);
        $att_stmt->execute();
        $attRows = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $att_stmt->close();

        $valueScore = ['P' => 100.0, 'L' => 95.0, 'A' => 0.0];
        $totalCount = 0;
        $totalScore = 0.0;
        foreach ($attRows as $row) {
            $score = $valueScore[$row['value']] ?? null;
            if ($score === null) continue;
            $c = (int) $row['c'];
            $totalScore += $score * $c;
            $totalCount += $c;
        }
        if ($totalCount > 0) {
            $attPct = $totalScore / $totalCount;
        }
    }

    // Sub-percentages are exposed too (not just total/remarks) so
    // teacher_grade_management.php can display each component (e.g. the
    // Attendance column) without re-running these queries itself.
    $parts = [
        'attendance'        => $attPct,
        'quiz'              => $quizPct,
        'seatwork'          => $seatPct,
        'assignment'        => $assignPct,
        'performance_task'  => $ptPct,
        'exam'              => $examPct,
    ];

    if ($quizPct === null && $seatPct === null && $assignPct === null && $ptPct === null && $examPct === null && $attPct === null) {
        return ['total' => null, 'remarks' => 'no_record'] + $parts;
    }

    // Dynamic weight redistribution: a category the teacher never uses
    // at all (zero items for the whole class) is excluded entirely
    // rather than silently scored as 0 — its weight is redistributed
    // proportionally among the categories that do exist, so a student
    // who aces everything actually assigned gets a total near 100, not
    // dragged down by categories the teacher simply never assigned.
    // Attendance "exists" per-student (see $attPct above) since it's
    // recorded per session for the whole class already.
    $weights = [
        'quiz'             => 0.10,
        'seatwork'         => 0.10,
        'assignment'       => 0.10,
        'performance_task' => 0.20,
        'exam'             => 0.40,
    ];
    $pcts = [
        'quiz'             => $quizPct,
        'seatwork'         => $seatPct,
        'assignment'       => $assignPct,
        'performance_task' => $ptPct,
        'exam'             => $examPct,
    ];

    $activeWeight = $attPct !== null ? 0.10 : 0.0;
    foreach ($weights as $grp => $w) {
        if (in_array($grp, $classHas, true)) {
            $activeWeight += $w;
        }
    }
    if ($activeWeight <= 0.0) {
        return ['total' => null, 'remarks' => 'no_record'] + $parts;
    }

    $weightedSum = ($attPct !== null ? $attPct * 0.10 : 0.0);
    foreach ($weights as $grp => $w) {
        if (in_array($grp, $classHas, true)) {
            $weightedSum += ($pcts[$grp] ?? 0.0) * $w;
        }
    }

    $total = round(
        $weightedSum / $activeWeight,
        2
    );
    return ['total' => $total, 'remarks' => $total >= 75 ? 'passed' : 'failed'] + $parts;
}

// ── Login brute-force throttling ─────────────────────────────────────────
// Shared by every login entry point (login.php, teacherportal/teacher_login.php,
// studentportal/student_login.php, learningportal/lms_login.php) — tracks
// failed attempts per username+IP so repeated wrong-password guesses get
// locked out instead of being retryable instantly forever.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(100) NOT NULL,
        ip_address   VARCHAR(45) NOT NULL,
        succeeded    TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (username, ip_address, attempted_at)
    )
");

const LOGIN_THROTTLE_MAX_ATTEMPTS = 5;
const LOGIN_THROTTLE_WINDOW_MIN   = 15;

// Call before verifying a password. Returns a human-readable lockout
// message if this username+IP has too many recent failed attempts, or ''
// if the login attempt may proceed.
function login_throttle_check(mysqli $conn, string $username, string $ip): string
{
    $cutoff = date('Y-m-d H:i:s', time() - LOGIN_THROTTLE_WINDOW_MIN * 60);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS failures
        FROM login_attempts
        WHERE username = ? AND ip_address = ? AND succeeded = 0 AND attempted_at > ?
    ");
    $stmt->bind_param('sss', $username, $ip, $cutoff);
    $stmt->execute();
    $failures = (int) ($stmt->get_result()->fetch_assoc()['failures'] ?? 0);
    $stmt->close();

    return $failures >= LOGIN_THROTTLE_MAX_ATTEMPTS
        ? 'Too many failed login attempts. Please try again in ' . LOGIN_THROTTLE_WINDOW_MIN . ' minutes.'
        : '';
}

// Call after every login attempt (success or failure) to record it.
function login_throttle_record(mysqli $conn, string $username, string $ip, bool $succeeded): void
{
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, succeeded) VALUES (?, ?, ?)");
    $succ = $succeeded ? 1 : 0;
    $stmt->bind_param('ssi', $username, $ip, $succ);
    $stmt->execute();
    $stmt->close();
}
?>