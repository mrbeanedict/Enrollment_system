<?php
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
// actually redirecting (same reasoning as roles/staff/dashboard.php's own
// early role redirect). $redirectPath is either a same-directory filename
// (e.g. "change_password" from roles/staff/ itself) or an APP_URL-prefixed
// absolute path (e.g. APP_URL . '/roles/staff/change_password' from a
// department page elsewhere under roles/staff/). $role is 'staff'
// (default) or 'teacher' — the two account types that support a forced
// first-login password change; admin and student accounts don't use this.
function guard_password_change(string $redirectPath, string $role = 'staff'): void
{
    if (($_SESSION['role'] ?? '') === $role && !empty($_SESSION['must_change_password'])) {
        header("Location: $redirectPath");
        exit();
    }
}

// Small colored file-type badge (PDF/DOC/PPT/image/generic) shown next to
// an attachment's name in place of a bare paperclip emoji — shared by every
// place an already-uploaded file (lesson attachment, assignment submission)
// is linked back to a teacher or student. The CSS classes it returns
// (attach-icon-*) are defined in both css_teacher.css and css_lms.css.
function file_icon_meta(string $filename): array
{
    $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'PDF') {
        $cls = 'attach-icon-pdf';
    } elseif (in_array($ext, ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP'], true)) {
        $cls = 'attach-icon-img';
    } elseif (in_array($ext, ['DOC', 'DOCX'], true)) {
        $cls = 'attach-icon-doc';
    } elseif (in_array($ext, ['PPT', 'PPTX'], true)) {
        $cls = 'attach-icon-ppt';
    } else {
        $cls = 'attach-icon-generic';
    }
    return ['label' => $ext, 'cls' => $cls];
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
// gets uploaded (roles/student/public/admission.php, roles/staff/records/document_review.php), so
// files created by any of them are indistinguishable to every downstream
// consumer (roles/staff/records/view_document.php). Default constraints; a couple of
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

// Extension checks alone only look at the client-supplied filename, which
// is trivial to spoof (e.g. renaming evil.php to evil.jpg). This adds a
// magic-byte/MIME check on the actual uploaded content as defense-in-depth
// — call it after the extension whitelist check, before move_uploaded_file().
// Covers every extension accepted by any upload handler in this app.
// docx/pptx/zip all share the same "application/zip" magic bytes (an
// OOXML file is a zip container), so that's accepted for all three rather
// than rejecting legitimate docx/pptx uploads.
function upload_content_matches_ext(string $tmp_name, string $ext): bool
{
    $mime = @mime_content_type($tmp_name);
    $allowed = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-cfb'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-cfb'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'zip'  => ['application/zip'],
    ];
    return isset($allowed[$ext]) && in_array($mime, $allowed[$ext], true);
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
// (roles/staff/records/document_review.php's dr_finalize_admission(), which only
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
// it. Schema lives in schema/online_payment_submissions.sql (the table
// already exists in the live database; this file used to CREATE TABLE IF
// NOT EXISTS on every page load, which was pure overhead once the table
// existed).

// Cosmetic simulation reference number only (e.g. "SIM-GC-20260830-A1B2")
// — not a real financial identifier, so no strict sequence/uniqueness
// guarantee is needed.
function generate_sim_reference(string $prefix): string
{
    return 'SIM-' . strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}
