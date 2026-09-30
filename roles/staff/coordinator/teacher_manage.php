<?php
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../config/mail.php';

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';

$is_reviewer = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');
if (!$is_reviewer) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

// Strands a new teacher can be assigned to from this form. TVL and CORE are
// deliberately excluded — TVL isn't offered at this school and CORE subjects
// aren't a strand a teacher "belongs to" the way STEM/HUMSS/ABM/GAS are.
// Optional either way — not every teacher (e.g. one shared across strands)
// needs one set.
$strand_stmt = $conn->prepare("SELECT strand_id, strand_code FROM strands WHERE strand_code NOT IN ('TVL', 'CORE') ORDER BY strand_code ASC");
$strand_stmt->execute();
$strands = $strand_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$strand_stmt->close();

// firstname+middlename.lastname, lowercase letters only; a collision falls
// back to appending an incrementing number (same convention as
// admin/staff_manage.php's generate_staff_username()).
function generate_teacher_login_username(mysqli $conn, string $given_name, string $middle_name, string $family_name): string {
    $first = strtolower(preg_replace('/[^a-zA-Z]/', '', $given_name));
    $mid   = strtolower(preg_replace('/[^a-zA-Z]/', '', $middle_name));
    $last  = strtolower(preg_replace('/[^a-zA-Z]/', '', $family_name));
    $base  = trim($first . $mid . '.' . $last, '.');
    if ($base === '') {
        $base = 'teacher';
    }

    $username = $base;
    $n = 2;
    while (true) {
        $check = $conn->prepare("SELECT 1 FROM users WHERE username = ?");
        $check->bind_param('s', $username);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$exists) {
            return $username;
        }
        $username = $base . $n;
        $n++;
    }
}

// An 8-character temporary password from an unambiguous charset (no 0/O,
// 1/l/I) — never derived from any account information, and only ever held
// in memory for this one request (display + email); only its bcrypt hash
// is stored.
function generate_temp_password(): string {
    $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max     = strlen($charset) - 1;
    $pw      = '';
    for ($i = 0; $i < 8; $i++) {
        $pw .= $charset[random_int(0, $max)];
    }
    return $pw;
}

// Two-letter (first + last) initials for the avatar fallback — same
// technique as admin/staff_manage.php's staff_initials().
function tm_initials(string $given, string $family): string {
    $g = mb_substr(trim($given), 0, 1);
    $f = mb_substr(trim($family), 0, 1);
    $initials = mb_strtoupper($g . $f);
    return $initials !== '' ? $initials : '?';
}

function tm_display_name(array $t): string {
    return trim(($t['given_name'] ?? '') . ' ' . ($t['middle_name'] ? $t['middle_name'] . ' ' : '') . ($t['family_name'] ?? '') . ($t['suffix'] ? ' ' . $t['suffix'] : ''));
}

function tm_search_key(array $t): string {
    $parts = [$t['given_name'] ?? '', $t['middle_name'] ?? '', $t['family_name'] ?? '', $t['username'] ?? '', $t['email'] ?? '', $t['strand_code'] ?? ''];
    return mb_strtolower(trim(implode(' ', $parts)));
}

// Maps a strand code to one of the .strand-badge--* color modifiers defined
// in this page's <style> block — TVL/CORE are included for legacy rows even
// though the Add teacher form no longer offers them.
function tm_strand_class(?string $code): string {
    $map = ['STEM' => 'stem', 'HUMSS' => 'humss', 'ABM' => 'abm', 'GAS' => 'gas', 'TVL' => 'tvl', 'CORE' => 'core'];
    return $map[$code] ?? 'none';
}

// Optional profile photo upload — same validation/storage convention as
// roles/teacher/teacher_profile.php's own upload handler (extension
// allow-list, 3 MB cap, uploads/profile_photos/, never served directly).
// Returns the stored filename, or null if no file was submitted. Throws
// nothing — an upload problem is reported via $error by the caller.
function tm_handle_photo_upload(array $file, ?string &$upload_error): ?string {
    $upload_error = '';
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_error = 'Could not upload the photo. Please try again.';
        return null;
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        $upload_error = 'Photo must be 3 MB or smaller.';
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $upload_error = 'Photo must be a JPG or PNG file.';
        return null;
    }
    $stored_name = uniqid('teacher_', true) . '.' . $ext;
    $dest = __DIR__ . '/../../../uploads/profile_photos/' . $stored_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $upload_error = 'Could not upload the photo. Please try again.';
        return null;
    }
    return $stored_name;
}

