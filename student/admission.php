<?php
// Public online admission form — no login required. session_start() here is
// used for: a single-use anti-replay token, a same-session resubmission
// cooldown, a honeypot flag, and (on successful submit) a short-lived
// adm_success flash so this same page can show an inline confirmation after
// a Post/Redirect/Get, without a separate slip page. Note there's no control
// number to carry in that flash — Records assigns one later, once a
// student's requirements are fully verified and they're marked admitted
// (see records/document_review.php), not here. This session must NEVER be
// used to check $_SESSION['user_id'] like the staff pages do, and no other
// application field data is ever stored in the session — every other field
// is only echoed back from $_POST within the same request (old() helper
// below).
session_start();
include('../config.php');
require_once '../config/mail.php';

$allowed_grades  = ['11', '12'];
$allowed_strands = ['STEM', 'HUMSS', 'ABM', 'GAS', 'TVL-ICT'];

// Requirement document upload constraints (REQ_DOC_ALLOWED_EXT,
// REQ_DOC_MAX_BYTES, req_doc_constraints()) now live in config.php, shared
// with records/document_review.php's staff-side upload path.

// ── Admission window + current school year (admin-controlled) ─────────────
// No hard-coded cutoff date — admin toggles is_admission_open in
// school_year_settings. Prefer whichever row is actually open right now;
// school_year is only a tiebreaker, not the primary signal — sorting by
// school_year alone would pick a newer-but-still-closed row (e.g. admin
// added 2027-2028 but hasn't opened it yet) over the real open cycle,
// same bug already fixed the same way in registrar/enrollment.php.
$sys_stmt = $conn->prepare(
    "SELECT school_year, is_admission_open
     FROM school_year_settings
     ORDER BY is_admission_open DESC, school_year DESC
     LIMIT 1"
);
$sys_stmt->execute();
$sys = $sys_stmt->get_result()->fetch_assoc();
$sys_stmt->close();

// Admin-open alone isn't enough — the open row must also be the real
// current school year, so a future year toggled on too early (or a past
// year left on by mistake) doesn't actually open the public form.
$admission_open = $sys
    && (int) $sys['is_admission_open'] === 1
    && $sys['school_year'] === current_real_school_year();
$school_year    = $sys['school_year'] ?? current_real_school_year();

// ── Requirement types ──────────────────────────────────────────────────────
// applicable_to ('all' or 'public_jhs_only') gates which types apply to a
// given student. is_required is label-only here — it decides whether a "*"
// is shown next to a requirement's name, nothing more; uploads at this
// stage stay optional regardless of is_required (see the validation block
// below), since applicants may not have scans ready yet and can still bring
// documents to the Records Office later. This unfiltered list (all active
// types) is used for rendering the upload section itself, since visibility
// of public_jhs_only rows there is a live client-side toggle, not something
// PHP can know for certain at render time.
function load_requirement_types($conn) {
    $out = [];
    $res = $conn->query(
        "SELECT requirement_type_id, requirement_name, applicable_to, is_required
         FROM requirement_types
         WHERE is_active = 1
         ORDER BY display_order ASC"
    );
    while ($res && ($row = $res->fetch_assoc())) $out[] = $row;
    return $out;
}

// Filtered by the real jhs_is_public value submitted — the single source
// of truth for which requirement types actually apply to a given
// submission, used at file-validation and DB-insert time.
function load_requirement_types_for($conn, $jhs_is_public) {
    $out = [];
    $applicable = $jhs_is_public
        ? "applicable_to IN ('all', 'public_jhs_only')"
        : "applicable_to = 'all'";
    $sql = "SELECT requirement_type_id, requirement_name, applicable_to, is_required
            FROM requirement_types
            WHERE is_active = 1
              AND $applicable
            ORDER BY display_order ASC";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) $out[] = $row;
    return $out;
}

// Re-verifies a submitted province/city/barangay code triple against the
// PSGC reference tables server-side — the cascading dropdowns are a UX
// convenience, not the real gate, same principle as every other lookup
// endpoint in this app. Returns the canonical names to store (never the
// client-echoed <option> text) or null if the codes don't form a real,
// consistent chain (missing, tampered, or the barangay/city/province
// don't actually nest under each other).
function resolve_psgc(mysqli $conn, string $provinceCode, string $cityCode, string $brgyCode): ?array {
    if ($provinceCode === '' || $cityCode === '' || $brgyCode === '') return null;
    $stmt = $conn->prepare(
        "SELECT p.province_name, c.city_name, b.brgy_name
         FROM psgc_barangays b
         JOIN psgc_cities c ON c.city_code = b.city_code
         JOIN psgc_provinces p ON p.province_code = c.province_code
         WHERE b.brgy_code = ? AND b.city_code = ? AND c.province_code = ?
         LIMIT 1"
    );
    $stmt->bind_param('sss', $brgyCode, $cityCode, $provinceCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ── State ──────────────────────────────────────────────────────────────────
$errors              = [];
$old                 = [];
$new_enrollment_id   = null;
$blocked_silently    = false; // honeypot / replay / cooldown — never shown as a specific reason
$requirementTypes    = load_requirement_types($conn); // used by the GET-render form and by POST error redisplay

// ── Pre-fill strand from a link like admission.php?strand=STEM ────────────
// Only applies on the initial GET load (e.g. clicking a strand card on the
// homepage); a POST always uses $_POST via old(), so this can't be used to
// override a submitted value.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['strand'])) {
    $requested_strand = trim($_GET['strand']);
    if (in_array($requested_strand, $allowed_strands, true)) {
        $old['strand'] = $requested_strand;
    }
}

// ── Anti-abuse pre-checks (token, cooldown, honeypot) ──────────────────────
// These run before normal field validation and never reveal which check
// failed — bots and replayed requests just get the same generic error as a
// stale/expired session would.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admission_open) {
    $old = $_POST;

    $given_token = $_POST['adm_token'] ?? '';
    $token_valid = !empty($_SESSION['adm_token']) && hash_equals($_SESSION['adm_token'], $given_token);

    $cooldown_ok = (time() - (int) ($_SESSION['adm_last_submit'] ?? 0)) >= 20;

    $honeypot_filled = trim($_POST['website'] ?? '') !== '';

    if (!$token_valid || !$cooldown_ok || $honeypot_filled) {
        $blocked_silently = true;
        error_log(sprintf(
            '[admission.php] blocked submission — token_valid=%s cooldown_ok=%s honeypot=%s ip=%s',
            $token_valid ? '1' : '0', $cooldown_ok ? '1' : '0', $honeypot_filled ? '1' : '0',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));
    }
}


// Only generate once per session, not on every execution — both success
// paths below already unset() this to force a genuinely fresh token after
// a real submission ("so this exact POST can't be replayed"). Regenerating
// unconditionally here defeated that: a failed POST, a duplicate tab, or a
// double-click on Submit would each re-render the page with a *new* token
// while the session had already moved on to whichever request ran last,
// so the next submit's token never matched — a false "something went
// wrong" even though nothing was actually wrong with the submission.
if (empty($_SESSION['adm_token'])) {
    $_SESSION['adm_token'] = bin2hex(random_bytes(32));
}

if ($blocked_silently) {
    $errors['_db'] = 'Something went wrong while submitting your application. Please reload the page and try again.';
}