$success         = $_SESSION['teacher_flash'] ?? '';
unset($_SESSION['teacher_flash']);
$error           = '';
$new_credentials = null; // set only right after a successful create, for the one-time reveal

// ── Add teacher POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher'])) {
    $family_name = trim($_POST['family_name'] ?? '');
    $given_name  = trim($_POST['given_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $suffix      = trim($_POST['suffix'] ?? '');
    $contact     = trim($_POST['contact_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $strand_id   = $_POST['strand_id'] ?? '';

    $valid_strand_ids = array_column($strands, 'strand_id');
    $strand_id = ($strand_id !== '' && in_array((int)$strand_id, $valid_strand_ids, true)) ? (int)$strand_id : null;

    if (!$family_name || !$given_name || !$email) {
        $error = 'First name, last name, and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
        $error = 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).';
    } else {
        $photo_error = '';
        $photo_name  = tm_handle_photo_upload($_FILES['photo'] ?? [], $photo_error);
        if ($photo_error) {
            $error = $photo_error;
        }
    }

    if (!$error) {
        $faculty_department_id = department_id($conn, 'faculty');

        $username = generate_teacher_login_username($conn, $given_name, $middle_name, $family_name);
        $temp_password = generate_temp_password();
        $hash = password_hash($temp_password, PASSWORD_BCRYPT);

        $conn->begin_transaction();
        try {
            $t = $conn->prepare("
                INSERT INTO teachers (family_name, given_name, middle_name, suffix, email, contact_number, department_id, strand, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $t->bind_param('ssssssii', $family_name, $given_name, $middle_name, $suffix, $email, $contact, $faculty_department_id, $strand_id);
            $t->execute();
            $teacher_id = $conn->insert_id;
            $t->close();

            $u = $conn->prepare("
                INSERT INTO users (username, password_hash, role, is_active, must_change_password, photo_path)
                VALUES (?, ?, 'teacher', 1, 1, ?)
            ");
            $u->bind_param('sss', $username, $hash, $photo_name);
            $u->execute();
            $new_user_id = $conn->insert_id;
            $u->close();

            $upd = $conn->prepare("UPDATE teachers SET user_id = ? WHERE teacher_id = ?");
            $upd->bind_param('ii', $new_user_id, $teacher_id);
            $upd->execute();
            $upd->close();

            $conn->commit();

            $mail_sent = false;
            try {
                $mail = getMailer();
                $bodyHtml = '<p>Hi ' . htmlspecialchars($given_name) . ', an account has been created for you on the Enrollment Management System as a <strong>Teacher</strong>.</p>'
                    . email_detail_rows([
                        'Username'           => $username,
                        'Temporary Password' => $temp_password,
                    ])
                    . '<p style="margin-top:16px;">You\'ll be asked to set your own password the first time you log in.</p>';
                $altBody = "An account has been created for you as a Teacher.\nUsername: $username\nTemporary Password: $temp_password\n\nYou'll be asked to set your own password the first time you log in.";

                $mail_sent = send_branded_email(
                    $mail,
                    $email,
                    trim($given_name . ' ' . $family_name),
                    'Your Teacher Account — Greenfield Senior High School',
                    'Teacher Account Created',
                    $bodyHtml,
                    $altBody
                );
            } catch (\Throwable $e) {
                $mail_sent = false;
            }

            $new_credentials = [
                'username'  => $username,
                'password'  => $temp_password,
                'mail_sent' => $mail_sent,
                'name'      => trim($given_name . ' ' . $family_name),
            ];
        } catch (Exception $e) {
            $conn->rollback();
            if ($photo_name) { @unlink(__DIR__ . '/../../../uploads/profile_photos/' . $photo_name); }
            $error = 'Failed to create teacher account. Please try again.';
        }
    }
}

// ── Toggle login active status (single row) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $active = (int)($_POST['is_active'] ?? 0);
    $new    = $active ? 0 : 1;
    $stmt   = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ? AND role = 'teacher'");
    $stmt->bind_param('ii', $new, $uid);
    $stmt->execute();
    header("Location: teacher_manage");
    exit();
}

// ── Bulk activate / deactivate (from the selection bar) ─────────────────────
// Only teacher accounts are touched, and only rows that would actually
// change, so the count in the confirmation toast is the number really affected.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_status'])) {
    $to  = ($_POST['bulk_status'] === 'activate') ? 1 : 0;
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE role = 'teacher' AND is_active <> ? AND user_id IN ($placeholders)");
        $stmt->bind_param('ii' . str_repeat('i', count($ids)), $to, $to, ...$ids);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();

        $_SESSION['teacher_flash'] = $n > 0
            ? $n . ($n === 1 ? ' account ' : ' accounts ') . ($to ? 'activated.' : 'deactivated.')
            : 'No accounts needed changing.';
    }
    header("Location: teacher_manage");
    exit();
}

// ── Fetch teacher list ───────────────────────────────────────────────────────
$sql = "
    SELECT t.teacher_id, t.family_name, t.given_name, t.middle_name, t.suffix, t.email, t.contact_number, t.created_at,
           s.strand_code, u.user_id, u.username, u.is_active, u.photo_path
    FROM teachers t
    LEFT JOIN strands s ON s.strand_id = t.strand
    LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
    ORDER BY t.family_name ASC, t.given_name ASC
";
$result = mysqli_query($conn, $sql);
$teacher_list = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) { $teacher_list[] = $row; }
}

foreach ($teacher_list as &$t) {
    $t['full_name'] = tm_display_name($t);
    $t['state'] = $t['username'] === null ? 'none' : ($t['is_active'] ? 'active' : 'inactive');
}
unset($t);

$state_counts = array_count_values(array_column($teacher_list, 'state'));

// ── Export CSV ───────────────────────────────────────────────────────────
$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if (($req['export'] ?? '') === 'csv') {
    $export_rows = $teacher_list;
    if (!empty($req['ids'])) {
        $selected_ids = array_map('intval', explode(',', (string)$req['ids']));
        $export_rows = array_values(array_filter($teacher_list, function ($t) use ($selected_ids) {
            return in_array((int)$t['teacher_id'], $selected_ids, true);
        }));
    }

    $state_labels = ['none' => 'No account', 'active' => 'Active', 'inactive' => 'Inactive'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teachers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Username', 'Email', 'Contact Number', 'Strand', 'Account Status', 'Date Added']);
    foreach ($export_rows as $t) {
        fputcsv($out, [
            $t['full_name'],
            $t['username'] ?? '',
            $t['email'] ?? '',
            $t['contact_number'] ?? '',
            $t['strand_code'] ?? '',
            $state_labels[$t['state']],
            $t['created_at'] ? date('Y-m-d', strtotime($t['created_at'])) : '',
        ]);
    }
    fclose($out);
    exit();
}

// NOTE: do NOT close $conn here — staff_sidebar.php / staff_notifications.php
// (included below, in the HTML) run their own queries and need it open.

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$svg_mail  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
$svg_phone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Teachers — Coordinator</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <!-- css_admin.css supplies the staff-hero/staff-toolbar/staff-table/modal
       component classes this page is styled with (same components
       admin/staff_manage.php and admin/teacher_accounts.php use) — its
       generic `body { display:flex }` is overridden by css_staff.css's
       higher-specificity `body.staff-layout` rule below, so it can't
       disturb the staff sidebar layout. -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_admin.css') ?>">
  <style>
    /* css_staff.css's generic `select { width:100% }` (needed elsewhere on
       staff pages) otherwise wins over .staff-filter-select — which sets no
       width of its own — and stretches each dropdown to fill the row,
       wrapping search/strand/sort onto separate lines instead of sitting
       in one row like admin/staff_manage.php's toolbar. */
    .staff-toolbar .staff-filter-select { width: auto; flex: 0 0 auto; }

    /* Color-coded, higher-contrast strand badges — .staff-role-tag is a
       flat single-color tag everywhere else, but a per-strand color makes
       the column scannable at a glance. */
    .strand-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 6px;
      font-size: 12px; font-weight: 700; letter-spacing: .01em; white-space: nowrap; }
    .strand-badge--stem  { background: #E1ECEA; color: #1F5E57; }
    .strand-badge--humss { background: #E6ECF2; color: #34526E; }
    .strand-badge--abm   { background: #EFEEE2; color: #5E5A2A; }
    .strand-badge--gas   { background: #E4F0E8; color: #2D6547; }
    .strand-badge--tvl   { background: #F1EBE3; color: #6B4E2E; }
    .strand-badge--core  { background: #E9ECEB; color: #44524B; }
    .strand-badge--none  { background: #EDEDF0; color: #4A5560; }
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

      <div class="staff-hero staff-hero--row">
        <div class="staff-hero-text">
          <p class="page-eyebrow">Coordinator Portal</p>
          <h1 class="page-title">Teacher management</h1>
          <p class="staff-hero-desc">Add teachers and give them a portal login. Each new account gets a temporary password, shown once, that the teacher must change at first sign-in.</p>
        </div>
        <?php if ($teacher_list): ?>
          <div class="staff-hero-actions">
            <button type="button" class="btn-export-csv" id="tblExport">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
              <span id="tblExportLabel">Export CSV</span>
            </button>
            <button type="button" class="btn-add-staff" id="btnOpenAddTeacher">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              Add teacher
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="staff-overlap">

      <?php if ($success): ?>
        <div class="alert alert-success" data-ui-toast><?= $h($success) ?></div>
      <?php endif; ?>

      <div class="card staff-panel">
        <?php if (empty($teacher_list)): ?>
          <p class="empty-state">No teachers yet. Use "Add teacher" to create the first one.</p>
        <?php else: ?>

        <div class="staff-toolbar">
          <div class="staff-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <label for="tblSearch" class="sr-only">Search teachers</label>
            <input type="search" id="tblSearch" placeholder="Search by name, username, or email" autocomplete="off">
          </div>
          <select class="staff-filter-select" name="strand" data-filter-select data-attr="strand" aria-label="Filter by strand">
            <option value="">All strands</option>
            <?php foreach ($strands as $st): ?>
              <option value="<?= $h($st['strand_code']) ?>"><?= $h($st['strand_code']) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="staff-filter-select" id="tblSort" aria-label="Sort teachers">
            <option value="name:asc">Sort: Name (A to Z)</option>
            <option value="name:desc">Sort: Name (Z to A)</option>
            <option value="joined:desc" data-num>Sort: Newest added</option>
            <option value="joined:asc" data-num>Sort: Oldest added</option>
          </select>
          <button type="button" class="staff-toolbar-reset hidden" id="tblReset">Reset filters</button>
        </div>

        <div class="staff-pills" role="group" aria-label="Filter by account status" data-pills data-attr="state" data-param="status">
          <button type="button" class="staff-pill active" data-value="" aria-pressed="true">All <span data-count><?= count($teacher_list) ?></span></button>
          <button type="button" class="staff-pill" data-value="active" aria-pressed="false">Active <span data-count><?= (int)($state_counts['active'] ?? 0) ?></span></button>
          <button type="button" class="staff-pill" data-value="inactive" aria-pressed="false">Inactive <span data-count><?= (int)($state_counts['inactive'] ?? 0) ?></span></button>
          <?php if (!empty($state_counts['none'])): ?>
            <button type="button" class="staff-pill" data-value="none" aria-pressed="false">No account <span data-count><?= (int)$state_counts['none'] ?></span></button>
          <?php endif; ?>
        </div>

        <div class="staff-bulkbar hidden" id="tblBulkBar" role="region" aria-label="Bulk actions">
          <strong class="staff-bulk-count" id="tblBulkCount" aria-live="polite">0 selected</strong>
          <button type="button" class="staff-bulk-btn" id="bulkActivate">Activate</button>
          <button type="button" class="staff-bulk-btn" id="bulkDeactivate">Deactivate</button>
          <button type="button" class="staff-bulk-btn" data-bulk="export">Export selected</button>
          <button type="button" class="staff-bulk-clear" data-bulk="clear">Clear selection</button>
        </div>

        <table class="data-table staff-table" id="dataTable">
          <thead>
            <tr>
              <th class="staff-table-checkbox-col"><input type="checkbox" id="tblSelectAll" aria-label="Select all on this page"></th>
              <th>Teacher</th>
              <th>Contact</th>
              <th>Strand</th>
              <th>Status</th>
              <th>Date Added</th>
              <th class="staff-actions-col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($teacher_list as $t): ?>
              <tr class="<?= $t['state'] === 'active' ? '' : 'is-dim' ?>"
                  data-search="<?= $h(tm_search_key($t)) ?>"
                  data-state="<?= $t['state'] ?>"
                  data-strand="<?= $h($t['strand_code'] ?? '') ?>"
                  data-sort-name="<?= $h(mb_strtolower($t['full_name'])) ?>"
                  data-sort-joined="<?= $t['created_at'] ? strtotime($t['created_at']) : 0 ?>">
                <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$t['teacher_id'] ?>" aria-label="Select <?= $h($t['full_name']) ?>"></td>
                <td class="td-name">
                  <div class="td-name-cell">
                    <?php if (!empty($t['photo_path'])): ?>
                      <img class="staff-table-avatar" style="object-fit:cover;" src="teacher_photo_view?id=<?= (int)$t['teacher_id'] ?>&amp;v=<?= urlencode($t['photo_path']) ?>" alt="">
                    <?php else: ?>
                      <span class="staff-table-avatar"><?= $h(tm_initials($t['given_name'] ?? '', $t['family_name'] ?? '')) ?></span>
                    <?php endif; ?>
                    <span class="staff-name-stack">
                      <span class="staff-name-primary"><?= $h($t['full_name']) ?></span>
                      <span class="staff-name-secondary"><?= $t['username'] !== null ? '@' . $h($t['username']) : 'No login yet' ?></span>
                    </span>
                  </div>
                </td>
                <td>
                  <?php if (!empty($t['email'])): ?>
                    <div class="staff-contact-line"><?= $svg_mail ?><span><?= $h($t['email']) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($t['contact_number'])): ?>
                    <div class="staff-contact-line"><?= $svg_phone ?><span><?= $h($t['contact_number']) ?></span></div>
                  <?php endif; ?>
                  <?php if (empty($t['email']) && empty($t['contact_number'])): ?><span class="td-meta">—</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($t['strand_code']): ?>
                    <span class="strand-badge strand-badge--<?= tm_strand_class($t['strand_code']) ?>"><?= $h($t['strand_code']) ?></span>
                  <?php else: ?>
                    <span class="td-meta">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($t['state'] === 'none'): ?>
                    <span class="staff-state staff-state--pending"><i></i>No account</span>
                  <?php elseif ($t['state'] === 'active'): ?>
                    <span class="staff-state staff-state--active"><i></i>Active</span>
                  <?php else: ?>
                    <span class="staff-state staff-state--inactive"><i></i>Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="staff-joined"><?= $t['created_at'] ? $h(date('M j, Y', strtotime($t['created_at']))) : '—' ?></td>
                <td class="staff-actions-col">
                  <?php if ($t['username'] !== null): ?>
                    <form method="POST" action="teacher_manage" style="display:inline;"
                          data-confirm="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?> <?= $h($t['full_name']) ?>'s login?">
                      <input type="hidden" name="user_id" value="<?= (int)$t['user_id'] ?>">
                      <input type="hidden" name="is_active" value="<?= (int)$t['is_active'] ?>">
                      <button type="submit" name="toggle_active" class="tbl-action-btn">
                        <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="empty-state hidden" data-no-match>
          <strong>No teachers match these filters</strong>
          Try a different name, strand, or status.
          <div><button type="button" class="staff-btn staff-btn--ghost" data-clear-filters>Clear filters</button></div>
        </div>

        <?php $tbl_total = count($teacher_list); $tbl_noun = 'teachers'; include BASE_PATH . '/shared/includes/admin_table_footer.php'; ?>

        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <!-- Add teacher modal -->
  <div class="modal-overlay staff-modal-overlay<?= $error ? '' : ' hidden' ?>" id="addTeacherOverlay" role="dialog" aria-modal="true" aria-labelledby="addTeacherTitle">
    <div class="modal-panel staff-modal">
      <div class="staff-modal-header">
        <div>
          <h2 id="addTeacherTitle">Add teacher</h2>
          <p class="staff-modal-sub">Creates the teacher record and a portal login together. A temporary password is generated and emailed to them.</p>
        </div>
        <button type="button" class="staff-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= $h($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="teacher_manage" enctype="multipart/form-data" data-confirm="Create this teacher account?" data-icon="question">
        <div class="staff-form-grid">
          <div class="form-group" style="grid-column:1/-1; display:flex; align-items:center; gap:12px;">
            <span class="staff-table-avatar" style="width:44px; height:44px; font-size:15px;">+</span>
            <div>
              <label for="tf_photo" style="margin-bottom:.2rem;">Profile photo</label>
              <input id="tf_photo" type="file" name="photo" accept=".jpg,.jpeg,.png">
              <p class="staff-field-hint">Optional. JPG or PNG, 3 MB max.</p>
            </div>
          </div>
          <div class="form-group">
            <label for="tf_given_name">First name <span class="req">*</span></label>
            <input id="tf_given_name" type="text" name="given_name" placeholder="e.g. Maria" required maxlength="50" autocomplete="off"
              value="<?= $h($_POST['given_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="tf_middle_name">Middle name</label>
            <input id="tf_middle_name" type="text" name="middle_name" placeholder="Optional" maxlength="50" autocomplete="off"
              value="<?= $h($_POST['middle_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="tf_family_name">Last name <span class="req">*</span></label>
            <input id="tf_family_name" type="text" name="family_name" placeholder="e.g. Dela Cruz" required maxlength="50" autocomplete="off"
              value="<?= $h($_POST['family_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="tf_suffix">Suffix</label>
            <input id="tf_suffix" type="text" name="suffix" placeholder="Jr., Sr., III, etc. (optional)" maxlength="10" autocomplete="off"
              value="<?= $h($_POST['suffix'] ?? '') ?>">
          </div>
          <div class="form-group form-group--wide">
            <label for="tf_email">Email address <span class="req">*</span></label>
            <input id="tf_email" type="email" name="email" placeholder="name@example.com" required maxlength="100" autocomplete="off"
              value="<?= $h($_POST['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="tf_contact">Contact number</label>
            <input id="tf_contact" type="tel" name="contact_number" placeholder="09171234567" inputmode="numeric" maxlength="11" autocomplete="off"
              value="<?= $h($_POST['contact_number'] ?? '') ?>">
            <p class="staff-field-hint">11 digits, starts with 09</p>
          </div>
          <div class="form-group">
            <label for="tf_strand">Strand</label>
            <select id="tf_strand" name="strand_id">
              <option value="">Not assigned</option>
              <?php foreach ($strands as $st): ?>
                <option value="<?= (int)$st['strand_id'] ?>" <?= ($_POST['strand_id'] ?? '') == $st['strand_id'] ? 'selected' : '' ?>><?= $h($st['strand_code']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="staff-field-hint" style="margin:.6rem 0 0;">Department is set to Faculty automatically. Username and a temporary password are generated automatically.</p>
        <div class="staff-modal-actions">
          <button type="button" class="staff-btn staff-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="add_teacher" class="staff-btn staff-btn--primary">Create teacher account</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($teacher_list): ?>
    <form method="POST" action="teacher_manage" id="bulkForm" class="hidden">
      <input type="hidden" name="bulk_status" id="bulkStatus" value="">
      <div id="bulkIds"></div>
    </form>

    <script src="<?= APP_URL ?>/assets/js/admin_table.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/admin_table.js') ?>"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        function submitBulk(action, rows) {
          document.getElementById('bulkStatus').value = action;
          var box = document.getElementById('bulkIds');
          box.innerHTML = '';
          rows.forEach(function (r) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'user_ids[]';
            input.value = r._cb.value;
            box.appendChild(input);
          });
          document.getElementById('bulkForm').submit();
        }

        AdminTable.init({
          noun: 'teachers',
          bulk: [
            {
              id: 'bulkActivate',
              when: function (rows) { return rows.some(function (r) { return r.getAttribute('data-state') === 'inactive'; }); },
              run: function (rows) {
                var todo = rows.filter(function (r) { return r.getAttribute('data-state') === 'inactive'; });
                if (todo.length) submitBulk('activate', todo);
              }
            },
            {
              id: 'bulkDeactivate',
              when: function (rows) { return rows.some(function (r) { return r.getAttribute('data-state') === 'active'; }); },
              run: function (rows) {
                var todo = rows.filter(function (r) { return r.getAttribute('data-state') === 'active'; });
                if (todo.length) submitBulk('deactivate', todo);
              }
            }
          ]
        });

        // ── Add teacher modal open/close (the "hide" control on the modal
        // is its ✕ button and Cancel button, both marked [data-close-modal]) ──
        var overlay = document.getElementById('addTeacherOverlay');
        var openBtn = document.getElementById('btnOpenAddTeacher');
        if (openBtn) {
          openBtn.addEventListener('click', function () {
            overlay.classList.remove('hidden');
            document.getElementById('tf_given_name').focus();
          });
        }
        overlay.addEventListener('click', function (e) {
          if (e.target === overlay || e.target.closest('[data-close-modal]')) overlay.classList.add('hidden');
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') overlay.classList.add('hidden');
        });
      });
    </script>
  <?php endif; ?>

  <?php if ($new_credentials): ?>
    <div class="modal-overlay" id="credOverlay">
      <div class="modal-panel cred-modal">
        <h2>Teacher Account Created</h2>

        <?php if ($new_credentials['mail_sent']): ?>
          <p class="cred-sub">Credentials have also been emailed to <?= $h($new_credentials['name']) ?>.</p>
        <?php else: ?>
          <div class="alert alert-warning">
            <strong>Email delivery failed.</strong> These credentials were not sent to <?= $h($new_credentials['name']) ?> — copy them now and share them directly.
          </div>
        <?php endif; ?>

        <div class="cred-rows">
          <div class="cred-row">
            <span class="cred-row-label">Username</span>
            <span class="cred-row-value" id="credUsername"><?= htmlspecialchars($new_credentials['username']) ?></span>
          </div>
          <div class="cred-row">
            <span class="cred-row-label">Temporary Password</span>
            <span class="cred-row-value" id="credPassword"><?= htmlspecialchars($new_credentials['password']) ?></span>
          </div>
        </div>

        <p class="cred-note"><strong>This password will not be shown again.</strong> Copy it before closing — a password change is required on first login.</p>

        <div class="cred-actions">
          <button type="button" class="btn-cred-copy" id="btnCopyCred">Copy Credentials</button>
          <button type="button" class="btn-cred-done" id="btnDoneCred">Done</button>
        </div>
      </div>
    </div>

    <script>
      (function () {
        var overlay = document.getElementById('credOverlay');
        var copyBtn = document.getElementById('btnCopyCred');
        var doneBtn = document.getElementById('btnDoneCred');
        var username = document.getElementById('credUsername').textContent;
        var password = document.getElementById('credPassword').textContent;
        var hasCopied = false; // this password exists nowhere else once the modal closes

        copyBtn.addEventListener('click', function () {
          var text = 'Username: ' + username + '\nTemporary Password: ' + password;
          var done = function () {
            hasCopied = true;
            copyBtn.textContent = 'Copied!';
            copyBtn.classList.add('copied');
            setTimeout(function () {
              copyBtn.textContent = 'Copy Credentials';
              copyBtn.classList.remove('copied');
            }, 1500);
          };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, done);
          } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
          }
        });

        function closeCredModal() { overlay.remove(); }

        doneBtn.addEventListener('click', function () {
          if (hasCopied) { closeCredModal(); return; }

          var warning = 'You haven’t copied the temporary password yet. It cannot be recovered once this closes.';
          if (typeof Swal === 'undefined') {
            if (window.confirm(warning + ' Close anyway?')) closeCredModal();
            return;
          }
          Swal.fire({
            title: 'Close without copying?',
            text: warning,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#C0392B',
            cancelButtonColor: '#386641',
            confirmButtonText: 'Close anyway',
            cancelButtonText: 'Go back and copy'
          }).then(function (result) {
            if (result.isConfirmed) closeCredModal();
          });
        });
      })();
    </script>
  <?php endif; ?>

</body>
</html>