// ── POST handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admission_open && !$blocked_silently) {

    // ── Collect raw ────────────────────────────────────────────────────────
    $grade_level   = trim($_POST['gradeLevel'] ?? '');
    $strand        = trim($_POST['strand']     ?? '');
    // Student type is derived from grade level and is NOT user-toggleable:
    //   Grade 11 -> new student (is_transferee = 0)
    //   Grade 12 -> transferee  (is_transferee = 1)
    // The form only shows this as a locked/read-only indicator; the value
    // actually used server-side is always recomputed here from gradeLevel,
    // so a tampered hidden field can't change the outcome.
    $student_type  = ($grade_level === '12') ? 'transferee' : 'new';
    $is_transferee = ($student_type === 'transferee') ? 1 : 0;
    $is_new_student = ($student_type === 'new') ? 1 : 0;

    $given_name    = trim($_POST['givenName']       ?? '');
    $middle_name   = trim($_POST['middleName']      ?? '');
    $family_name   = trim($_POST['familyName']      ?? '');
    $suffix        = trim($_POST['suffix']          ?? '');
    $birth_date    = trim($_POST['birthDate']       ?? '');
    $sex           = trim($_POST['sex']             ?? '');
    $civil_status  = trim($_POST['civilStatus']     ?? 'Single');
    $nationality   = trim($_POST['nationality']     ?? '');
    $full_address  = trim($_POST['fullAddress']     ?? '');
    $province_code = trim($_POST['studentProvince'] ?? '');
    $city_code     = trim($_POST['studentCity']     ?? '');
    $brgy_code     = trim($_POST['studentBarangay']  ?? '');
    $email         = trim($_POST['email']           ?? '');
    $jhs_school    = trim($_POST['schoolName']      ?? '');
    $jhs_year      = trim($_POST['yearGraduated']   ?? '');
    $jhs_public    = isset($_POST['jhsIsPublic']) ? 1 : 0;

    $g_first_name  = trim($_POST['guardianFirstName']  ?? '');
    $g_middle_name = trim($_POST['guardianMiddleName'] ?? '');
    $g_last_name   = trim($_POST['guardianLastName']   ?? '');
    $g_suffix      = trim($_POST['guardianSuffix']     ?? '');
    $relationship  = trim($_POST['relationship']       ?? '');
    $student_mobile = trim($_POST['studentMobile']     ?? '');
    $occupation    = trim($_POST['occupation']         ?? '');
    $g_mobile      = trim($_POST['guardianMobile']     ?? '');
    $same_address  = isset($_POST['sameAddress']) ? 1 : 0;
    $g_address       = $same_address ? $full_address : trim($_POST['guardianAddress'] ?? '');
    $g_province_code = $same_address ? $province_code : trim($_POST['guardianProvince'] ?? '');
    $g_city_code     = $same_address ? $city_code     : trim($_POST['guardianCity']     ?? '');
    $g_brgy_code     = $same_address ? $brgy_code     : trim($_POST['guardianBarangay']  ?? '');


    // ── Validate ───────────────────────────────────────────────────────────
    $nameRegex = "/^[A-Za-zÀ-ÿ' .\-]+$/u";

    if (!in_array($grade_level, $allowed_grades))
        $errors['gradeLevel'] = 'Please select a grade level.';

    if (!in_array($strand, $allowed_strands))
        $errors['strand'] = 'Please select a strand.';

    // Student name
    if ($given_name === '') {
        $errors['givenName'] = 'First name is required.';
    } elseif (!preg_match($nameRegex, $given_name)) {
        $errors['givenName'] = 'First name cannot contain numbers or special characters.';
    }

    if ($middle_name !== '' && !preg_match($nameRegex, $middle_name))
        $errors['middleName'] = 'Middle name cannot contain numbers or special characters.';

    if ($family_name === '') {
        $errors['familyName'] = 'Last name is required.';
    } elseif (!preg_match($nameRegex, $family_name)) {
        $errors['familyName'] = 'Last name cannot contain numbers or special characters.';
    }

    if ($suffix !== '' && !preg_match('/^[A-Za-z.]+$/', $suffix))
        $errors['suffix'] = 'Enter a valid suffix (e.g. Jr., Sr., III).';

    // Date of birth
    if ($birth_date === '') {
        $errors['birthDate'] = 'Date of birth is required.';
    } elseif (strtotime($birth_date) === false) {
        $errors['birthDate'] = 'Enter a valid date of birth.';
    } else {
        $birth = new DateTime($birth_date);
        $today = new DateTime();
        if ($birth >= $today) {
            $errors['birthDate'] = 'Date of birth cannot be today or a future date.';
        } else {
            $age = $today->diff($birth)->y;
            if ($age < 13)
                $errors['birthDate'] = 'Student must be at least 13 years old.';
            elseif ($age > 100)
                $errors['birthDate'] = 'Enter a valid date of birth.';
        }
    }

    // Sex
    if (!in_array($sex, ['Male', 'Female']))
        $errors['sex'] = 'Please select a sex.';

    // Nationality
    if ($nationality === '') {
        $errors['nationality'] = 'Nationality is required.';
    } elseif (!preg_match($nameRegex, $nationality)) {
        $errors['nationality'] = 'Nationality cannot contain numbers or special characters.';
    }

    // Address — student_addresses.address_line (the house no./street line)
    // is varchar(100), so the cap here must match the column width.
    // Province/city/barangay now come from the cascading PSGC dropdowns
    // (psgc_provinces/psgc_cities/psgc_barangays) instead of being folded
    // into one free-text line — resolve_psgc() below re-verifies the
    // submitted codes server-side (never trusts client-echoed names) and
    // is also what determines whether barangay/city/province end up NULL
    // vs populated on student_addresses/student_guardians.
    if ($full_address === '') {
        $errors['fullAddress'] = 'House number / street is required.';
    } elseif (strlen($full_address) > 100) {
        $errors['fullAddress'] = 'Address is too long (max 100 characters).';
    }

    $student_psgc = resolve_psgc($conn, $province_code, $city_code, $brgy_code);
    if (!$student_psgc) {
        $errors['studentBarangay'] = 'Please select your province, city/municipality, and barangay.';
    }

    // Guardian address — only collected/validated when it differs from the
    // student's; student_guardians.full_address is also varchar(100).
    $guardian_psgc = null;
    if (!$same_address) {
        if ($g_address === '') {
            $errors['guardianAddress'] = 'Guardian address is required (or check "Same as student address").';
        } elseif (strlen($g_address) > 100) {
            $errors['guardianAddress'] = 'Address is too long (max 100 characters).';
        }

        $guardian_psgc = resolve_psgc($conn, $g_province_code, $g_city_code, $g_brgy_code);
        if (!$guardian_psgc) {
            $errors['guardianBarangay'] = 'Please select the guardian\'s province, city/municipality, and barangay (or check "Same as student address").';
        }
    } else {
        $guardian_psgc = $student_psgc;
    }

    // Email — required
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    // Student mobile — optional, but must be a valid PH mobile if given
    if ($student_mobile !== '' && !preg_match('/^09\d{9}$/', $student_mobile))
        $errors['studentMobile'] = 'Enter a valid PH mobile number (09XXXXXXXXX).';

    // JHS
    if ($jhs_school === '') {
        $errors['schoolName'] = 'Junior high school name is required.';
    } elseif (mb_strlen($jhs_school) < 3) {
        $errors['schoolName'] = 'School name must be at least 3 characters.';
    } elseif (mb_strlen($jhs_school) > 100) {
        $errors['schoolName'] = 'School name cannot exceed 100 characters.';
    } elseif (preg_match('/\d/', $jhs_school)) {
        $errors['schoolName'] = 'School name cannot contain numbers.';
    } elseif (!preg_match("/^[\p{L} .,'()\-]+$/u", $jhs_school)) {
        $errors['schoolName'] = 'School name contains invalid characters.';
    }

    if ($jhs_year === '') {
        $errors['yearGraduated'] = 'Year graduated is required.';
    } elseif (!preg_match('/^\d{4}$/', $jhs_year)
              || (int) $jhs_year < 2000
              || (int) $jhs_year > (int) date('Y')) {
        $errors['yearGraduated'] = 'Enter a valid 4-digit graduation year (2000–' . date('Y') . ').';
    }

    // Guardian name
    if ($g_first_name === '') {
        $errors['guardianFirstName'] = 'Guardian first name is required.';
    } elseif (!preg_match($nameRegex, $g_first_name)) {
        $errors['guardianFirstName'] = 'Guardian first name cannot contain numbers or special characters.';
    }

    if ($g_middle_name !== '' && !preg_match($nameRegex, $g_middle_name))
        $errors['guardianMiddleName'] = 'Guardian middle name cannot contain numbers or special characters.';

    if ($g_last_name === '') {
        $errors['guardianLastName'] = 'Guardian last name is required.';
    } elseif (!preg_match($nameRegex, $g_last_name)) {
        $errors['guardianLastName'] = 'Guardian last name cannot contain numbers or special characters.';
    }

    if ($g_suffix !== '' && !preg_match('/^[A-Za-z.]+$/', $g_suffix))
        $errors['guardianSuffix'] = 'Enter a valid suffix (e.g. Jr., Sr., III).';

    $allowed_relationships = ['Parent', 'Relative', 'Legal Guardian', 'Other'];
    if ($relationship === '' || !in_array($relationship, $allowed_relationships, true)) {
        $errors['relationship'] = 'Please select a relationship to student.';
    }

    if ($occupation === '') {
        $errors['occupation'] = 'Occupation is required.';
    } elseif (!preg_match("/^[A-Za-zÀ-ÿ' .\-\/]+$/u", $occupation)) {
        $errors['occupation'] = 'Occupation cannot contain numbers or special characters.';
    }

    if ($g_mobile === '') {
        $errors['guardianMobile'] = 'Mobile number is required.';
    } elseif (!preg_match('/^09\d{9}$/', $g_mobile)) {
        $errors['guardianMobile'] = 'Enter a valid PH mobile number (09XXXXXXXXX).';
    }

    // ── Normalize casing (only if no errors on those fields) ──────────────
    if (!isset($errors['givenName']))      $given_name    = mb_convert_case($given_name,    MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['middleName']))     $middle_name   = mb_convert_case($middle_name,   MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['familyName']))     $family_name   = mb_convert_case($family_name,   MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['suffix']))         $suffix        = strtoupper($suffix);
    if (!isset($errors['nationality']))    $nationality   = mb_convert_case($nationality,   MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['schoolName']))     $jhs_school    = mb_convert_case($jhs_school,    MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['guardianFirstName'])) $g_first_name  = mb_convert_case($g_first_name,  MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['guardianMiddleName'])) $g_middle_name = mb_convert_case($g_middle_name, MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['guardianLastName']))  $g_last_name   = mb_convert_case($g_last_name,   MB_CASE_TITLE, 'UTF-8');
    if (!isset($errors['guardianSuffix']))    $g_suffix      = strtoupper($g_suffix);
    if (!isset($errors['occupation']))    $occupation    = mb_convert_case($occupation,    MB_CASE_TITLE, 'UTF-8');

    // ── Requirement uploads (optional — validate only what was attached) ────
    // Uploads are optional at this stage (staff verify documents in person
    // later); only files that were actually attached are validated, and
    // only for requirement types that actually apply to this student
    // (gated by the jhs_is_public value just submitted) — a stray file left
    // in a now-hidden/inapplicable input (e.g. VEC after unchecking "public
    // JHS") is ignored, since no enrollment_requirements row will exist for
    // it anyway. A file that WAS attached with a bad extension or oversized
    // IS a hard error — never silently dropped, since that would mislead
    // the applicant into thinking it was received.
    $applicable_req_types  = load_requirement_types_for($conn, $jhs_public);
    $uploaded_requirements = []; // requirement_type_id => [tmp_name, ext, original_name]

    foreach ($applicable_req_types as $rt) {
        $rid         = (int) $rt['requirement_type_id'];
        $constraints = req_doc_constraints($rt['requirement_name']);

        $file_name     = $_FILES['requirements']['name'][$rid]  ?? '';
        $file_error    = $_FILES['requirements']['error'][$rid] ?? UPLOAD_ERR_NO_FILE;
        // "Submit later" is a per-requirement acknowledgement, not a file —
        // uploads themselves stay optional (see note above), but the
        // applicant must make an explicit choice for every applicable
        // requirement: attach it now, or check this box to confirm they'll
        // bring it to the Records Office in person. Prevents a requirement
        // being silently skipped with no signal either way.
        $later_checked = isset($_POST['requirements_later'][$rid]);

        if ($file_error === UPLOAD_ERR_NO_FILE || $file_name === '') {
            if (!$later_checked) {
                $errors['requirements'][$rid] = 'Attach this document, or check "I\'ll submit this in person later."';
            }
            continue; // nothing attached — fine only once "submit later" is checked
        }

        if ($file_error !== UPLOAD_ERR_OK) {
            $errors['requirements'][$rid] = 'There was a problem uploading this file. Please try again.';
            continue;
        }

        $file_size = (int) ($_FILES['requirements']['size'][$rid] ?? 0);
        if ($file_size > $constraints['max_bytes']) {
            $mb = (int) ($constraints['max_bytes'] / (1024 * 1024));
            $errors['requirements'][$rid] = "That file is too large — {$mb} MB maximum.";
            continue;
        }

        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $constraints['ext'], true)) {
            $errors['requirements'][$rid] = 'Only ' . strtoupper(implode(' or ', $constraints['ext'])) . ' files are accepted.';
            continue;
        }

        $uploaded_requirements[$rid] = [
            'tmp_name'      => $_FILES['requirements']['tmp_name'][$rid],
            'ext'           => $ext,
            'original_name' => $file_name,
        ];
    }

    // ── Duplicate applicant / email pre-checks ─────────────────────────────
    // These are best-effort pre-checks for a friendly error message. The
    // FOR UPDATE re-check inside the transaction below shrinks the race
    // window further, and the schema's uq_identity unique key
    // (family_name, given_name, date_of_birth) is the final safety net if
    // two submissions still race past both checks — see the errno 1062
    // handling around the student insert below.
    // NOTE: students still has no unique constraint on email — a race on
    // simultaneous identical emails isn't caught at the DB level. Recommend:
    //   ALTER TABLE students ADD UNIQUE KEY uq_email (email);
    if (empty($errors)) {
        $dup_stmt = $conn->prepare(
            "SELECT student_id FROM students WHERE family_name=? AND given_name=? AND date_of_birth=? LIMIT 1"
        );
        $dup_stmt->bind_param('sss', $family_name, $given_name, $birth_date);
        $dup_stmt->execute();
        if ($dup_stmt->get_result()->num_rows > 0) {
            $errors['_dup'] = 'A student record already exists for this name and date of birth. If you already applied, please contact the registrar\'s office to retrieve your control number.';
        }
        $dup_stmt->close();
    }

    if (empty($errors)) {
        $email_stmt = $conn->prepare("SELECT student_id FROM students WHERE email=? LIMIT 1");
        $email_stmt->bind_param('s', $email);
        $email_stmt->execute();
        if ($email_stmt->get_result()->num_rows > 0) {
            $errors['email'] = 'This email address is already linked to an existing application. If it\'s yours, please contact the registrar\'s office.';
        }
        $email_stmt->close();
    }

    // ── DB transaction ─────────────────────────────────────────────────────
    if (empty($errors)) {

        // Absolute paths successfully moved this request — move_uploaded_file()
        // isn't transactional, so these are cleaned up on rollback below.
        $moved_files = [];

        mysqli_begin_transaction($conn);

        try {
            // Row-lock re-check to shrink the race window between two
            // near-simultaneous submissions of the same identity. This can't
            // lock rows that don't exist yet, so it isn't a full guarantee on
            // its own — the schema's uq_identity unique key is the real
            // safety net, enforced by MySQL on the INSERT below (errno 1062
            // is caught and reported as the same friendly duplicate error).
            $lock_stmt = $conn->prepare(
                "SELECT student_id FROM students
                 WHERE family_name=? AND given_name=? AND date_of_birth=?
                 FOR UPDATE"
            );
            $lock_stmt->bind_param('sss', $family_name, $given_name, $birth_date);
            $lock_stmt->execute();
            if ($lock_stmt->get_result()->num_rows > 0) {
                $lock_stmt->close();
                throw new Exception('DUPLICATE_IDENTITY');
            }
            $lock_stmt->close();

            $middle_name_val    = $middle_name    !== '' ? $middle_name    : null;
            $suffix_val         = $suffix         !== '' ? $suffix         : null;
            $student_mobile_val = $student_mobile !== '' ? $student_mobile : null;

            // 1. Insert student
            $q1 = "INSERT INTO students
                       (family_name, given_name, middle_name, suffix,
                        date_of_birth, sex, civil_status, nationality,
                        email, contact_number, is_transferee, student_type)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stu_stmt = $conn->prepare($q1);
            $stu_stmt->bind_param(
                'sssssssssiis',
                $family_name, $given_name, $middle_name_val, $suffix_val,
                $birth_date, $sex, $civil_status, $nationality,
                $email, $student_mobile_val,
                $is_transferee, $student_type
            );
            if (!$stu_stmt->execute()) {
                $stu_err = $conn->errno === 1062 ? 'DUPLICATE_IDENTITY' : 'INSERT_STUDENT: ' . $stu_stmt->error;
                $stu_stmt->close();
                throw new Exception($stu_err);
            }
            $stu_stmt->close();

            $student_id = mysqli_insert_id($conn);

            // NOTE: Student Number is intentionally NOT formed here. Per the
            // finalized flow, it's formed by staff on the Admission page's
            // Student-Number-forming summary modal, once, during the
            // student's first ever staff-side processing cycle. Leave
            // students.student_number NULL.

            // 1b. Insert address — barangay/city/province now come from the
            // resolved PSGC lookup (validated above), so needs_update can
            // be cleared immediately instead of staying flagged for a
            // later fuller-address backfill.
            $addr_stmt = $conn->prepare(
                "INSERT INTO student_addresses (student_id, address_line, barangay, city, province, needs_update)
                 VALUES (?, ?, ?, ?, ?, 0)"
            );
            $addr_stmt->bind_param(
                'issss',
                $student_id, $full_address,
                $student_psgc['brgy_name'], $student_psgc['city_name'], $student_psgc['province_name']
            );
            if (!$addr_stmt->execute())
                throw new Exception('INSERT_ADDRESS: ' . $addr_stmt->error);
            $addr_stmt->close();

            // 1c. Insert JHS education record.
            $edu_stmt = $conn->prepare("INSERT INTO student_education (student_id, school_name, year_graduated, is_public) VALUES (?, ?, ?, ?)");
            $edu_stmt->bind_param('isii', $student_id, $jhs_school, $jhs_year, $jhs_public);
            if (!$edu_stmt->execute())
                throw new Exception('INSERT_EDUCATION: ' . $edu_stmt->error);
            $edu_stmt->close();

            // 2. Insert guardian
            $g_middle_name_val = $g_middle_name !== '' ? $g_middle_name : null;
            $g_suffix_val      = $g_suffix      !== '' ? $g_suffix      : null;
            $occupation_val    = $occupation    !== '' ? $occupation    : null;
            $g_address_val     = $g_address     !== '' ? $g_address     : null;

            $q2 = "INSERT INTO student_guardians
                       (student_id, family_name, first_name, middle_name, suffix,
                        relationship, occupation, contact_number,
                        same_address_as_student, full_address, barangay, city, province)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $gua_stmt = $conn->prepare($q2);
            $gua_stmt->bind_param(
                'isssssssissss',
                $student_id, $g_last_name, $g_first_name, $g_middle_name_val, $g_suffix_val,
                $relationship, $occupation_val, $g_mobile,
                $same_address, $g_address_val,
                $guardian_psgc['brgy_name'], $guardian_psgc['city_name'], $guardian_psgc['province_name']
            );
            if (!$gua_stmt->execute())
                throw new Exception('INSERT_GUARDIAN: ' . $gua_stmt->error);
            $gua_stmt->close();

            // 3. Insert enrollment — no staff user, no section yet (assigned
            // later by staff during the Admission single-page flow: control
            // number lookup → requirements checklist → Student-Number-forming summary).
            $enrollment_date = date('Y-m-d');

            $q3 = "INSERT INTO enrollments
                       (student_id, section_id, admission_strand, admission_grade_level,
                        school_year, enrollment_date, status, user_id, is_new_student, is_strand_shifter)
                   VALUES (?, NULL, ?, ?, ?, ?, 'pending', NULL, ?, 0)";
            $enr_stmt = $conn->prepare($q3);
            $strandId = strand_id($conn, $strand);
            $enr_stmt->bind_param(
                'iisssi',
                $student_id, $strandId, $grade_level, $school_year, $enrollment_date, $is_new_student
            );
            if (!$enr_stmt->execute())
                throw new Exception('INSERT_ENROLLMENT: ' . $enr_stmt->error);
            $enr_stmt->close();

            $new_enrollment_id = mysqli_insert_id($conn);

            // Control number is NOT assigned here. It's formed by Records
            // once a student's submitted requirements are fully verified
            // and they're marked admitted (records/document_review.php),
            // not at public submission — an application that never
            // completes admission should never burn a sequence number, and
            // only students Records actually admits get one.
            // enrollments.control_number stays NULL until then.

            // 4. Insert requirement rows — one per requirement type that
            // actually applies to this student ($applicable_req_types, same
            // filtered list used for validation above). If a valid file was
            // attached and passed validation, move it into storage now and
            // mark the row 'pending_review' so it enters the same staff
            // review queue records/document_review.php already uses for
            // student-portal uploads. Otherwise insert exactly as before
            // ('pending', no file) — uploads are optional here.
            if (!empty($applicable_req_types)) {
                $req_stmt = $conn->prepare(
                    "INSERT INTO enrollment_requirements
                        (enrollment_id, requirement_type_id, status, file_path, original_filename, submitted_at)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($applicable_req_types as $rt) {
                    $rid = (int) $rt['requirement_type_id'];

                    $status           = 'pending';
                    $file_path_val    = null;
                    $orig_name_val    = null;
                    $submitted_at_val = null;

                    if (isset($uploaded_requirements[$rid])) {
                        $up          = $uploaded_requirements[$rid];
                        $stored_name = uniqid('reqdoc_', true) . '.' . $up['ext'];
                        $dest        = __DIR__ . '/../uploads/requirement_documents/' . $stored_name;

                        if (!move_uploaded_file($up['tmp_name'], $dest)) {
                            throw new Exception('MOVE_UPLOAD_FAILED: requirement_type_id=' . $rid);
                        }
                        $moved_files[]    = $dest;
                        $status           = 'pending_review';
                        $file_path_val    = $stored_name;
                        $orig_name_val    = $up['original_name'];
                        $submitted_at_val = date('Y-m-d');
                    }

                    $req_stmt->bind_param(
                        'iissss',
                        $new_enrollment_id, $rid, $status, $file_path_val, $orig_name_val, $submitted_at_val
                    );
                    if (!$req_stmt->execute())
                        throw new Exception('INSERT_REQUIREMENT: ' . $req_stmt->error);
                }
                $req_stmt->close();
            }

            mysqli_commit($conn);

            // Successful submission — start the cooldown window and burn the
            // token so this exact POST can't be replayed.
            $_SESSION['adm_last_submit'] = time();
            unset($_SESSION['adm_token']);

            // Short-lived flash read once by the next GET load of this same
            // page (Post/Redirect/Get) — shows the inline confirmation
            // without a separate slip page. No control number to carry here
            // — Records assigns one later, at approval. Cleared via the
            // "Submit another application" link (admission.php?new=1), not
            // automatically, so it survives a refresh/back-navigation
            // within this browser session.
            $_SESSION['adm_success'] = true;

            header('Location: admission');
            exit();

        } catch (Exception $e) {
            mysqli_rollback($conn);

            foreach ($moved_files as $path) {
                if (is_file($path)) @unlink($path);
            }

            if ($e->getMessage() === 'DUPLICATE_IDENTITY') {
                $errors['_dup'] = 'A student record already exists for this name and date of birth. If you already applied, please contact the registrar\'s office to retrieve your control number.';
            } else {
                $errors['_db'] = 'Something went wrong while submitting your application. Please try again, or contact the registrar\'s office if the problem continues.';
                error_log('[admission.php] ' . $e->getMessage());
            }
        }
    }
}

// ── Post-submit success flash (Post/Redirect/Get) ───────────────────────────
if (isset($_GET['new'])) {
    unset($_SESSION['adm_success']); // "Submit another application" link
}
// Gating on $admission_open too: if an admin closes admissions in the
// narrow window between a student's submit and their redirected GET, the
// closed-state message wins over a stale success flash.
$show_success = $admission_open && !empty($_SESSION['adm_success']);

// ── Helper ─────────────────────────────────────────────────────────────────
function old(string $key, string $default = ''): string {
    global $old;
    return htmlspecialchars($old[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Online Admission Application — SHS Enrollment System</title>
  <link rel="stylesheet" href="../css/css_staff.css?v=<?= filemtime(__DIR__ . '/../css/css_staff.css') ?>">
  <link rel="stylesheet" href="../css/styles.css?v=<?= filemtime(__DIR__ . '/../css/styles.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560&display=swap">
</head>
<body class="adm-page">

<nav>
  <div class="wrap">
    <a href="index" class="brand">
      <img
        src="/Enrollment_system/images/log_ui.png"
        alt=""
        class="brand-icon"
      >
      <img
        src="/Enrollment_system/images/logo_mini2.png"
        alt="Greenfield Senior High School"
      >
    </a>
    <div class="nav-right">
      <a href="index" class="adm-nav-home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-9"/></svg>
        Back to Home
      </a>
    </div>
  </div>
</nav>

<?php if (!$admission_open): ?>

  <div class="success-screen">
    <p class="page-eyebrow">Online Admission</p>
    <h1 class="page-title">Admissions are currently closed</h1>
    <p class="page-sub">
      We're not accepting new admission applications right now<?= $sys ? ' for school year ' . htmlspecialchars($school_year) : '' ?>.
      Please check back later, or contact the registrar's office for more information.
      Already applied? Contact the registrar's office to retrieve your control number.
    </p>
  </div>

<?php elseif ($show_success): ?>

  <div class="success-screen">
    <div class="success-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
    </div>
    <p class="page-eyebrow">Online Admission</p>
    <h1 class="page-title">Application submitted</h1>
    <p class="page-sub">
      Thank you for applying to Greenfield Senior High School. Your application is now under review —
      please wait for an email update from the registrar's office.
    </p>

    <section class="card success-box">
      <p class="success-note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5.5A2.5 2.5 0 0 1 4.5 3h15A2.5 2.5 0 0 1 22 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-15A2.5 2.5 0 0 1 2 18.5v-13z"/><path d="m3 6 9 7 9-7"/></svg>
        <span>Once reviewed, the registrar's office will send you a <strong>control number by email</strong> —
        you'll need it as your reference for any future inquiries.</span>
      </p>
      <hr class="success-divider">
      <div class="success-actions">
        <a href="index" class="btn-primary">Back to Homepage</a>
        <a href="admission?new=1" class="btn-secondary">Submit another application</a>
      </div>
    </section>
  </div>

  <script>
    // The form is done with — clear any in-progress Province/City/Barangay
    // picks saved to survive a refresh (see the .adm-psgc-row wiring),
    // so a later, brand-new application in this same tab doesn't start
    // with stale address values.
    (function () {
      try {
        Object.keys(sessionStorage)
          .filter(function (k) { return k.indexOf('adm_psgc_') === 0; })
          .forEach(function (k) { sessionStorage.removeItem(k); });
      } catch (e) {}
    })();
  </script>

<?php else: ?>

  <p class="page-eyebrow">New Student Application</p>
  <h1 class="page-title">Greenfield Senior High School Online Admission</h1>
  <p class="page-sub">
    Complete the form below to begin your application. After submitting, your application will be
    reviewed by the registrar's office, who will email you a control number once it's approved.
  </p>

  <?php if (!empty($errors['_db'])): ?>
    <div class="notice notice-error"><?= $errors['_db'] ?></div>
  <?php endif; ?>

  <?php if (!empty($errors['_dup'])): ?>
    <div class="notice notice-error"><?= $errors['_dup'] ?></div>
  <?php endif; ?>

  <?php if (!empty($errors) && !isset($errors['_db']) && !isset($errors['_dup'])): ?>
    <div class="notice notice-error">Please correct the errors below before submitting.</div>
  <?php endif; ?>

  <div class="adm-wizard" role="navigation" aria-label="Application progress">
    <?php foreach ([
      'step-type'         => ['1', 'Type'],
      'step-personal'     => ['2', 'Personal'],
      'step-contact'      => ['3', 'Contact'],
      'step-education'    => ['4', 'Education'],
      'step-guardian'     => ['5', 'Guardian'],
      'step-requirements' => ['6', 'Requirements'],
    ] as $stepId => $meta): ?>
      <button type="button" class="adm-step" data-target="<?= $stepId ?>">
        <span class="adm-step-num"><?= $meta[0] ?></span>
        <span class="adm-step-label"><?= $meta[1] ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <div class="adm-shell">
  <form id="enrollmentForm" method="POST" action="admission" enctype="multipart/form-data" novalidate>

    <input type="hidden" name="adm_token" value="<?= htmlspecialchars($_SESSION['adm_token']) ?>">

    <!-- Honeypot — real applicants never see or fill this -->
    <div class="hp-field" aria-hidden="true">
      <label for="website">Leave this field blank</label>
      <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>

    <!-- ── APPLICATION TYPE ──────────────────────────────────────────────── -->
  <section class="card" id="step-type">
    <div class="adm-card-head">
      <div class="adm-card-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      </div>
      <div>
        <h2>Application Type</h2>
        <p class="adm-card-sub">Tell us which grade and strand you're applying for.</p>
      </div>
    </div>

    <label>Grade Level</label>
    <div class="adm-card-row <?= isset($errors['gradeLevel']) ? 'input-error' : '' ?>" role="radiogroup" aria-label="Grade level">
      <?php foreach ($allowed_grades as $g): ?>
        <div class="adm-pick">
          <input type="radio" id="gradeLevel-<?= $g ?>" name="gradeLevel" value="<?= $g ?>"
                 <?= old('gradeLevel') === $g ? 'checked' : '' ?> required>
          <label for="gradeLevel-<?= $g ?>">
            <span class="adm-pick-title">Grade <?= $g ?></span>
            <span class="adm-pick-sub"><?= $g === '11' ? 'New Student' : 'Transferee' ?></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (isset($errors['gradeLevel'])): ?><span class="field-error"><?= $errors['gradeLevel'] ?></span><?php endif; ?>
    <input type="hidden" id="studentTypeDisplay" value="">

    <label style="margin-top:1rem;">Strand</label>
    <div class="adm-card-row adm-strand-row <?= isset($errors['strand']) ? 'input-error' : '' ?>" role="radiogroup" aria-label="Strand">
      <?php foreach ($allowed_strands as $s): ?>
        <div class="adm-pick">
          <input type="radio" id="strand-<?= $s ?>" name="strand" value="<?= $s ?>"
                 <?= old('strand') === $s ? 'checked' : '' ?> required>
          <label for="strand-<?= $s ?>">
            <span class="adm-pick-title"><?= $s ?></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (isset($errors['strand'])): ?><span class="field-error"><?= $errors['strand'] ?></span><?php endif; ?>
    </section>

    <!-- ── PERSONAL INFORMATION ──────────────────────────────────────────── -->
    <section class="card" id="step-personal">
      <div class="adm-card-head">
        <div class="adm-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
        </div>
        <div>
          <h2>Personal Information</h2>
          <p class="adm-card-sub">Legal name and basic details, exactly as on your records.</p>
        </div>
      </div>

      <fieldset>
        <legend>Name</legend>

        <div class="field-row">
          <div class="field-col">
            <label for="givenName">First Name <span class="req">*</span></label>
            <input type="text" id="givenName" name="givenName"
                   value="<?= old('givenName') ?>" placeholder="Enter first name"
                   class="<?= isset($errors['givenName']) ? 'input-error' : '' ?>" required>
            <?php if (isset($errors['givenName'])): ?><span class="field-error"><?= $errors['givenName'] ?></span><?php endif; ?>
          </div>
          <div class="field-col">
            <label for="familyName">Last Name <span class="req">*</span></label>
            <input type="text" id="familyName" name="familyName"
                   value="<?= old('familyName') ?>" placeholder="Enter last name"
                   class="<?= isset($errors['familyName']) ? 'input-error' : '' ?>" required>
            <?php if (isset($errors['familyName'])): ?><span class="field-error"><?= $errors['familyName'] ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field-row">
          <div class="field-col">
            <label for="middleName">Middle Name <span class="field-hint">(optional)</span></label>
            <input type="text" id="middleName" name="middleName"
                   value="<?= old('middleName') ?>" placeholder="Enter middle name"
                   class="<?= isset($errors['middleName']) ? 'input-error' : '' ?>"
                   <?= isset($old['noMiddleName']) ? 'disabled' : '' ?>>
            <label class="adm-mini-check" for="noMiddleName">
              <input type="checkbox" id="noMiddleName" name="noMiddleName"
                     <?= isset($old['noMiddleName']) ? 'checked' : '' ?> onchange="syncNoMiddleName()">
              I have no middle name
            </label>
            <?php if (isset($errors['middleName'])): ?><span class="field-error"><?= $errors['middleName'] ?></span><?php endif; ?>
          </div>
          <div class="field-col adm-suffix-col">
            <label for="suffix">Suffix <span class="field-hint">(optional)</span></label>
            <input type="text" id="suffix" name="suffix"
                   value="<?= old('suffix') ?>" placeholder="Jr., Sr., III"
                   class="<?= isset($errors['suffix']) ? 'input-error' : '' ?>">
            <?php if (isset($errors['suffix'])): ?><span class="field-error"><?= $errors['suffix'] ?></span><?php endif; ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Basic Information</legend>

        <div class="field-row">
          <div class="field-col">
            <label for="birthDate">Date of Birth <span class="req">*</span></label>
            <input type="date" id="birthDate" name="birthDate"
                   value="<?= old('birthDate') ?>"
                   max="<?= date('Y-m-d') ?>"
                   class="<?= isset($errors['birthDate']) ? 'input-error' : '' ?>" required>
            <span class="field-error" id="birthDate-error"
                  style="<?= isset($errors['birthDate']) ? '' : 'display:none;' ?>"><?= isset($errors['birthDate']) ? $errors['birthDate'] : '' ?></span>
          </div>
          <div class="field-col">
            <label>Sex <span class="req">*</span></label>
            <div class="sex-row <?= isset($errors['sex']) ? 'input-error' : '' ?>">
              <div class="sex-opt">
                <input type="radio" id="sex-male" name="sex" value="Male"
                       <?= old('sex') === 'Male' ? 'checked' : '' ?> required>
                <label for="sex-male">Male</label>
              </div>
              <div class="sex-opt">
                <input type="radio" id="sex-female" name="sex" value="Female"
                       <?= old('sex') === 'Female' ? 'checked' : '' ?>>
                <label for="sex-female">Female</label>
              </div>
            </div>
            <?php if (isset($errors['sex'])): ?><span class="field-error"><?= $errors['sex'] ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field-row">
          <div class="field-col">
            <label for="civilStatus">Civil Status</label>
            <select id="civilStatus" name="civilStatus">
              <?php foreach (['Single','Married','Separated','Widowed'] as $cs): ?>
                <option value="<?= $cs ?>" <?= old('civilStatus','Single') === $cs ? 'selected' : '' ?>><?= $cs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-col">
            <label for="nationality">Nationality <span class="req">*</span></label>
            <input type="text" id="nationality" name="nationality"
                   value="<?= old('nationality','Filipino') ?>" placeholder="Filipino"
                   class="<?= isset($errors['nationality']) ? 'input-error' : '' ?>" required>
            <?php if (isset($errors['nationality'])): ?><span class="field-error"><?= $errors['nationality'] ?></span><?php endif; ?>
          </div>
        </div>
      </fieldset>
    </section>

    <!-- ── CONTACT INFORMATION ───────────────────────────────────────────── -->
    <section class="card" id="step-contact">
      <div class="adm-card-head">
        <div class="adm-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5.5A2.5 2.5 0 0 1 4.5 3h15A2.5 2.5 0 0 1 22 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-15A2.5 2.5 0 0 1 2 18.5v-13z"/><path d="m3 6 9 7 9-7"/></svg>
        </div>
        <div>
          <h2>Contact Information</h2>
          <p class="adm-card-sub">Where and how the registrar's office can reach you.</p>
        </div>
      </div>

      <fieldset>
        <legend>Address</legend>

        <div class="adm-psgc-row" data-psgc-scope="student"
             data-old-province="<?= old('studentProvince', '0421') ?>"
             data-old-city="<?= old('studentCity') ?>"
             data-old-barangay="<?= old('studentBarangay') ?>">
          <div class="field-row">
            <div class="field-col">
              <label for="studentProvince">Province <span class="req">*</span></label>
              <select id="studentProvince" name="studentProvince"
                      class="<?= isset($errors['studentBarangay']) ? 'input-error' : '' ?>" required>
                <option value="">Select province</option>
              </select>
            </div>
            <div class="field-col">
              <label for="studentCity">City / Municipality <span class="req">*</span></label>
              <select id="studentCity" name="studentCity"
                      class="<?= isset($errors['studentBarangay']) ? 'input-error' : '' ?>" required disabled>
                <option value="">Select province first</option>
              </select>
            </div>
          </div>
          <div class="field-row">
            <div class="field-col">
              <label for="studentBarangay">Barangay <span class="req">*</span></label>
              <select id="studentBarangay" name="studentBarangay"
                      class="<?= isset($errors['studentBarangay']) ? 'input-error' : '' ?>" required disabled>
                <option value="">Select city/municipality first</option>
              </select>
              <?php if (isset($errors['studentBarangay'])): ?><span class="field-error"><?= $errors['studentBarangay'] ?></span><?php endif; ?>
            </div>
          </div>
        </div>

        <label for="fullAddress">House No. / Street <span class="req">*</span></label>
        <div class="adm-field-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.3"/></svg>
          <textarea id="fullAddress" name="fullAddress" rows="2" maxlength="100"
                    placeholder="House No., Street, Subdivision"
                    class="<?= isset($errors['fullAddress']) ? 'input-error' : '' ?>"
                    required><?= old('fullAddress') ?></textarea>
        </div>
        <?php if (isset($errors['fullAddress'])): ?><span class="field-error"><?= $errors['fullAddress'] ?></span><?php endif; ?>
      </fieldset>

      <fieldset>
        <legend>Contact Details</legend>

        <div class="field-row">
          <div class="field-col">
            <label for="studentMobileDisplay">Mobile Number <span class="field-hint">(optional)</span></label>
            <div class="adm-phone-wrap <?= isset($errors['studentMobile']) ? 'input-error' : '' ?>">
              <span class="adm-phone-prefix">+63</span>
              <input type="tel" id="studentMobileDisplay" placeholder="9XXXXXXXXX" maxlength="10" inputmode="numeric"
                     value="<?= htmlspecialchars(ltrim(old('studentMobile'), '0')) ?>">
            </div>
            <input type="hidden" id="studentMobile" name="studentMobile" value="<?= old('studentMobile') ?>">
            <?php if (isset($errors['studentMobile'])): ?><span class="field-error"><?= $errors['studentMobile'] ?></span><?php endif; ?>
          </div>
          <div class="field-col">
            <label for="email">Email Address <span class="req">*</span></label>
            <input type="email" id="email" name="email"
                   value="<?= old('email') ?>" placeholder="example@email.com"
                   class="<?= isset($errors['email']) ? 'input-error' : '' ?>" required>
            <?php if (isset($errors['email'])): ?><span class="field-error"><?= $errors['email'] ?></span><?php endif; ?>
          </div>
        </div>
      </fieldset>
    </section>

    <!-- ── EDUCATIONAL BACKGROUND ────────────────────────────────────────── -->
    <section class="card" id="step-education">
      <div class="adm-card-head">
        <div class="adm-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/></svg>
        </div>
        <div>
          <h2>Educational Background</h2>
          <p class="adm-card-sub">Your junior high school record.</p>
        </div>
      </div>

      <fieldset>
        <legend>Junior High School</legend>

        <label for="schoolName">School Name <span class="req">*</span></label>
        <input type="text" id="schoolName" name="schoolName"
               value="<?= old('schoolName') ?>" placeholder="Enter Junior High School"
               class="<?= isset($errors['schoolName']) ? 'input-error' : '' ?>" required>
        <?php if (isset($errors['schoolName'])): ?><span class="field-error"><?= $errors['schoolName'] ?></span><?php endif; ?>

        <label for="yearGraduated">Year Graduated <span class="req">*</span></label>
        <input type="number" id="yearGraduated" name="yearGraduated"
               value="<?= old('yearGraduated') ?>"
               min="2000" max="<?= date('Y') ?>" placeholder="YYYY"
               class="<?= isset($errors['yearGraduated']) ? 'input-error' : '' ?>" required>
        <?php if (isset($errors['yearGraduated'])): ?><span class="field-error"><?= $errors['yearGraduated'] ?></span><?php endif; ?>

        <div class="check-item" id="jhsPublicWrap" onclick="toggleJhsPublic(event)">
          <input type="checkbox" id="jhsIsPublic" name="jhsIsPublic"
                 <?= isset($old['jhsIsPublic']) ? 'checked' : '' ?>
                 onchange="syncJhsPublicNote()">
          <div>
            <div class="check-text">I graduated from a public junior high school</div>
          </div>
        </div>
        <span class="field-hint" id="jhsPublicNote"
              style="<?= isset($old['jhsIsPublic']) ? '' : 'display:none;' ?>">
          Applicable for the SHS Voucher Program — the registrar will confirm your voucher eligibility during document validation.
        </span>
      </fieldset>
    </section>

    <!-- ── GUARDIAN INFORMATION ──────────────────────────────────────────── -->
    <section class="card" id="step-guardian">
      <div class="adm-card-head">
        <div class="adm-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div>
          <h2>Guardian Information</h2>
          <p class="adm-card-sub">Parent or legal guardian details.</p>
        </div>
      </div>

      <fieldset>
        <legend>Name</legend>

        <div class="field-row">
          <div class="field-col">
            <label for="guardianFirstName">First Name <span class="req">*</span></label>
            <input type="text" id="guardianFirstName" name="guardianFirstName"
                   value="<?= old('guardianFirstName') ?>" placeholder="Enter first name"
                   class="<?= isset($errors['guardianFirstName']) ? 'input-error' : '' ?>" required>
            <?php if (isset($errors['guardianFirstName'])): ?><span class="field-error"><?= $errors['guardianFirstName'] ?></span><?php endif; ?>
          </div>
          <div class="field-col">
            <label for="guardianLastName">Last Name <span class="req">*</span></label>
            <input type="text" id="guardianLastName" name="guardianLastName"
                   value="<?= old('guardianLastName') ?>" placeholder="Enter last name"
                   class="<?= isset($errors['guardianLastName']) ? 'input-error' : '' ?>" required>
            <?php if (isset($errors['guardianLastName'])): ?><span class="field-error"><?= $errors['guardianLastName'] ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field-row">
          <div class="field-col">
            <label for="guardianMiddleName">Middle Name <span class="field-hint">(optional)</span></label>
            <input type="text" id="guardianMiddleName" name="guardianMiddleName"
                   value="<?= old('guardianMiddleName') ?>" placeholder="Enter middle name"
                   class="<?= isset($errors['guardianMiddleName']) ? 'input-error' : '' ?>">
            <?php if (isset($errors['guardianMiddleName'])): ?><span class="field-error"><?= $errors['guardianMiddleName'] ?></span><?php endif; ?>
          </div>
          <div class="field-col adm-suffix-col">
            <label for="guardianSuffix">Suffix <span class="field-hint">(optional)</span></label>
            <input type="text" id="guardianSuffix" name="guardianSuffix"
                   value="<?= old('guardianSuffix') ?>" placeholder="Jr., Sr., III"
                   class="<?= isset($errors['guardianSuffix']) ? 'input-error' : '' ?>">
            <?php if (isset($errors['guardianSuffix'])): ?><span class="field-error"><?= $errors['guardianSuffix'] ?></span><?php endif; ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Guardian Details</legend>

        <label for="relationship">Relationship to Student <span class="req">*</span></label>
        <select id="relationship" name="relationship"
                class="<?= isset($errors['relationship']) ? 'input-error' : '' ?>" required>
          <option value="">Select relationship</option>
          <?php foreach (['Parent', 'Relative', 'Legal Guardian', 'Other'] as $rel): ?>
            <option value="<?= $rel ?>" <?= old('relationship') === $rel ? 'selected' : '' ?>><?= $rel ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['relationship'])): ?><span class="field-error"><?= $errors['relationship'] ?></span><?php endif; ?>

        <label for="occupation">Occupation <span class="req">*</span></label>
        <input type="text" id="occupation" name="occupation"
               value="<?= old('occupation') ?>" placeholder="Enter occupation"
               class="<?= isset($errors['occupation']) ? 'input-error' : '' ?>" required>
        <?php if (isset($errors['occupation'])): ?><span class="field-error"><?= $errors['occupation'] ?></span><?php endif; ?>

        <label for="guardianMobileDisplay">Mobile Number <span class="req">*</span></label>
        <div class="adm-phone-wrap <?= isset($errors['guardianMobile']) ? 'input-error' : '' ?>">
          <span class="adm-phone-prefix">+63</span>
          <input type="tel" id="guardianMobileDisplay" placeholder="9XXXXXXXXX" maxlength="10" inputmode="numeric"
                 value="<?= htmlspecialchars(ltrim(old('guardianMobile'), '0')) ?>">
        </div>
        <input type="hidden" id="guardianMobile" name="guardianMobile" value="<?= old('guardianMobile') ?>">
        <?php if (isset($errors['guardianMobile'])): ?><span class="field-error"><?= $errors['guardianMobile'] ?></span><?php endif; ?>
      </fieldset>

      <fieldset>
        <legend>Address</legend>

        <div class="check-item" id="sameAddressWrap" onclick="toggleSameAddress(event)">
          <input type="checkbox" id="sameAddress" name="sameAddress"
                 <?= isset($old['sameAddress']) ? 'checked' : '' ?>
                 onchange="syncSameAddress()">
          <div>
            <div class="check-text">Same as student address</div>
          </div>
        </div>

        <div id="guardianAddressWrap" <?= isset($old['sameAddress']) ? 'style="display:none"' : '' ?>>
          <div class="adm-psgc-row" data-psgc-scope="guardian"
               data-old-province="<?= old('guardianProvince') ?>"
               data-old-city="<?= old('guardianCity') ?>"
               data-old-barangay="<?= old('guardianBarangay') ?>"
               style="margin-top:0.75rem;">
            <div class="field-row">
              <div class="field-col">
                <label for="guardianProvince">Province <span class="field-hint">(if different)</span></label>
                <select id="guardianProvince" name="guardianProvince"
                        class="<?= isset($errors['guardianBarangay']) ? 'input-error' : '' ?>">
                  <option value="">Select province</option>
                </select>
              </div>
              <div class="field-col">
                <label for="guardianCity">City / Municipality <span class="field-hint">(if different)</span></label>
                <select id="guardianCity" name="guardianCity"
                        class="<?= isset($errors['guardianBarangay']) ? 'input-error' : '' ?>" disabled>
                  <option value="">Select province first</option>
                </select>
              </div>
            </div>
            <div class="field-row">
              <div class="field-col">
                <label for="guardianBarangay">Barangay <span class="field-hint">(if different)</span></label>
                <select id="guardianBarangay" name="guardianBarangay"
                        class="<?= isset($errors['guardianBarangay']) ? 'input-error' : '' ?>" disabled>
                  <option value="">Select city/municipality first</option>
                </select>
                <?php if (isset($errors['guardianBarangay'])): ?><span class="field-error"><?= $errors['guardianBarangay'] ?></span><?php endif; ?>
              </div>
            </div>
          </div>

          <label for="guardianAddress">
            House No. / Street <span class="field-hint">(if different)</span>
          </label>
          <div class="adm-field-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.3"/></svg>
            <textarea id="guardianAddress" name="guardianAddress" rows="2" maxlength="100"
                      placeholder="House No., Street, Subdivision/Sitio"
                      class="<?= isset($errors['guardianAddress']) ? 'input-error' : '' ?>"><?= old('guardianAddress') ?></textarea>
          </div>
          <?php if (isset($errors['guardianAddress'])): ?><span class="field-error"><?= $errors['guardianAddress'] ?></span><?php endif; ?>
        </div>
      </fieldset>
    </section>

<!-- ── REQUIREMENT UPLOADS ─────────────────────────────────────────────── -->
<section class="card" id="step-requirements">
    <div class="adm-card-head">
      <div class="adm-card-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
      </div>
      <div>
        <h2>Requirement Uploads</h2>
        <p class="adm-card-sub">Attach scans now, or bring originals to the Records Office later.</p>
      </div>
    </div>

    <p class="field-hint adm-hint-card">
        If you don't have scanned copies on hand yet, that's fine — for each item below, either
        attach the file now, or check <strong>"I'll submit this in person later"</strong> and bring
        the original to the Records Office once your application is reviewed. Required documents
        are marked below, but a checked box is enough to submit — nothing here blocks submission
        as long as every item has one or the other.
    </p>

    <div class="check-item" id="allDocsLaterWrap" onclick="toggleAllDocsLater(event)">
        <input type="checkbox" id="allDocsLater">
        <div>
            <div class="check-text">I'll submit <strong>all documents</strong> in person at the Records Office instead of uploading them here</div>
        </div>
    </div>

    <?php foreach ($requirementTypes as $req): ?>

        <?php
        $rid         = (int) $req['requirement_type_id'];
        $constraints = req_doc_constraints($req['requirement_name']);
        // Initial paint only — JS (syncJhsPublicNote) reconciles this on
        // load and on every checkbox change via data-public-only below.
        $hidden       = ($req['applicable_to'] === 'public_jhs_only' && !isset($old['jhsIsPublic']));
        $later_checked = isset($old['requirements_later'][$rid]);
        $req_has_error = isset($errors['requirements'][$rid]);
        ?>

        <div class="upload-group<?= $req_has_error ? ' upload-group-error' : '' ?><?= $later_checked ? ' is-later' : '' ?>"
             <?= $req['applicable_to'] === 'public_jhs_only' ? 'data-public-only' : '' ?>
             <?= $hidden ? 'style="display:none;"' : '' ?>>

            <!-- Collapses away entirely once "submit later" is checked below —
                 the label + whole upload-control fold into ~0 height so a
                 checked item barely takes any room, instead of sitting there
                 as a full-size disabled dropzone. The compact summary line
                 right after this (shown only while collapsed) keeps the
                 requirement's name visible so a stack of checked items still
                 reads as a list, not a wall of identical "later" checkboxes. -->
            <div class="upload-group-body"><div>
                <label for="requirement<?= $rid ?>">
                    <?= htmlspecialchars($req['requirement_name']) ?>
                    <?php if ((int) $req['is_required'] === 1): ?>
                        <span class="req">*</span>
                    <?php endif; ?>
                </label>

                <div class="upload-control">
                    <label class="upload-dropzone<?= $later_checked ? ' upload-dropzone-disabled' : '' ?>" for="requirement<?= $rid ?>">
                        <input
                            type="file"
                            id="requirement<?= $rid ?>"
                            name="requirements[<?= $rid ?>]"
                            accept="<?= $constraints['accept'] ?>"
                            class="<?= $req_has_error ? 'input-error' : '' ?>"
                            <?= $later_checked ? 'disabled' : '' ?>
                            hidden
                        >
                        <svg class="upload-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>
                        <span class="upload-dropzone-text"><strong>Click to upload</strong> or drag and drop</span>
                        <span class="field-hint"><?= htmlspecialchars($constraints['hint']) ?></span>
                    </label>

                    <div class="upload-file-card" style="display:none;">
                        <div class="upload-file-icon"></div>
                        <div class="upload-file-body">
                            <div class="upload-file-row1">
                                <span class="upload-file-name"></span>
                                <span class="upload-file-percent"></span>
                                <span class="upload-file-done" style="display:none;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.3 2.3L15.5 9.5"/></svg>
                                    Completed
                                </span>
                            </div>
                            <div class="upload-progress-track"><div class="upload-progress-fill"></div></div>
                            <div class="upload-file-meta"></div>
                            <div class="upload-file-actions" style="display:none;">
                                <button type="button" class="upload-change-btn">Change</button>
                                <button type="button" class="upload-remove-btn">Remove</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div></div>

            <div class="upload-group-collapsed-summary">
                <span class="upload-group-collapsed-name"><?= htmlspecialchars($req['requirement_name']) ?></span>
                <span class="upload-group-collapsed-badge">Submitting in person later</span>
            </div>

            <label class="upload-later-check" for="requirementLater<?= $rid ?>">
                <input
                    type="checkbox"
                    id="requirementLater<?= $rid ?>"
                    name="requirements_later[<?= $rid ?>]"
                    value="1"
                    <?= $later_checked ? 'checked' : '' ?>
                >
                <span>I'll submit this in person later</span>
            </label>

            <?php if ($req_has_error): ?>
                <span class="field-error"><?= $errors['requirements'][$rid] ?></span>
            <?php endif; ?>

        </div>

    <?php endforeach; ?>

</section>


    <!-- ── ACTIONS ────────────────────────────────────────────────────────── -->
    <div class="form-actions">
      <button type="submit" class="btn-primary" id="submitBtn">
        Submit application
      </button>
    </div>

  </form>

  <!-- ── SIDE REVIEW PANEL ─────────────────────────────────────────────────
       Lives outside <form> in the DOM (a CSS grid sibling of it, see
       .adm-shell) so it can sit alongside the form as a sticky column
       instead of the old full-width bottom table — the JS below still
       reads live field values by name/id regardless of DOM nesting. -->
  <aside class="adm-review" aria-label="Application summary">
    <h2>Review</h2>
    <p class="adm-review-note">Updates live as you fill the form.</p>
    <div id="reviewContainer"></div>
  </aside>
  </div><!-- /.adm-shell -->

<script src="../js/sweetalert2.all.min.js"></script>
<script src="../js/blur_detect.js"></script>
<script>
  // ── "I'll submit ALL documents in person" master checkbox ───────────────
  // Declared up front (rather than down by the requirement-uploads code
  // below) because syncJhsPublicNote() — called immediately below — calls
  // into syncAllDocsLaterCheckbox(), which closes over allDocsLaterCb; a
  // `const` is only "hoisted" as an uninitialized binding, so referencing
  // it before this line would throw a ReferenceError and abort the rest of
  // this script (including the PSGC address-dropdown wiring further down).
  const allDocsLaterCb = document.getElementById('allDocsLater');

  function syncAllDocsLaterCheckbox() {
    if (!allDocsLaterCb) return;
    const wrap = document.getElementById('allDocsLaterWrap');
    const cbs = Array.from(document.querySelectorAll('.upload-group'))
      .filter(function (g) { return g.style.display !== 'none'; })
      .map(function (g) { return g.querySelector('.upload-later-check input[type="checkbox"]'); })
      .filter(Boolean);
    if (!cbs.length) { allDocsLaterCb.checked = false; allDocsLaterCb.indeterminate = false; }
    else {
      const checkedCount = cbs.filter(function (cb) { return cb.checked; }).length;
      if (checkedCount === 0) {
        allDocsLaterCb.checked = false; allDocsLaterCb.indeterminate = false;
      } else if (checkedCount === cbs.length) {
        allDocsLaterCb.checked = true; allDocsLaterCb.indeterminate = false;
      } else {
        allDocsLaterCb.checked = false; allDocsLaterCb.indeterminate = true;
      }
    }
    if (wrap) wrap.classList.toggle('checked', allDocsLaterCb.checked);
  }

  // These three check-item rows (allDocsLaterWrap, sameAddressWrap,
  // jhsPublicWrap) each wrap their <input type="checkbox"> in a div with
  // its own onclick="toggleX()", so clicking anywhere in the row (not just
  // the checkbox) also toggles it. But a click landing on the checkbox
  // itself already toggles it natively AND fires the row's onclick as the
  // event bubbles — flipping it a second time and canceling the click out.
  // That's what made these feel unresponsive ("takes a lot of tries"):
  // clicking precisely on the checkbox square was a silent no-op, and only
  // a click that missed it and landed on the surrounding text actually
  // registered. Guard each toggle so it only fires the manual flip when
  // the click didn't originate on the input itself.
  function toggleAllDocsLater(e) {
    if (!allDocsLaterCb) return;
    if (e && e.target === allDocsLaterCb) return;
    allDocsLaterCb.checked = !allDocsLaterCb.checked;
    allDocsLaterCb.dispatchEvent(new Event('change'));
  }

  function toggleSameAddress(e) {
    const cb = document.getElementById('sameAddress');
    if (e && e.target === cb) return;
    cb.checked = !cb.checked;
    syncSameAddress();
  }
  function syncSameAddress() {
    const cb   = document.getElementById('sameAddress');
    const wrap = document.getElementById('guardianAddressWrap');
    const item = document.getElementById('sameAddressWrap');
    if (!cb) return;
    wrap.style.display = cb.checked ? 'none' : '';
    item.classList.toggle('checked', cb.checked);
  }
  syncSameAddress();

  function toggleJhsPublic(e) {
    const cb = document.getElementById('jhsIsPublic');
    if (e && e.target === cb) return;
    cb.checked = !cb.checked;
    syncJhsPublicNote();
  }
  function syncJhsPublicNote() {
    const cb    = document.getElementById('jhsIsPublic');
    const note  = document.getElementById('jhsPublicNote');
    const item  = document.getElementById('jhsPublicWrap');
    if (!cb) return;
    note.style.display = cb.checked ? '' : 'none';
    item.classList.toggle('checked', cb.checked);

    document.querySelectorAll('.upload-group[data-public-only]').forEach(function (el) {
      el.style.display = cb.checked ? '' : 'none';
    });
    if (typeof syncAllDocsLaterCheckbox === 'function') syncAllDocsLaterCheckbox();
  }
  syncJhsPublicNote();

  if (allDocsLaterCb) {
    allDocsLaterCb.addEventListener('change', function () {
      const checked = allDocsLaterCb.checked;
      allDocsLaterCb.indeterminate = false;
      document.querySelectorAll('.upload-group').forEach(function (group) {
        if (group.style.display === 'none') return;
        const laterCb = group.querySelector('.upload-later-check input[type="checkbox"]');
        if (laterCb && laterCb.checked !== checked) {
          laterCb.checked = checked;
          laterCb.dispatchEvent(new Event('change'));
        }
      });
    });
  }

  function formatMB(bytes) {
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function fileIconMeta(name) {
    const ext = (name.split('.').pop() || '').toUpperCase();
    let cls = 'upload-file-icon-generic';
    if (ext === 'PDF') cls = 'upload-file-icon-pdf';
    else if (['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP'].indexOf(ext) !== -1) cls = 'upload-file-icon-img';
    else if (['DOC', 'DOCX'].indexOf(ext) !== -1) cls = 'upload-file-icon-doc';
    return { label: ext, cls: cls };
  }

  // ── Requirement uploads: dropzone/file-card vs. "submit later" checkbox ──
  // Each requirement group must end up with exactly one signal: an attached
  // file, or the "I'll submit this in person later" box checked. Picking
  // one clears/disables the other so a group can never silently carry both
  // (or end up with neither) without the applicant noticing.
  document.querySelectorAll('.upload-group').forEach(function (group) {
    const fileInput  = group.querySelector('input[type="file"]');
    const dropzone   = group.querySelector('.upload-dropzone');
    const card       = group.querySelector('.upload-file-card');
    const laterLabel = group.querySelector('.upload-later-check');
    const laterCb    = laterLabel ? laterLabel.querySelector('input[type="checkbox"]') : null;
    if (!fileInput || !laterCb || !dropzone || !card) return;

    const fileIcon      = card.querySelector('.upload-file-icon');
    const fileNameEl    = card.querySelector('.upload-file-name');
    const filePercent   = card.querySelector('.upload-file-percent');
    const fileDone      = card.querySelector('.upload-file-done');
    const progressFill  = card.querySelector('.upload-progress-fill');
    const fileMeta      = card.querySelector('.upload-file-meta');
    const fileActions   = card.querySelector('.upload-file-actions');
    const changeBtn     = card.querySelector('.upload-change-btn');
    const removeBtn     = card.querySelector('.upload-remove-btn');
    let progressTimer   = null;

    // Once a file is attached, the "submit later" choice no longer applies
    // to this item — hide it entirely rather than just leaving it unchecked,
    // so it can't be confused for a second required step. It reappears the
    // moment the file is cleared, since at that point the applicant needs to
    // pick one of the two options again.
    function resetToDropzone() {
      if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
      dropzone.style.display = '';
      card.style.display = 'none';
      progressFill.style.width = '0%';
      progressFill.classList.remove('is-complete');
      fileActions.style.display = 'none';
      fileDone.style.display = 'none';
      filePercent.style.display = '';
      if (laterLabel) laterLabel.style.display = '';
    }

    // Fake-but-fast progress animation — files aren't actually sent to the
    // server until final submit, so this is a purely visual "your file is
    // ready" confirmation, not a real transfer.
    function showUploadProgress(file) {
      const meta = fileIconMeta(file.name);
      fileIcon.textContent = meta.label;
      fileIcon.className = 'upload-file-icon ' + meta.cls;
      fileNameEl.textContent = file.name;
      fileDone.style.display = 'none';
      filePercent.style.display = '';
      fileActions.style.display = 'none';
      progressFill.classList.remove('is-complete');

      dropzone.style.display = 'none';
      card.style.display = '';
      if (laterLabel) laterLabel.style.display = 'none';

      const total = file.size;
      let pct = 0;
      if (progressTimer) clearInterval(progressTimer);
      progressTimer = setInterval(function () {
        pct = Math.min(100, pct + Math.random() * 25 + 10);
        progressFill.style.width = pct + '%';
        filePercent.textContent = Math.round(pct) + '%';
        fileMeta.textContent = formatMB(total * pct / 100) + ' of ' + formatMB(total);
        if (pct >= 100) {
          clearInterval(progressTimer);
          progressTimer = null;
          progressFill.classList.add('is-complete');
          filePercent.style.display = 'none';
          fileDone.style.display = '';
          fileMeta.textContent = formatMB(total);
          fileActions.style.display = '';
        }
      }, 120);
    }

    // Small status line for the async blur check below — created once,
    // reused for every selection in this group.
    let blurStatus = group.querySelector('.upload-blur-status');
    if (!blurStatus) {
      blurStatus = document.createElement('div');
      blurStatus.className = 'upload-blur-status field-hint';
      blurStatus.style.display = 'none';
      card.insertAdjacentElement('afterend', blurStatus);
    }

    fileInput.addEventListener('change', function () {
      if (!(fileInput.files && fileInput.files.length > 0)) {
        resetToDropzone();
        clearUploadGroupError(group);
        return;
      }

      const file = fileInput.files[0];
      laterCb.checked = false;
      syncAllDocsLaterCheckbox();

      // Blur check only applies to actual raster images — a PDF (allowed
      // for most requirement types) can't be meaningfully checked this
      // way without rendering it first, so it's skipped entirely.
      if (typeof scoreImageSharpness !== 'function' || !file.type.startsWith('image/')) {
        showUploadProgress(file);
        clearUploadGroupError(group);
        return;
      }

      blurStatus.textContent = 'Checking image sharpness…';
      blurStatus.style.display = '';

      scoreImageSharpness(file).then(function (score) {
        blurStatus.style.display = 'none';
        if (score < BLUR_THRESHOLD) {
          fileInput.value = '';
          resetToDropzone();
          const showBlurAlert = function () {
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'warning', title: 'Image looks blurry', text: 'This image looks blurry — please attach a clearer photo or scan.', confirmButtonColor: '#1E4D3B' });
            } else {
              alert('This image looks blurry — please attach a clearer photo or scan.');
            }
          };
          showBlurAlert();
        } else {
          showUploadProgress(file);
        }
        clearUploadGroupError(group);
      }).catch(function () {
        // Couldn't score it (e.g. a corrupt/unsupported image) — never
        // block on a check that itself failed; let normal validation
        // (file type/size) catch a genuinely bad file server-side.
        blurStatus.style.display = 'none';
        showUploadProgress(file);
        clearUploadGroupError(group);
      });
    });

    if (changeBtn) {
      changeBtn.addEventListener('click', function () {
        fileInput.click();
      });
    }

    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        fileInput.value = '';
        resetToDropzone();
        clearUploadGroupError(group);
      });
    }

    laterCb.addEventListener('change', function () {
      fileInput.disabled = laterCb.checked;
      dropzone.classList.toggle('upload-dropzone-disabled', laterCb.checked);
      if (laterCb.checked) {
        fileInput.value = '';
      }
      resetToDropzone();
      // Collapse the whole card away (label + upload control) once
      // "submit later" is checked, rather than leaving a full-size
      // disabled dropzone sitting there — the checkbox is already the
      // applicant's explicit signal for this item, and with 6 requirement
      // cards on the page, keeping each checked one at full height meant a
      // lot of extra scrolling to reach Submit for no benefit. The compact
      // summary line (name + "Submitting in person later") takes its place
      // so the item stays identifiable.
      group.classList.toggle('is-later', laterCb.checked);
      clearUploadGroupError(group);
      syncAllDocsLaterCheckbox();
    });

    // Drag-and-drop onto the dropzone — the label's own click-to-browse
    // behavior (input is nested inside it) keeps working alongside this.
    ['dragenter', 'dragover'].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault();
        if (fileInput.disabled) return;
        dropzone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'dragend'].forEach(function (evt) {
      dropzone.addEventListener(evt, function () {
        dropzone.classList.remove('is-dragover');
      });
    });
    dropzone.addEventListener('drop', function (e) {
      e.preventDefault();
      dropzone.classList.remove('is-dragover');
      if (fileInput.disabled) return;
      const dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length) {
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    resetToDropzone();
  });

  syncAllDocsLaterCheckbox();

  // Prevent a file dropped outside a dropzone from navigating the browser
  // away from the form.
  ['dragover', 'drop'].forEach(function (evt) {
    window.addEventListener(evt, function (e) { e.preventDefault(); });
  });

  function clearUploadGroupError(group) {
    group.classList.remove('upload-group-error');
    const fileInput = group.querySelector('input[type="file"]');
    if (fileInput) fileInput.classList.remove('input-error');
    const err = group.querySelector('.field-error');
    if (err) err.style.display = 'none';
  }

  // Returns true if every currently-applicable requirement group has either
  // a file attached or "submit later" checked; otherwise marks the
  // offending groups and returns false. Hidden groups (e.g. public-JHS-only
  // items when that box isn't checked) are skipped, matching the server's
  // filtering by jhsIsPublic.
  function validateUploadChoices() {
    let valid = true;
    let firstInvalid = null;

    document.querySelectorAll('.upload-group').forEach(function (group) {
      if (group.style.display === 'none') return;

      const fileInput = group.querySelector('input[type="file"]');
      const laterCb   = group.querySelector('.upload-later-check input[type="checkbox"]');
      if (!fileInput || !laterCb) return;

      const hasFile = fileInput.files && fileInput.files.length > 0;

      if (!hasFile && !laterCb.checked) {
        valid = false;
        group.classList.add('upload-group-error');
        fileInput.classList.add('input-error');

        let err = group.querySelector('.field-error');
        if (!err) {
          err = document.createElement('span');
          err.className = 'field-error';
          group.appendChild(err);
        }
        err.textContent = 'Attach this document, or check "I\'ll submit this in person later."';
        err.style.display = '';

        if (!firstInvalid) firstInvalid = group;
      } else {
        clearUploadGroupError(group);
      }
    });

    if (!valid && firstInvalid) {
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    return valid;
  }

  // ── Student type indicator (derived from grade level, not editable) ─────
  // gradeLevel is now a set of card radios (see .adm-pick markup) instead
  // of a single <select>, so this reads the checked radio's value rather
  // than one element's .value. studentTypeDisplay is a hidden field that
  // only exists to feed the side review panel below.
  (function () {
    const gradeRadios = document.querySelectorAll('input[name="gradeLevel"]');
    const display = document.getElementById('studentTypeDisplay');
    if (!gradeRadios.length || !display) return;

    function updateStudentType() {
      const checked = document.querySelector('input[name="gradeLevel"]:checked');
      const val = checked ? checked.value : '';
      if (val === '11') {
        display.value = 'New Student';
      } else if (val === '12') {
        display.value = 'Transferee';
      } else {
        display.value = '';
      }
    }

    gradeRadios.forEach(r => r.addEventListener('change', updateStudentType));
    updateStudentType();
  })();

  // ── Live side-panel review, grouped by category ──────────────────────────
  const reviewGroups = [
    { title: 'Application', fields: [
      { label: 'Grade level',  name: 'gradeLevel' },
      { label: 'Strand',       name: 'strand' },
      { label: 'Student type', name: 'studentTypeDisplay' },
    ]},
    { title: 'Personal', fields: [
      { label: 'First name',    name: 'givenName' },
      { label: 'Middle name',   name: 'middleName' },
      { label: 'Last name',     name: 'familyName' },
      { label: 'Suffix',        name: 'suffix' },
      { label: 'Date of birth', name: 'birthDate' },
      { label: 'Sex',           name: 'sex' },
      { label: 'Civil status',  name: 'civilStatus' },
      { label: 'Nationality',   name: 'nationality' },
    ]},
    { title: 'Contact', fields: [
      { label: 'Street',   name: 'fullAddress' },
      { label: 'Barangay', name: 'studentBarangay' },
      { label: 'City',     name: 'studentCity' },
      { label: 'Province', name: 'studentProvince' },
      { label: 'Mobile',   name: 'studentMobile' },
      { label: 'Email',    name: 'email' },
    ]},
    { title: 'Education', fields: [
      { label: 'JHS school',     name: 'schoolName' },
      { label: 'Year graduated', name: 'yearGraduated' },
    ]},
    { title: 'Guardian', fields: [
      { label: 'First name',   name: 'guardianFirstName' },
      { label: 'Last name',    name: 'guardianLastName' },
      { label: 'Suffix',       name: 'guardianSuffix' },
      { label: 'Relationship', name: 'relationship' },
      { label: 'Occupation',   name: 'occupation' },
      { label: 'Mobile',       name: 'guardianMobile' },
      { label: 'Street',       name: 'guardianAddress' },
      { label: 'Barangay',     name: 'guardianBarangay' },
      { label: 'City',         name: 'guardianCity' },
      { label: 'Province',     name: 'guardianProvince' },
    ]},
  ];

  function fieldValue(name) {
    const radio = document.querySelector('input[name="' + name + '"]:checked');
    if (radio) return radio.value.trim();
    const el = document.querySelector('[name="' + name + '"]') || document.getElementById(name);
    if (!el) return '';
    // For the PSGC selects, show the human-readable option text (the
    // province/city/barangay name), not the code stored in .value.
    if (el.tagName === 'SELECT') {
      const opt = el.options[el.selectedIndex];
      return (opt && opt.value) ? opt.text.trim() : '';
    }
    return el.value.trim();
  }

  function buildReview() {
    const container = document.getElementById('reviewContainer');
    if (!container) return;
    let html = '';
    reviewGroups.forEach(function (group) {
      const rows = group.fields
        .map(f => ({ label: f.label, val: fieldValue(f.name) }))
        .filter(r => r.val);
      if (!rows.length) return;
      html += '<div class="adm-review-group"><div class="adm-review-group-title">' + group.title + '</div><dl>';
      rows.forEach(r => {
        html += '<div class="adm-review-row"><dt>' + r.label + '</dt><dd>' + escHtml(r.val) + '</dd></div>';
      });
      html += '</dl></div>';
    });
    container.innerHTML = html || '<p class="adm-review-empty">Nothing filled in yet.</p>';
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  buildReview();
  const form = document.getElementById('enrollmentForm');
  if (form) form.addEventListener('input', buildReview);
  if (form) form.addEventListener('change', buildReview);

  // ── Input validation & auto-capitalize ──────────────────────────────────
  function capFirst(val) {
    return val.replace(/(?:^|\s)(\S)/g, (_, c) => _ .replace(c, c.toUpperCase()));
  }

  const nameFields = ['givenName','middleName','familyName',
                      'guardianFirstName','guardianMiddleName','guardianLastName'];
  nameFields.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      const pos = this.selectionStart;
      const cleaned = this.value.replace(/[^a-zA-ZÀ-ÖØ-öø-ÿ\s'\-]/g, '');
      this.value = capFirst(cleaned);
      try { this.setSelectionRange(pos, pos); } catch(_) {}
    });
  });

  const lettersOnlyFields = {
    schoolName:   /[^\p{L}\s.,'()\-]/gu,
    occupation:   /[^A-Za-zÀ-ÿ'\s.\-\/]/g,
  };
  Object.keys(lettersOnlyFields).forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      const pos = this.selectionStart;
      this.value = this.value.replace(lettersOnlyFields[id], '');
      try { this.setSelectionRange(pos, pos); } catch(_) {}
    });
  });

['yearGraduated'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '');
    });
  });

  // ── PH phone prefix (+63) — display fields capture the 10 digits after
  // the leading 0; the real hidden field stays in 09XXXXXXXXX format,
  // matching the server regex and every other consumer of contact_number
  // across the app, so nothing outside this page needs to change. ────────
  function wirePhonePrefix(displayId, hiddenId) {
    const display = document.getElementById(displayId);
    const hidden  = document.getElementById(hiddenId);
    if (!display || !hidden) return;
    display.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 10);
      hidden.value = this.value ? '0' + this.value : '';
    });
  }
  wirePhonePrefix('studentMobileDisplay', 'studentMobile');
  wirePhonePrefix('guardianMobileDisplay', 'guardianMobile');

  // ── "I have no middle name" toggle — mirrors the sameAddress/jhsIsPublic
  // click-wrapper pattern already used elsewhere on this page. ────────────
  function syncNoMiddleName() {
    const cb = document.getElementById('noMiddleName');
    const mn = document.getElementById('middleName');
    if (!cb || !mn) return;
    mn.disabled = cb.checked;
    if (cb.checked) {
      mn.value = '';
      mn.placeholder = 'N/A';
    } else {
      mn.placeholder = 'Enter middle name';
    }
    buildReview();
  }
  syncNoMiddleName();

  // ── PSGC cascading address dropdowns (Province -> City/Municipality ->
  // Barangay), backed by ajax/psgc_lookup.php. Each .adm-psgc-row (one for
  // the student address, one for the guardian address) is wired up the
  // same way. On a validation-error redisplay the selects always start
  // empty (PHP never renders their <option>s — they're populated here),
  // so data-old-province/-city/-barangay carry the previous codes and get
  // replayed through the same cascade once the province list loads. ────────
  (function () {
    const PSGC_URL = '../ajax/psgc_lookup';

    function populateSelect(select, items, valueKey, labelKey, placeholder) {
      select.innerHTML = '';
      const opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = placeholder;
      select.appendChild(opt0);
      items.forEach(function (it) {
        const opt = document.createElement('option');
        opt.value = it[valueKey];
        opt.textContent = it[labelKey];
        select.appendChild(opt);
      });
    }

    function fetchJson(url) {
      return fetch(url).then(function (r) { return r.json(); });
    }

    function wirePsgcScope(scopeEl) {
      const provinceSel = scopeEl.querySelector('select[id$="Province"]');
      const citySel      = scopeEl.querySelector('select[id$="City"]');
      const brgySel      = scopeEl.querySelector('select[id$="Barangay"]');
      if (!provinceSel || !citySel || !brgySel) return;

      // These three selects start completely empty in the raw HTML (their
      // <option>s only exist once populateSelect() builds them from the
      // AJAX fetch below), so the browser's native "restore my values on
      // refresh" has nothing to restore into. Persisting the actual picks
      // here ourselves is what makes them survive a plain refresh — a
      // validation-error redisplay's data-old-* attributes only reflect a
      // real form POST, not a refresh, so they'd otherwise silently reset
      // to the province default below on every reload.
      function storageKey(sel) { return 'adm_psgc_' + sel.id; }
      function saveSelection(sel) {
        try { sessionStorage.setItem(storageKey(sel), sel.value); } catch (e) {}
      }
      function savedSelection(sel) {
        try { return sessionStorage.getItem(storageKey(sel)) || ''; } catch (e) { return ''; }
      }
      function clearSelection(sel) {
        try { sessionStorage.removeItem(storageKey(sel)); } catch (e) {}
      }

      const oldProvince = savedSelection(provinceSel) || scopeEl.dataset.oldProvince || '';
      const oldCity     = savedSelection(citySel)     || scopeEl.dataset.oldCity || '';
      const oldBarangay = savedSelection(brgySel)      || scopeEl.dataset.oldBarangay || '';

      function loadCities(provinceCode, selectCode) {
        citySel.disabled = true;
        brgySel.disabled = true;
        brgySel.innerHTML = '<option value="">Select city/municipality first</option>';
        if (!provinceCode) {
          citySel.innerHTML = '<option value="">Select province first</option>';
          return;
        }
        citySel.innerHTML = '<option value="">Loading…</option>';
        fetchJson(PSGC_URL + '?type=cities&province_code=' + encodeURIComponent(provinceCode))
          .then(function (data) {
            populateSelect(citySel, data.cities || [], 'city_code', 'city_name', 'Select city/municipality');
            citySel.disabled = false;
            if (selectCode) {
              citySel.value = selectCode;
              if (citySel.value === selectCode) {
                saveSelection(citySel);
                loadBarangays(selectCode, oldBarangay);
              }
            }
            buildReview();
          });
      }

      function loadBarangays(cityCode, selectCode) {
        brgySel.disabled = true;
        if (!cityCode) {
          brgySel.innerHTML = '<option value="">Select city/municipality first</option>';
          return;
        }
        brgySel.innerHTML = '<option value="">Loading…</option>';
        fetchJson(PSGC_URL + '?type=barangays&city_code=' + encodeURIComponent(cityCode))
          .then(function (data) {
            populateSelect(brgySel, data.barangays || [], 'brgy_code', 'brgy_name', 'Select barangay');
            brgySel.disabled = false;
            if (selectCode) {
              brgySel.value = selectCode;
              saveSelection(brgySel);
            }
            buildReview();
          });
      }

      provinceSel.addEventListener('change', function () {
        saveSelection(provinceSel);
        // City/barangay are about to be reset (invalid for the new
        // province) — clear their saved picks rather than persisting the
        // now-stale values still sitting in those selects.
        clearSelection(citySel);
        clearSelection(brgySel);
        loadCities(provinceSel.value, null);
      });
      citySel.addEventListener('change', function () {
        saveSelection(citySel);
        clearSelection(brgySel);   // barangay is about to reset for the new city
        loadBarangays(citySel.value, null);
      });
      brgySel.addEventListener('change', function () {
        saveSelection(brgySel);
        buildReview();
      });

      fetchJson(PSGC_URL + '?type=provinces')
        .then(function (data) {
          populateSelect(provinceSel, data.provinces || [], 'province_code', 'province_name', 'Select province');
          if (oldProvince) {
            provinceSel.value = oldProvince;
            saveSelection(provinceSel);
            loadCities(oldProvince, oldCity);
          }
        });
    }

    document.querySelectorAll('.adm-psgc-row').forEach(wirePsgcScope);
  })();

  // ── Wizard progress bar: scroll-spy over the six section cards, plus
  // click-to-scroll on each step dot. Purely client-side/cosmetic — no
  // interaction with the PHP validation/error flow. ───────────────────────
  (function () {
    const steps = Array.from(document.querySelectorAll('.adm-step'));
    if (!steps.length) return;

    const sections = steps
      .map(s => document.getElementById(s.dataset.target))
      .filter(Boolean);

    steps.forEach(s => {
      s.addEventListener('click', function () {
        const target = document.getElementById(this.dataset.target);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    if (!sections.length || typeof IntersectionObserver === 'undefined') return;

    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        const step = steps.find(s => s.dataset.target === entry.target.id);
        if (!step) return;
        if (entry.isIntersecting) {
          steps.forEach(s => s.classList.remove('active'));
          step.classList.add('active');
          const idx = steps.indexOf(step);
          steps.forEach((s, i) => s.classList.toggle('complete', i < idx));
        }
      });
    }, { rootMargin: '-140px 0px -60% 0px', threshold: 0 });

    sections.forEach(sec => observer.observe(sec));
  })();

  (function () {
    const bd  = document.getElementById('birthDate');
    const err = document.getElementById('birthDate-error');
    if (!bd || !err) return;

    function checkBirthDate() {
      if (!bd.value) { err.style.display = 'none'; bd.classList.remove('input-error'); return; }
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const chosen = new Date(bd.value + 'T00:00:00');
      if (isNaN(chosen.getTime()) || chosen > today) {
        err.textContent = 'Date of birth cannot be today or a future date.';
        err.style.display = '';
        bd.classList.add('input-error');
      } else {
        err.style.display = 'none';
        bd.classList.remove('input-error');
      }
    }

    bd.addEventListener('input', checkBirthDate);
    bd.addEventListener('change', checkBirthDate);
  })();

  // ── Real-time field validation ────────────────────────────────────────
  // Mirrors the PHP rules in the POST handler above (kept in sync by
  // hand — this is a convenience layer only, PHP remains the real gate)
  // so most mistakes surface on blur, or as soon as they're fixed,
  // instead of only after Submit. Reuses each field's existing
  // "<span class="field-error"> right after the input" markup — creates
  // one on the fly if a field never rendered one (i.e. no server error
  // on the initial load).
  (function () {
    const nameRegex   = /^[A-Za-zÀ-ÿ' .\-]+$/;
    const suffixRegex = /^[A-Za-z.]+$/;

    function fieldErrorEl(anchor) {
      let el = anchor.nextElementSibling;
      if (el && el.classList && el.classList.contains('field-error')) return el;
      el = document.createElement('span');
      el.className = 'field-error';
      anchor.insertAdjacentElement('afterend', el);
      return el;
    }

    function setError(input, message, opts) {
      opts = opts || {};
      const errorClassTarget = opts.errorClassTarget || input;
      const errEl = fieldErrorEl(opts.anchor || input);
      if (message) {
        errEl.textContent = message;
        errEl.style.display = '';
        errorClassTarget.classList.add('input-error');
      } else {
        errEl.style.display = 'none';
        errorClassTarget.classList.remove('input-error');
      }
    }

    function clearGroupError(container) {
      if (!container) return;
      container.classList.remove('input-error');
      const err = container.nextElementSibling;
      if (err && err.classList && err.classList.contains('field-error')) err.style.display = 'none';
    }

    const requiredName = label => v => {
      if (v === '') return label + ' is required.';
      if (!nameRegex.test(v)) return label + ' cannot contain numbers or special characters.';
      return '';
    };
    const optionalName = label => v => (v !== '' && !nameRegex.test(v))
      ? label + ' cannot contain numbers or special characters.' : '';
    const optionalSuffix = () => v => (v !== '' && !suffixRegex.test(v))
      ? 'Enter a valid suffix (e.g. Jr., Sr., III).' : '';

    const currentYear = new Date().getFullYear();
    const validators = {
      givenName:          requiredName('First name'),
      familyName:         requiredName('Last name'),
      middleName:         optionalName('Middle name'),
      suffix:             optionalSuffix(),
      nationality:        requiredName('Nationality'),
      guardianFirstName:  requiredName('Guardian first name'),
      guardianLastName:   requiredName('Guardian last name'),
      guardianMiddleName: optionalName('Guardian middle name'),
      guardianSuffix:     optionalSuffix(),
      occupation: v => {
        if (v === '') return 'Occupation is required.';
        return !/^[A-Za-zÀ-ÿ' .\-\/]+$/.test(v) ? 'Occupation cannot contain numbers or special characters.' : '';
      },
      schoolName: v => {
        if (v === '') return 'Junior high school name is required.';
        if (v.length < 3) return 'School name must be at least 3 characters.';
        if (v.length > 100) return 'School name cannot exceed 100 characters.';
        return '';
      },
      yearGraduated: v => {
        if (v === '') return 'Year graduated is required.';
        const y = parseInt(v, 10);
        return (!/^\d{4}$/.test(v) || y < 2000 || y > currentYear)
          ? 'Enter a valid 4-digit graduation year (2000–' + currentYear + ').' : '';
      },
      email: v => {
        if (v === '') return 'Email address is required.';
        return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? 'Enter a valid email address.' : '';
      },
      fullAddress:  v => v === '' ? 'House number / street is required.' : '',
      relationship: v => v === '' ? 'Please select a relationship to student.' : '',
    };

    Object.keys(validators).forEach(function (id) {
      const el = document.getElementById(id);
      if (!el) return;
      function run() { setError(el, validators[id](el.value.trim())); }
      el.addEventListener('blur', run);
      el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function () {
        if (el.classList.contains('input-error')) run();
      });
    });

    // guardianAddress is only required when NOT reusing the student address.
    const guardianAddressEl = document.getElementById('guardianAddress');
    if (guardianAddressEl) {
      function runGuardianAddress() {
        const sameAddress = document.getElementById('sameAddress');
        if (sameAddress && sameAddress.checked) { setError(guardianAddressEl, ''); return; }
        setError(guardianAddressEl, guardianAddressEl.value.trim() === ''
          ? 'Guardian address is required (or check "Same as student address").' : '');
      }
      guardianAddressEl.addEventListener('blur', runGuardianAddress);
      guardianAddressEl.addEventListener('input', function () {
        if (guardianAddressEl.classList.contains('input-error')) runGuardianAddress();
      });
    }

    // PH mobile numbers — the visible field is the 10-digit display input;
    // the error class/message anchor to the phone-prefix wrap and hidden
    // field respectively, matching this field's existing markup.
    function wireMobileValidation(displayId, required) {
      const display = document.getElementById(displayId);
      if (!display) return;
      const wrap   = display.closest('.adm-phone-wrap');
      const hidden = document.getElementById(displayId.replace('Display', ''));
      function run() {
        const digits = display.value.trim();
        let msg = '';
        if (digits === '') {
          if (required) msg = 'Mobile number is required.';
        } else if (digits.length !== 10 || digits[0] !== '9') {
          msg = 'Enter a valid PH mobile number (09XXXXXXXXX).';
        }
        setError(display, msg, { errorClassTarget: wrap || display, anchor: hidden || display });
      }
      display.addEventListener('blur', run);
      display.addEventListener('input', function () {
        if ((wrap || display).classList.contains('input-error')) run();
      });
    }
    wireMobileValidation('studentMobileDisplay', false);
    wireMobileValidation('guardianMobileDisplay', true);

    // Required picker rows (radio groups) — nothing to format-check, just
    // clear the "required" highlight the moment a choice is made.
    function wireRequiredGroup(groupName, containerSelector) {
      const inputs = document.querySelectorAll('input[name="' + groupName + '"]');
      const container = document.querySelector(containerSelector);
      if (!inputs.length || !container) return;
      inputs.forEach(input => input.addEventListener('change', () => clearGroupError(container)));
    }
    wireRequiredGroup('gradeLevel', '#step-type .adm-card-row:not(.adm-strand-row)');
    wireRequiredGroup('strand', '.adm-strand-row');
    wireRequiredGroup('sex', '.sex-row');

    // PSGC cascading address (province -> city -> barangay) — the three
    // selects share one error, keyed off whether barangay (the final,
    // most specific level) ends up chosen.
    function wirePsgcValidation(scope) {
      const row = document.querySelector('.adm-psgc-row[data-psgc-scope="' + scope + '"]');
      if (!row) return;
      const province = row.querySelector('select[id$="Province"]');
      const city     = row.querySelector('select[id$="City"]');
      const brgy     = row.querySelector('select[id$="Barangay"]');
      if (!province || !city || !brgy) return;
      function run() {
        if (scope === 'guardian') {
          const sameAddress = document.getElementById('sameAddress');
          if (sameAddress && sameAddress.checked) {
            setError(brgy, '');
            [province, city, brgy].forEach(sel => sel.classList.remove('input-error'));
            return;
          }
        }
        const msg = brgy.value === ''
          ? (scope === 'guardian'
              ? 'Please select the guardian\'s province, city/municipality, and barangay (or check "Same as student address").'
              : 'Please select your province, city/municipality, and barangay.')
          : '';
        [province, city, brgy].forEach(sel => sel.classList.toggle('input-error', !!msg));
        setError(brgy, msg);
      }
      brgy.addEventListener('blur', run);
      brgy.addEventListener('change', run);
      city.addEventListener('change', run);
    }
    wirePsgcValidation('student');
    wirePsgcValidation('guardian');
  })();

  ['fullAddress', 'guardianAddress', 'schoolName'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      if (this.value.length === 1) {
        this.value = this.value.charAt(0).toUpperCase();
      } else if (this.value.length > 1 && this.value[0] !== this.value[0].toUpperCase()) {
        this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
      }
    });
  });

  // Form submit confirmation — also guards against accidental double submit
  // since there's no staff member present to catch a duplicate click.
  const enrollForm = document.getElementById('enrollmentForm');
  if (enrollForm) {
    enrollForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const self = this;

      if (!validateUploadChoices()) {
        Swal.fire({
          icon: 'warning',
          title: 'Missing requirement selection',
          text: 'For each requirement above, attach the file or check "I\'ll submit this in person later" before submitting.',
          confirmButtonColor: '#1E4D3B'
        });
        return;
      }

      const submitBtn = document.getElementById('submitBtn');
      Swal.fire({
        title: 'Submit Application?',
        text: 'Please review all details before confirming.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#1E4D3B',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, submit',
        cancelButtonText: 'Review again'
      }).then(function(result) {
        if (result.isConfirmed) {
          if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting…'; }
          HTMLFormElement.prototype.submit.call(self);
        }
      });
    });
  }
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
  // Toggles the nav from transparent (over the top of the page) to a
  // solid/blurred pill once the page scrolls — see nav.scrolled in
  // css/styles.css. Runs regardless of which branch above rendered
  // (closed / success / form), since the nav is shared by all three.
  (function () {
    var nav = document.querySelector('nav');
    if (!nav) return;

    function updateNavState() {
      nav.classList.toggle('scrolled', window.scrollY > 12);
    }

    updateNavState();
    window.addEventListener('scroll', updateNavState, { passive: true });
  })();
</script>

</body>
</html>