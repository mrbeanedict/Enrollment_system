<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../config/mail.php';

require_login('admin');

// "Suffix" (Jr., III, …) is new; older databases only have middle_name.
if (mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM staff LIKE 'suffix'")) === 0) {
    mysqli_query($conn, "ALTER TABLE staff ADD COLUMN suffix VARCHAR(10) NULL AFTER middle_name");
}

// Departments a staff account can be provisioned into (excludes 'faculty' —
// teachers are provisioned separately via admin/teacher_accounts.php).
$staff_roles = [
    'registrar'   => 'Registrar',
    'treasury'    => 'Treasury',
    'records'     => 'Records',
    'coordinator' => 'Coordinator',
    'scheduler'   => 'Scheduler',
];

const STAFF_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

// Collapse runs of whitespace so "Juan   Dela  Cruz " is stored as one clean value.
function staff_clean_name(string $v): string {
    return trim(preg_replace('/\s+/u', ' ', $v));
}

// "Given Family Suffix" — what the list, cards and confirmations show.
function staff_display_name(array $st): string {
    return trim(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? '') . ' ' . ($st['suffix'] ?? ''));
}

// Server-side twin of the client checks in the Add/Edit modals (the client
// rules are a convenience; these are the ones that count). Column widths:
// names 50, email 100, contact 11 digits. Returns [cleaned values, error].
function staff_validate(array $post, array $roles): array {
    $v = [
        'given'   => staff_clean_name((string)($post['given_name'] ?? '')),
        'middle'  => staff_clean_name((string)($post['middle_name'] ?? '')),
        'family'  => staff_clean_name((string)($post['family_name'] ?? '')),
        'suffix'  => trim((string)($post['suffix'] ?? '')),
        'contact' => trim((string)($post['contact_number'] ?? '')),
        'email'   => trim((string)($post['email'] ?? '')),
        'role'    => (string)($post['role'] ?? ''),
    ];

    $names = [
        ['given',  'First name',  true,  2],
        ['middle', 'Middle name', false, 1],
        ['family', 'Last name',   true,  2],
    ];
    foreach ($names as [$key, $label, $required, $min]) {
        $len = mb_strlen($v[$key]);
        if ($len === 0) {
            if ($required) { return [$v, "$label is required."]; }
            continue;
        }
        if ($len < $min) { return [$v, "$label must be at least $min characters."]; }
        if ($len > 50)   { return [$v, "$label must be 50 characters or fewer."]; }
        if (!preg_match("/^\\p{L}[\\p{L} .'\\-]*$/u", $v[$key])) {
            return [$v, "$label can only contain letters, spaces, hyphens, apostrophes and periods."];
        }
    }

    if ($v['suffix'] !== '' && !in_array($v['suffix'], STAFF_SUFFIXES, true)) {
        return [$v, 'Please choose a valid suffix.'];
    }
    if ($v['contact'] !== '' && !preg_match('/^09\d{9}$/', $v['contact'])) {
        return [$v, 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).'];
    }
    if ($v['email'] === '') { return [$v, 'Email address is required.']; }
    if (mb_strlen($v['email']) > 100 || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        return [$v, 'Please enter a valid email address (100 characters or fewer).'];
    }
    if (!array_key_exists($v['role'], $roles)) { return [$v, 'Please select a valid role.']; }

    return [$v, ''];
}

// firstname.lastname, lowercase letters only; a collision (e.g. two "Juan
// Dela Cruz") falls back to appending an incrementing number.
function generate_staff_username(mysqli $conn, string $given_name, string $family_name): string {
    $first = strtolower(preg_replace('/[^a-zA-Z]/', '', $given_name));
    $last  = strtolower(preg_replace('/[^a-zA-Z]/', '', $family_name));
    $base  = trim($first . '.' . $last, '.');
    if ($base === '') {
        $base = 'staff';
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

// Two-letter (first + last) initials for the staff-card avatar — same
// gradient/circle technique as sidebar.php's .sidebar-avatar, but two
// letters instead of one since this is a directory of many people, not
// a single logged-in user. '?' fallback guards a users row with no
// matching staff row (possible via the LEFT JOIN below).
function staff_initials(string $given, string $family): string {
    $g = mb_substr(trim($given), 0, 1);
    $f = mb_substr(trim($family), 0, 1);
    $initials = mb_strtoupper($g . $f);
    return $initials !== '' ? $initials : '?';
}

// Lowercased "name username email" blob for the topbar search box — computed
// once per row here so the client-side filter is a plain substring check
// against one attribute, not a live multi-field read per keystroke.
function staff_search_key(array $st): string {
    $parts = [$st['given_name'] ?? '', $st['middle_name'] ?? '', $st['family_name'] ?? '', $st['username'] ?? '', $st['email'] ?? ''];
    return mb_strtolower(trim(implode(' ', $parts)));
}

// Per-department accent used for this page's role-chip dot and avatar tint
// only — a small, fixed lookup (not user-configurable, not stored),
// matching admin/staff_manage_dupe.php's own palette exactly (its inline
// per-person `color` values) rather than the app's older green/purple/blue
// set, so the department accents read the same on this page's new glass
// surfaces. Falls back to a neutral slate for any department not in
// $staff_roles (defensive only — every real row's department comes from
// that same fixed set).
function staff_department_color(?string $dept_key): string {
    static $colors = [
        'registrar'   => '#1E4D3B',
        'treasury'    => '#A8752F',
        'records'     => '#3E6B96',
        'coordinator' => '#5B3E96',
        'scheduler'   => '#2F6B4F',
    ];
    return $colors[$dept_key] ?? '#7C9086';
}

// The ⋮ overflow menu shared by the table rows and the grid cards: View /
// Edit / Reset Password, then Activate/Deactivate. The staff's details ride
// along as data-* on the wrapper so the View and Edit modals can fill
// themselves client-side without another request.
function staff_row_menu(array $st, string $extra_class = ''): string {
    $full_name = staff_display_name($st);
    $h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $uid = (int)$st['user_id'];
    $active = (int)$st['is_active'];

    ob_start(); ?>
    <div class="staff-card-menu <?= $h($extra_class) ?>"
         data-user-id="<?= $uid ?>"
         data-given="<?= $h($st['given_name'] ?? '') ?>"
         data-middle="<?= $h($st['middle_name'] ?? '') ?>"
         data-suffix="<?= $h($st['suffix'] ?? '') ?>"
         data-family="<?= $h($st['family_name'] ?? '') ?>"
         data-username="<?= $h($st['username']) ?>"
         data-email="<?= $h($st['email'] ?? '') ?>"
         data-contact="<?= $h($st['contact_number'] ?? '') ?>"
         data-dept="<?= $h($st['department_name'] ?? '') ?>"
         data-account-status="<?= $active ? 'Active' : 'Inactive' ?>"
         data-joined="<?= $h($st['created_at'] ? date('M j, Y', strtotime($st['created_at'])) : '—') ?>">
      <button type="button" class="staff-card-menu-btn" aria-label="More actions for <?= $h($full_name) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle>
        <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
      </button>
      <div class="staff-card-menu-dropdown" role="menu">
        <button type="button" class="staff-card-menu-item" role="menuitem" data-action="edit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Edit details
        </button>
        <form method="POST" action="staff_manage" data-confirm="Send a password reset to <?= $h($full_name) ?>? A new temporary password is generated and emailed to them, and the current password stops working.">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <button type="submit" name="reset_staff_password" class="staff-card-menu-item" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.7-8.7M16 7l3 3M14 9l2 2"/></svg>Send password reset
          </button>
        </form>
        <div class="staff-card-menu-divider"></div>
        <form method="POST" action="staff_manage" data-confirm="<?= $active ? 'Deactivate' : 'Reactivate' ?> the account for <?= $h($full_name) ?>?">
          <input type="hidden" name="user_id"   value="<?= $uid ?>">
          <input type="hidden" name="is_active" value="<?= $active ?>">
          <button type="submit" name="toggle_active" class="staff-card-menu-item<?= $active ? ' staff-card-menu-item-danger' : '' ?>" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $active ? '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>' : '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>' ?></svg><?= $active ? 'Deactivate account' : 'Reactivate account' ?>
          </button>
        </form>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// Guards against silently minting a second account for the same person —
// e.g. a re-click during the synchronous email send below, or a re-submit
// of the Add Staff form after a successful create (see $form_values below).
function staff_email_exists(mysqli $conn, string $email, int $exclude_user_id = 0): bool {
    $stmt = $conn->prepare("SELECT 1 FROM staff WHERE email = ? AND user_id <> ? LIMIT 1");
    $stmt->bind_param('si', $email, $exclude_user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Success message carried across the post-redirect-get after an edit.
$success = $_SESSION['staff_flash'] ?? '';
unset($_SESSION['staff_flash']);

$error            = '';
$edit_error       = '';
$action_error     = ''; // page-level failure (e.g. password reset) — shown above the list, not in a modal
$new_credentials  = null; // set only right after a successful create/reset, for the one-time reveal
$cred_title       = 'Staff Account Created';

// ── Add staff POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    [$in, $error] = staff_validate($_POST, $staff_roles);
    $family_name = $in['family'];
    $given_name  = $in['given'];
    $middle_name = $in['middle'] !== '' ? $in['middle'] : null;
    $suffix      = $in['suffix'] !== '' ? $in['suffix'] : null;
    $contact     = $in['contact'] !== '' ? $in['contact'] : null;
    $email       = $in['email'];
    $role_key    = $in['role'];

    if ($error) {
        // shown in the Add staff modal, which reopens itself
    } elseif (staff_email_exists($conn, $email)) {
        $error = 'An account with this email address already exists.';
    } else {
        $department_id = department_id($conn, $role_key);

        $username = generate_staff_username($conn, $given_name, $family_name);
        $temp_password = generate_temp_password();
        $hash = password_hash($temp_password, PASSWORD_BCRYPT);

        $conn->begin_transaction();
        try {
            $u = $conn->prepare("INSERT INTO users (username, password_hash, role, is_active, must_change_password) VALUES (?, ?, 'staff', 1, 1)");
            $u->bind_param('ss', $username, $hash);
            $u->execute();
            $uid = $conn->insert_id;

            $s = $conn->prepare("INSERT INTO staff (user_id, department_id, family_name, given_name, middle_name, suffix, contact_number, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $s->bind_param('iissssss', $uid, $department_id, $family_name, $given_name, $middle_name, $suffix, $contact, $email);
            $s->execute();

            $conn->commit();

            $mail_sent = false;
            try {
                $mail = getMailer();
                $bodyHtml = '<p>Hi ' . htmlspecialchars($given_name) . ', an account has been created for you on the Enrollment Management System as <strong>' . htmlspecialchars($staff_roles[$role_key]) . '</strong>.</p>'
                    . email_detail_rows([
                        'Username'           => $username,
                        'Temporary Password' => $temp_password,
                    ])
                    . '<p style="margin-top:16px;">You\'ll be asked to set your own password the first time you log in.</p>';
                $altBody = "An account has been created for you as {$staff_roles[$role_key]}.\nUsername: $username\nTemporary Password: $temp_password\n\nYou'll be asked to set your own password the first time you log in.";

                $mail_sent = send_branded_email(
                    $mail,
                    $email,
                    trim($given_name . ' ' . $family_name),
                    'Your Staff Account — Greenfield Senior High School',
                    'Staff Account Created',
                    $bodyHtml,
                    $altBody
                );
            } catch (\Throwable $e) {
                $mail_sent = false;
            }

            $new_credentials = [
                'username'   => $username,
                'password'   => $temp_password,
                'mail_sent'  => $mail_sent,
                'name'       => trim($given_name . ' ' . $family_name),
            ];
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to create staff account. Please try again.';
        }
    }
}

// ── Toggle active status ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $active = (int)($_POST['is_active'] ?? 0);
    $new    = $active ? 0 : 1;
    $stmt   = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ? AND role = 'staff'");
    $stmt->bind_param('ii', $new, $uid);
    $stmt->execute();
    header("Location: staff_manage");
    exit();
}

// ── Bulk activate / deactivate (from the selection bar above the table) ─────
// Only staff accounts are touched, and only rows that would actually change,
// so the count in the confirmation toast is the number really affected.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_status'])) {
    $to  = ($_POST['bulk_status'] === 'activate') ? 1 : 0;
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE role = 'staff' AND is_active <> ? AND user_id IN ($placeholders)");
        $stmt->bind_param('ii' . str_repeat('i', count($ids)), $to, $to, ...$ids);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();

        $_SESSION['staff_flash'] = $n > 0
            ? $n . ($n === 1 ? ' account ' : ' accounts ') . ($to ? 'activated.' : 'deactivated.')
            : 'No accounts needed changing.';
    }
    header("Location: staff_manage");
    exit();
}

// ── Edit staff details ─────────────────────────────────────────────────────
// Username is intentionally not editable — it's the login identifier.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff'])) {
    $uid         = (int)($_POST['user_id'] ?? 0);
    [$in, $edit_error] = staff_validate($_POST, $staff_roles);
    $family_name = $in['family'];
    $given_name  = $in['given'];
    $middle_name = $in['middle'] !== '' ? $in['middle'] : null;
    $suffix      = $in['suffix'] !== '' ? $in['suffix'] : null;
    $contact     = $in['contact'] !== '' ? $in['contact'] : null;
    $email       = $in['email'];
    $role_key    = $in['role'];

    $chk = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND role = 'staff'");
    $chk->bind_param('i', $uid);
    $chk->execute();
    $is_staff_user = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$is_staff_user) {
        $edit_error = 'Staff account not found.';
    } elseif ($edit_error) {
        // validation message from staff_validate(), shown in the Edit modal
    } elseif (staff_email_exists($conn, $email, $uid)) {
        $edit_error = 'Another account already uses this email address.';
    } else {
        $department_id = department_id($conn, $role_key);

        // The list page LEFT JOINs staff, so a users row can exist without one.
        $has = $conn->prepare("SELECT 1 FROM staff WHERE user_id = ?");
        $has->bind_param('i', $uid);
        $has->execute();
        $has_row = $has->get_result()->num_rows > 0;
        $has->close();

        if ($has_row) {
            $q = $conn->prepare("UPDATE staff SET department_id = ?, family_name = ?, given_name = ?, middle_name = ?, suffix = ?, contact_number = ?, email = ? WHERE user_id = ?");
            $q->bind_param('issssssi', $department_id, $family_name, $given_name, $middle_name, $suffix, $contact, $email, $uid);
        } else {
            $q = $conn->prepare("INSERT INTO staff (department_id, family_name, given_name, middle_name, suffix, contact_number, email, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $q->bind_param('issssssi', $department_id, $family_name, $given_name, $middle_name, $suffix, $contact, $email, $uid);
        }

        if ($q->execute()) {
            $_SESSION['staff_flash'] = 'Staff details updated.';
            header("Location: staff_manage");
            exit();
        }
        $edit_error = 'Failed to update staff account. Please try again.';
    }
}

// ── Reset staff password ───────────────────────────────────────────────────
// Same one-time-reveal flow as account creation: the new temporary password
// is only ever held in memory for this request (shown + emailed); only its
// bcrypt hash is stored, and must_change_password forces a change at login.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_staff_password'])) {
    $uid = (int)($_POST['user_id'] ?? 0);

    $q = $conn->prepare("
        SELECT u.username, st.given_name, st.family_name, st.email
        FROM users u
        LEFT JOIN staff st ON st.user_id = u.user_id
        WHERE u.user_id = ? AND u.role = 'staff'
    ");
    $q->bind_param('i', $uid);
    $q->execute();
    $target = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$target) {
        $action_error = 'Staff account not found.';
    } else {
        $temp_password = generate_temp_password();
        $hash = password_hash($temp_password, PASSWORD_BCRYPT);

        $u = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ? AND role = 'staff'");
        $u->bind_param('si', $hash, $uid);

        if (!$u->execute()) {
            $action_error = 'Failed to reset the password. Please try again.';
        } else {
            $full_name = trim(($target['given_name'] ?? '') . ' ' . ($target['family_name'] ?? ''));
            $mail_sent = false;
            if (!empty($target['email'])) {
                try {
                    $mail = getMailer();
                    $bodyHtml = '<p>Hi ' . htmlspecialchars($target['given_name'] ?? '') . ', your password on the Enrollment Management System has been reset by an administrator.</p>'
                        . email_detail_rows([
                            'Username'           => $target['username'],
                            'Temporary Password' => $temp_password,
                        ])
                        . '<p style="margin-top:16px;">You\'ll be asked to set your own password the next time you log in.</p>';
                    $altBody = "Your password has been reset by an administrator.\nUsername: {$target['username']}\nTemporary Password: $temp_password\n\nYou'll be asked to set your own password the next time you log in.";

                    $mail_sent = send_branded_email(
                        $mail,
                        $target['email'],
                        $full_name,
                        'Your Password Was Reset — Greenfield Senior High School',
                        'Password Reset',
                        $bodyHtml,
                        $altBody
                    );
                } catch (\Throwable $e) {
                    $mail_sent = false;
                }
            }

            $cred_title = 'Password Reset';
            $new_credentials = [
                'username'  => $target['username'],
                'password'  => $temp_password,
                'mail_sent' => $mail_sent,
                'name'      => $full_name !== '' ? $full_name : $target['username'],
            ];
        }
    }
}

// ── Fetch staff list ───────────────────────────────────────────────────────
$sql = "
    SELECT u.user_id, u.username, u.is_active, u.created_at,
           st.family_name, st.given_name, st.middle_name, st.suffix, st.contact_number, st.email,
           d.department_name
    FROM users u
    LEFT JOIN staff st ON st.user_id = u.user_id
    LEFT JOIN departments d ON d.department_id = st.department_id
    WHERE u.role = 'staff'
    ORDER BY st.family_name ASC, st.given_name ASC
";
$result = mysqli_query($conn, $sql);
$staff_list = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) { $staff_list[] = $row; }
}
$conn->close();

// ── Export CSV ───────────────────────────────────────────────────────────
// ?ids=1,2,3 (built client-side from the checked row checkboxes) restricts
// the export to just those accounts; no ids param exports the full list.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_list = $staff_list;
    if (!empty($_GET['ids'])) {
        $selected_ids = array_map('intval', explode(',', $_GET['ids']));
        $export_list = array_values(array_filter($staff_list, function ($st) use ($selected_ids) {
            return in_array((int)$st['user_id'], $selected_ids, true);
        }));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="staff_accounts_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Username', 'Email', 'Contact Number', 'Department', 'Status', 'Date Joined']);
    foreach ($export_list as $st) {
        fputcsv($out, [
            trim(($st['given_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['family_name'] ?? '') . ' ' . ($st['suffix'] ?? '')),
            $st['username'],
            $st['email'] ?? '',
            $st['contact_number'] ?? '',
            $st['department_name'] ? ucfirst($st['department_name']) : '',
            $st['is_active'] ? 'Active' : 'Inactive',
            $st['created_at'] ? date('Y-m-d', strtotime($st['created_at'])) : '',
        ]);
    }
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right">
        <span class="topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>
    <div class="content">

      <div class="staff-hero staff-hero--row">
        <div class="staff-hero-text">
          <p class="page-eyebrow">Admin Portal</p>
          <h1 class="page-title">Staff management</h1>
          <p class="staff-hero-desc">Manage staff accounts, their roles, and who is able to sign in to the portal.</p>
        </div>
        <div class="staff-hero-actions">
          <button type="button" class="btn-export-csv" id="btnExportCsv">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            <span id="btnExportCsvLabel">Export CSV</span>
          </button>
          <button type="button" class="btn-add-staff" id="btnOpenAddStaff">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Add staff
          </button>
        </div>
      </div>

      <div class="staff-overlap">

      <?php if ($success): ?>
        <div class="alert alert-success" data-ui-toast><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($action_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($action_error) ?></div>
      <?php endif; ?>

      <?php
        $count_active   = count(array_filter($staff_list, fn($s) => $s['is_active']));
        $count_inactive = count($staff_list) - $count_active;
      ?>

      <div class="card staff-panel">

        <!-- Filters + view toggle — kept above both views so they stay
             visible and functional in table and grid mode. -->
        <div class="staff-toolbar">
          <div class="staff-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <label for="staffSearchInput" class="sr-only">Search staff</label>
            <input type="search" id="staffSearchInput" placeholder="Search by name, username, or email">
          </div>
          <select class="staff-filter-select" id="filterDepartment" aria-label="Filter by role">
            <option value="">All roles</option>
            <?php foreach ($staff_roles as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="staff-filter-select" id="sortStaff" aria-label="Sort staff">
            <option value="name-asc">Sort: Name (A to Z)</option>
            <option value="name-desc">Sort: Name (Z to A)</option>
            <option value="joined-desc">Sort: Newest joined</option>
            <option value="joined-asc">Sort: Oldest joined</option>
          </select>
          <div class="view-toggle" role="group" aria-label="Switch view">
            <button type="button" class="view-toggle-btn active" data-view="table" aria-pressed="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>List
            </button>
            <button type="button" class="view-toggle-btn" data-view="grid" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>Cards
            </button>
          </div>
        </div>

        <div class="staff-pills" role="group" aria-label="Filter by status">
          <button type="button" class="staff-pill active" data-filter-status="" aria-pressed="true">All <span data-count="all"><?= count($staff_list) ?></span></button>
          <button type="button" class="staff-pill" data-filter-status="active" aria-pressed="false">Active <span data-count="active"><?= $count_active ?></span></button>
          <button type="button" class="staff-pill" data-filter-status="inactive" aria-pressed="false">Inactive <span data-count="inactive"><?= $count_inactive ?></span></button>
        </div>

        <!-- Bulk actions — appear once at least one row or card is checked -->
        <div class="staff-bulkbar hidden" id="staffBulkBar" role="region" aria-label="Bulk actions">
          <strong class="staff-bulk-count" id="bulkCount" aria-live="polite">0 selected</strong>
          <button type="button" class="staff-bulk-btn" id="bulkExport">Export selected</button>
          <button type="button" class="staff-bulk-btn" id="bulkDeactivate">Deactivate</button>
          <button type="button" class="staff-bulk-btn" id="bulkActivate">Activate</button>
          <button type="button" class="staff-bulk-clear" id="bulkClear">Clear selection</button>
        </div>

        <!-- Staff list — table view -->
        <div class="staff-view" id="staffViewTable">
          <?php if (empty($staff_list)): ?>
            <p class="empty-state">No staff accounts yet.</p>
          <?php else: ?>
            <table class="data-table staff-table">
              <thead>
                <tr>
                  <th class="staff-table-checkbox-col"><input type="checkbox" id="selectAllStaff" aria-label="Select all"></th>
                  <th>Staff</th>
                  <th>Contact</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Joined</th>
                  <th class="staff-actions-col"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($staff_list as $st): ?>
                  <?php
                    $full_name = staff_display_name($st);
                    $has_email = trim((string)($st['email'] ?? '')) !== '';
                    $joined_ts = $st['created_at'] ? strtotime($st['created_at']) : 0;
                  ?>
                  <tr class="<?= $st['is_active'] ? '' : 'is-inactive' ?>" data-status="<?= $st['is_active'] ? 'active' : 'inactive' ?>" data-department="<?= htmlspecialchars($st['department_name'] ?? '') ?>" data-search="<?= htmlspecialchars(staff_search_key($st)) ?>" data-name="<?= htmlspecialchars(mb_strtolower($full_name)) ?>" data-display-name="<?= htmlspecialchars($full_name) ?>" data-joined="<?= (int)$joined_ts ?>">
                    <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$st['user_id'] ?>" aria-label="Select <?= htmlspecialchars($full_name) ?>"></td>
                    <td class="td-name">
                      <div class="td-name-cell">
                        <span class="staff-table-avatar"><?= htmlspecialchars(staff_initials($st['given_name'] ?? '', $st['family_name'] ?? '')) ?></span>
                        <span class="staff-name-stack">
                          <span class="staff-name-primary"><?= htmlspecialchars($full_name) ?></span>
                          <span class="staff-name-secondary">@<?= htmlspecialchars($st['username']) ?></span>
                        </span>
                      </div>
                    </td>
                    <td>
                      <?php if ($has_email): ?>
                        <div class="staff-contact-line">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                          <span><?= htmlspecialchars($st['email']) ?></span>
                        </div>
                      <?php else: ?>
                        <div class="staff-contact-line staff-contact-line--warn">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
                          <span>No email on file</span>
                          <button type="button" class="staff-add-link" data-add-email>Add</button>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($st['contact_number'])): ?>
                        <div class="staff-contact-line">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                          <span><?= htmlspecialchars($st['contact_number']) ?></span>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($st['department_name']): ?>
                        <span class="staff-role-tag"><?= htmlspecialchars(ucfirst($st['department_name'])) ?></span>
                      <?php else: ?>
                        <span class="td-meta">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="staff-state <?= $st['is_active'] ? 'staff-state--active' : 'staff-state--inactive' ?>"><i></i><?= $st['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td class="staff-joined"><?= htmlspecialchars($st['created_at'] ? date('M j, Y', strtotime($st['created_at'])) : '—') ?></td>
                    <td class="staff-actions-col"><?= staff_row_menu($st, 'staff-row-menu') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <p class="empty-state hidden" data-no-match>No staff match your filters.</p>

            <div class="staff-table-footer">
              <span class="staff-footer-summary" id="staffSummary">Showing <?= count($staff_list) ?> of <?= count($staff_list) ?> staff</span>
              <div class="staff-pager" id="staffPager">
                <label class="staff-pagination-rows">
                  <select id="rowsPerPage" aria-label="Rows per page">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                  </select>
                  per page
                </label>
                <div class="staff-pagination-pages" id="paginationPages">
                  <button type="button" id="paginationPrev" class="staff-page-btn staff-page-arrow" aria-label="Previous page">&lsaquo;</button>
                  <span id="paginationNumbers" class="staff-pagination-numbers"></span>
                  <button type="button" id="paginationNext" class="staff-page-btn staff-page-arrow" aria-label="Next page">&rsaquo;</button>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Staff list — card view -->
        <div class="staff-view staff-grid-wrap hidden" id="staffViewGrid">
          <?php if (empty($staff_list)): ?>
            <p class="empty-state">No staff accounts yet.</p>
          <?php else: ?>
            <label class="staff-grid-selectall">
              <input type="checkbox" id="selectAllGrid" class="staff-row-checkbox-all"> Select all on this page
            </label>

            <div class="staff-grid">
              <?php foreach ($staff_list as $i => $st): ?>
                <?php
                  $full_name = staff_display_name($st);
                  $has_email = trim((string)($st['email'] ?? '')) !== '';
                  $joined_ts = $st['created_at'] ? strtotime($st['created_at']) : 0;
                ?>
                <div class="staff-card<?= $st['is_active'] ? '' : ' is-inactive' ?>" data-status="<?= $st['is_active'] ? 'active' : 'inactive' ?>" data-department="<?= htmlspecialchars($st['department_name'] ?? '') ?>" data-search="<?= htmlspecialchars(staff_search_key($st)) ?>" data-name="<?= htmlspecialchars(mb_strtolower($full_name)) ?>" data-display-name="<?= htmlspecialchars($full_name) ?>" data-joined="<?= (int)$joined_ts ?>">

                  <div class="staff-card-top">
                    <input type="checkbox" class="staff-row-checkbox staff-card-check" value="<?= (int)$st['user_id'] ?>" aria-label="Select <?= htmlspecialchars($full_name) ?>">
                    <span class="staff-card-avatar"><?= htmlspecialchars(staff_initials($st['given_name'] ?? '', $st['family_name'] ?? '')) ?></span>
                    <span class="staff-card-id">
                      <span class="staff-card-name"><?= htmlspecialchars($full_name) ?></span>
                      <span class="staff-card-user">@<?= htmlspecialchars($st['username']) ?></span>
                    </span>
                    <?= staff_row_menu($st, 'staff-card-menu-inline') ?>
                  </div>

                  <div class="staff-card-tags">
                    <?php if ($st['department_name']): ?>
                      <span class="staff-role-tag"><?= htmlspecialchars(ucfirst($st['department_name'])) ?></span>
                    <?php endif; ?>
                    <span class="staff-state <?= $st['is_active'] ? 'staff-state--active' : 'staff-state--inactive' ?>"><i></i><?= $st['is_active'] ? 'Active' : 'Inactive' ?></span>
                  </div>

                  <div class="staff-card-contact">
                    <?php if ($has_email): ?>
                      <div class="staff-card-contact-row">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <span title="<?= htmlspecialchars($st['email']) ?>"><?= str_replace('@', '<wbr>@', htmlspecialchars($st['email'])) ?></span>
                      </div>
                    <?php else: ?>
                      <div class="staff-card-contact-row staff-card-contact-row--warn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
                        <span>No email on file</span>
                        <button type="button" class="staff-add-link" data-add-email>Add</button>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($st['contact_number'])): ?>
                      <div class="staff-card-contact-row">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span><?= htmlspecialchars($st['contact_number']) ?></span>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="staff-card-foot">Joined <?= htmlspecialchars($st['created_at'] ? date('M j, Y', strtotime($st['created_at'])) : '—') ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="empty-state hidden" data-no-match>No staff match your filters.</p>

            <div class="staff-table-footer staff-table-footer--flush">
              <span class="staff-footer-summary" id="gridSummary">Showing <?= count($staff_list) ?> of <?= count($staff_list) ?> staff</span>
              <div class="staff-pager">
                <label class="staff-pagination-rows">
                  <select id="gridRowsPerPage" aria-label="Cards per page">
                    <option value="12">12</option>
                    <option value="24">24</option>
                    <option value="48">48</option>
                  </select>
                  per page
                </label>
                <div class="staff-pagination-pages" id="gridPages">
                  <button type="button" id="gridPrev" class="staff-page-btn staff-page-arrow" aria-label="Previous page">&lsaquo;</button>
                  <span id="gridNumbers" class="staff-pagination-numbers"></span>
                  <button type="button" id="gridNext" class="staff-page-btn staff-page-arrow" aria-label="Next page">&rsaquo;</button>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>

      </div>

    </div>
  </div>

  <form method="POST" action="staff_manage" id="bulkForm" class="hidden">
    <input type="hidden" name="bulk_status" id="bulkStatus" value="">
    <div id="bulkIds"></div>
  </form>

  <!-- Bulk deactivate confirmation -->
  <div class="modal-overlay staff-modal-overlay hidden" id="bulkDeactivateOverlay" role="dialog" aria-modal="true" aria-labelledby="bulkDeactTitle">
    <div class="modal-panel staff-modal staff-modal--confirm">
      <h2 id="bulkDeactTitle">Deactivate accounts?</h2>
      <p class="staff-confirm-lead" id="bulkDeactLead"></p>
      <div class="staff-confirm-warn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        <span id="bulkDeactWarn"></span>
      </div>
      <ul class="staff-confirm-list">
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>Their records and history are kept.</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>You can reactivate them at any time.</li>
      </ul>
      <div class="staff-modal-actions staff-modal-actions--plain">
        <button type="button" class="staff-btn staff-btn--ghost" data-close-modal>Cancel</button>
        <button type="button" class="staff-btn staff-btn--danger" id="bulkDeactConfirm">Deactivate</button>
      </div>
    </div>
  </div>

  <!-- Add staff modal -->
  <div class="modal-overlay staff-modal-overlay<?= $error ? '' : ' hidden' ?>" id="addStaffOverlay" role="dialog" aria-modal="true" aria-labelledby="addStaffTitle">
    <div class="modal-panel staff-modal">
      <div class="staff-modal-header">
        <div>
          <h2 id="addStaffTitle">Add staff</h2>
          <p class="staff-modal-sub">Create a portal login. A temporary password is generated and emailed to them.</p>
        </div>
        <button type="button" class="staff-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="staff_manage" data-confirm="Create this staff account?" data-icon="question">
        <div class="staff-form-grid">
          <div class="form-group">
            <label for="sf_given_name">First name <span class="req">*</span></label>
            <input id="sf_given_name" type="text" name="given_name" placeholder="e.g. Maria" required maxlength="50" autocomplete="off"
              data-rule="name" data-label="First name" data-min="2"
              value="<?= htmlspecialchars($_POST['given_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="sf_middle_name">Middle name</label>
            <input id="sf_middle_name" type="text" name="middle_name" placeholder="Optional" maxlength="50" autocomplete="off"
              data-rule="name" data-label="Middle name" data-min="1"
              value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="sf_family_name">Last name <span class="req">*</span></label>
            <input id="sf_family_name" type="text" name="family_name" placeholder="e.g. Dela Cruz" required maxlength="50" autocomplete="off"
              data-rule="name" data-label="Last name" data-min="2"
              value="<?= htmlspecialchars($_POST['family_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="sf_suffix">Suffix</label>
            <select id="sf_suffix" name="suffix">
              <option value="">None</option>
              <?php foreach (STAFF_SUFFIXES as $sfx): ?>
                <option value="<?= htmlspecialchars($sfx) ?>" <?= ($_POST['suffix'] ?? '') === $sfx ? 'selected' : '' ?>><?= htmlspecialchars($sfx) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group form-group--wide">
            <label for="sf_email">Email address <span class="req">*</span></label>
            <input id="sf_email" type="email" name="email" placeholder="name@example.com" required maxlength="100" autocomplete="off"
              data-rule="email" data-label="Email address"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="sf_contact">Contact number</label>
            <input id="sf_contact" type="tel" name="contact_number" placeholder="09171234567" inputmode="numeric" maxlength="11" autocomplete="off"
              data-rule="phone" data-label="Contact number"
              value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
            <p class="staff-field-hint"></p>
          </div>
          <div class="form-group">
            <label for="sf_role">Role <span class="req">*</span></label>
            <select id="sf_role" name="role" required data-label="Role">
              <option value="" disabled <?= empty($_POST['role']) ? 'selected' : '' ?>>Select a role</option>
              <?php foreach ($staff_roles as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= ($_POST['role'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="staff-modal-actions">
          <button type="button" class="staff-btn staff-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="add_staff" class="staff-btn staff-btn--primary">Create account</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit staff modal — reopens itself with the submitted values when
       edit_staff validation fails, same as the Add staff modal above. -->
  <div class="modal-overlay staff-modal-overlay<?= $edit_error ? '' : ' hidden' ?>" id="editStaffOverlay" role="dialog" aria-modal="true" aria-labelledby="editStaffTitle">
    <div class="modal-panel staff-modal">
      <div class="staff-modal-header">
        <div>
          <h2 id="editStaffTitle">Edit details</h2>
          <p class="staff-modal-sub">Username: <strong id="es_username"><?php
            if ($edit_error) {
                foreach ($staff_list as $row) {
                    if ((int)$row['user_id'] === (int)($_POST['user_id'] ?? 0)) { echo htmlspecialchars($row['username']); break; }
                }
            }
          ?></strong> (can't be changed)</p>
        </div>
        <button type="button" class="staff-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($edit_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($edit_error) ?></div>
      <?php endif; ?>

      <form method="POST" action="staff_manage" data-confirm="Save changes to this staff account?" data-icon="question">
        <input type="hidden" name="user_id" id="es_user_id" value="<?= (int)($_POST['user_id'] ?? 0) ?>">
        <div class="staff-form-grid">
          <div class="form-group">
            <label for="es_given_name">First name <span class="req">*</span></label>
            <input id="es_given_name" type="text" name="given_name" required maxlength="50" autocomplete="off"
              data-rule="name" data-label="First name" data-min="2"
              value="<?= htmlspecialchars($_POST['given_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="es_middle_name">Middle name</label>
            <input id="es_middle_name" type="text" name="middle_name" maxlength="50" autocomplete="off"
              data-rule="name" data-label="Middle name" data-min="1"
              value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="es_family_name">Last name <span class="req">*</span></label>
            <input id="es_family_name" type="text" name="family_name" required maxlength="50" autocomplete="off"
              data-rule="name" data-label="Last name" data-min="2"
              value="<?= htmlspecialchars($_POST['family_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="es_suffix">Suffix</label>
            <select id="es_suffix" name="suffix">
              <option value="">None</option>
              <?php foreach (STAFF_SUFFIXES as $sfx): ?>
                <option value="<?= htmlspecialchars($sfx) ?>" <?= ($_POST['suffix'] ?? '') === $sfx ? 'selected' : '' ?>><?= htmlspecialchars($sfx) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group form-group--wide">
            <label for="es_email">Email address <span class="req">*</span></label>
            <input id="es_email" type="email" name="email" required maxlength="100" autocomplete="off"
              data-rule="email" data-label="Email address"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="es_contact">Contact number</label>
            <input id="es_contact" type="tel" name="contact_number" inputmode="numeric" maxlength="11" autocomplete="off"
              data-rule="phone" data-label="Contact number"
              value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
            <p class="staff-field-hint">11 digits, starts with 09</p>
          </div>
          <div class="form-group">
            <label for="es_role">Role <span class="req">*</span></label>
            <select id="es_role" name="role" required data-label="Role">
              <?php foreach ($staff_roles as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= ($_POST['role'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="staff-modal-actions">
          <button type="button" class="staff-btn staff-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="edit_staff" class="staff-btn staff-btn--primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // ── View toggle ──────────────────────────────────────────────────────
      var toggleBtns = document.querySelectorAll('.view-toggle-btn');
      var viewTable  = document.getElementById('staffViewTable');
      var viewGrid   = document.getElementById('staffViewGrid');
      var currentView = 'table';

      toggleBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          toggleBtns.forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
          btn.classList.add('active');
          btn.setAttribute('aria-pressed', 'true');
          currentView = btn.dataset.view === 'grid' ? 'grid' : 'table';
          viewGrid.classList.toggle('hidden', currentView !== 'grid');
          viewTable.classList.toggle('hidden', currentView === 'grid');
          // Each view pages independently, so a selection can't carry across.
          clearSelection();
        });
      });

      // ── Filters, status pills and sort (client-side — one set of filters
      // drives both the table rows and the grid cards) ─────────────────────
      var deptSel     = document.getElementById('filterDepartment');
      var sortSel     = document.getElementById('sortStaff');
      var searchInput = document.getElementById('staffSearchInput');
      var pills       = Array.prototype.slice.call(document.querySelectorAll('.staff-pill'));
      var noMatchEls  = document.querySelectorAll('[data-no-match]');
      var activeStatus = '';

      // Both views paginate on top of filtering: filtering records which items
      // match (a plain JS property, not the .hidden class) and the paginators
      // below decide final visibility.
      var tableRows = Array.prototype.slice.call(document.querySelectorAll('#staffViewTable tbody tr[data-status]'));
      var gridCards = Array.prototype.slice.call(document.querySelectorAll('#staffViewGrid .staff-card'));

      function matchesFilters(el, status, dept, search) {
        var matchesStatus = !status || el.dataset.status === status;
        var matchesDept   = !dept   || el.dataset.department === dept;
        var matchesSearch = !search || el.dataset.search.indexOf(search) !== -1;
        return matchesStatus && matchesDept && matchesSearch;
      }

      function sortItems() {
        var parts = sortSel.value.split('-');
        var dir = parts[1] === 'asc' ? 1 : -1;
        function cmp(a, b) {
          if (parts[0] === 'joined') {
            var d = (Number(a.dataset.joined) - Number(b.dataset.joined)) * dir;
            if (d) return d;
          }
          return a.dataset.name.localeCompare(b.dataset.name) * (parts[0] === 'name' ? dir : 1);
        }
        tableRows.sort(cmp);
        gridCards.sort(cmp);
        tableRows.forEach(function (r) { r.parentNode.appendChild(r); });
        gridCards.forEach(function (c) { c.parentNode.appendChild(c); });
      }

      function applyFilters() {
        var dept   = deptSel.value;
        var search = searchInput ? searchInput.value.trim().toLowerCase() : '';
        // Pill counts follow the search + role filters, so they always say
        // how many rows each pill would show right now.
        var counts = { all: 0, active: 0, inactive: 0 };

        tableRows.forEach(function (r) {
          var base = matchesFilters(r, '', dept, search);
          if (base) { counts.all++; counts[r.dataset.status]++; }
          r._filterMatch = base && (!activeStatus || r.dataset.status === activeStatus);
        });
        gridCards.forEach(function (c) {
          c._filterMatch = matchesFilters(c, activeStatus, dept, search);
        });

        pills.forEach(function (pill) {
          var key = pill.dataset.filterStatus || 'all';
          pill.querySelector('[data-count]').textContent = counts[key];
        });
        var anyMatch = tableRows.some(function (r) { return r._filterMatch; });
        noMatchEls.forEach(function (el) { el.classList.toggle('hidden', anyMatch); });

        tablePager.reset();
        gridPager.reset();
      }

      pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
          activeStatus = pill.dataset.filterStatus;
          pills.forEach(function (p) {
            var on = p === pill;
            p.classList.toggle('active', on);
            p.setAttribute('aria-pressed', on ? 'true' : 'false');
          });
          applyFilters();
        });
      });
      deptSel.addEventListener('change', applyFilters);
      sortSel.addEventListener('change', function () { sortItems(); applyFilters(); });
      if (searchInput) searchInput.addEventListener('input', applyFilters);

      // ── Pagination (one instance per view) ───────────────────────────────
      // Builds the "1 2 3 … 38 39 40"-style page list: always show the
      // first/last 3 pages plus the current page's immediate neighbors,
      // collapsing any gap between those groups into a single ellipsis.
      function buildPageList(current, total) {
        var boundary = 3, sibling = 1;
        var pages = {};
        for (var i = 1; i <= Math.min(boundary, total); i++) pages[i] = true;
        for (var i = Math.max(1, total - boundary + 1); i <= total; i++) pages[i] = true;
        for (var i = Math.max(1, current - sibling); i <= Math.min(total, current + sibling); i++) pages[i] = true;

        var sorted = Object.keys(pages).map(Number).sort(function (a, b) { return a - b; });
        var result = [];
        var prev = 0;
        sorted.forEach(function (p) {
          if (prev && p - prev > 1) result.push('ellipsis');
          result.push(p);
          prev = p;
        });
        return result;
      }

      // cfg: { items: fn -> list, size, summary, pages, numbers, prev, next (element ids), smallest }
      function makePaginator(cfg) {
        var sizeSel = document.getElementById(cfg.size);
        if (!sizeSel) return { reset: function () {} };   // no staff yet → nothing rendered
        var summaryEl = document.getElementById(cfg.summary);
        var pagesEl   = document.getElementById(cfg.pages);
        var numbersEl = document.getElementById(cfg.numbers);
        var prevBtn   = document.getElementById(cfg.prev);
        var nextBtn   = document.getElementById(cfg.next);
        var sizeLabel = sizeSel.closest('.staff-pagination-rows');
        var page = 1;

        function render() {
          var items = cfg.items();
          var per = parseInt(sizeSel.value, 10);
          var matched = items.filter(function (i) { return i._filterMatch !== false; });
          var total = matched.length;
          var totalPages = Math.max(1, Math.ceil(total / per));
          if (page > totalPages) page = totalPages;

          var start = (page - 1) * per;
          var end = start + per;
          matched.forEach(function (it, i) { it.classList.toggle('hidden', !(i >= start && i < end)); });
          items.forEach(function (it) { if (it._filterMatch === false) it.classList.add('hidden'); });

          if (total === 0) {
            summaryEl.textContent = 'No staff to show';
          } else if (totalPages === 1) {
            summaryEl.textContent = 'Showing ' + total + ' of ' + items.length + ' staff';
          } else {
            summaryEl.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total + ' staff';
          }
          // Nothing to page through (or choose a page size for) until there's more than one page's worth.
          sizeLabel.classList.toggle('hidden', items.length <= cfg.smallest);
          pagesEl.classList.toggle('hidden', totalPages <= 1);

          numbersEl.innerHTML = '';
          buildPageList(page, totalPages).forEach(function (item) {
            if (item === 'ellipsis') {
              var span = document.createElement('span');
              span.className = 'staff-page-ellipsis';
              span.textContent = '…';
              numbersEl.appendChild(span);
              return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'staff-page-btn' + (item === page ? ' active' : '');
            btn.textContent = item;
            btn.addEventListener('click', function () { page = item; render(); });
            numbersEl.appendChild(btn);
          });
          prevBtn.disabled = page <= 1;
          nextBtn.disabled = page >= totalPages;
          syncSelection();
        }

        sizeSel.addEventListener('change', function () { page = 1; render(); });
        prevBtn.addEventListener('click', function () { if (page > 1) { page--; render(); } });
        nextBtn.addEventListener('click', function () { page++; render(); });
        return { reset: function () { page = 1; render(); } };
      }

      var tablePager = makePaginator({ items: function () { return tableRows; }, size: 'rowsPerPage', summary: 'staffSummary',
        pages: 'paginationPages', numbers: 'paginationNumbers', prev: 'paginationPrev', next: 'paginationNext', smallest: 10 });
      var gridPager  = makePaginator({ items: function () { return gridCards; }, size: 'gridRowsPerPage', summary: 'gridSummary',
        pages: 'gridPages', numbers: 'gridNumbers', prev: 'gridPrev', next: 'gridNext', smallest: 12 });

      // ── Selection + bulk actions (works in both views) ───────────────────
      // Header/“Select all” checkboxes select the items on the current page.
      // Items that drop out of view (filter, sort, page or view change) are
      // unchecked, so "n selected" only ever covers what the admin can see.
      // The header Export CSV button always exports everyone; "Export selected"
      // in the bar exports just the checked ones.
      var selectAllStaff = document.getElementById('selectAllStaff');
      var selectAllGrid  = document.getElementById('selectAllGrid');
      var exportBtn      = document.getElementById('btnExportCsv');
      var bulkBar        = document.getElementById('staffBulkBar');
      var bulkCount      = document.getElementById('bulkCount');
      var bulkDeactivate = document.getElementById('bulkDeactivate');
      var bulkActivate   = document.getElementById('bulkActivate');
      var bulkOverlay    = document.getElementById('bulkDeactivateOverlay');
      var bulkForm       = document.getElementById('bulkForm');

      function viewItems() { return currentView === 'grid' ? gridCards : tableRows; }
      function checkboxOf(item) { return item.querySelector('.staff-row-checkbox'); }
      function isShown(item) { return !item.classList.contains('hidden'); }
      function selectedItems() { return viewItems().filter(function (i) { return checkboxOf(i).checked; }); }

      function updateSelection() {
        var sel = selectedItems();
        var n = sel.length;
        var activeCount = sel.filter(function (i) { return i.dataset.status === 'active'; }).length;

        if (bulkBar) {
          bulkBar.classList.toggle('hidden', n === 0);
          bulkCount.textContent = n + ' selected';
          // Deactivate only applies to active accounts, Activate to inactive ones.
          bulkDeactivate.disabled = activeCount === 0;
          bulkActivate.disabled   = n - activeCount === 0;
        }
        tableRows.concat(gridCards).forEach(function (i) { i.classList.toggle('is-selected', checkboxOf(i).checked); });

        [[selectAllStaff, tableRows], [selectAllGrid, gridCards]].forEach(function (pair) {
          var box = pair[0];
          if (!box) return;
          var visible = pair[1].filter(isShown);
          var checked = visible.filter(function (i) { return checkboxOf(i).checked; }).length;
          box.checked = visible.length > 0 && checked === visible.length;
          box.indeterminate = checked > 0 && checked < visible.length;
        });
      }

      // Called after every filter/sort/page change (see makePaginator()).
      function syncSelection() {
        tableRows.concat(gridCards).forEach(function (i) { if (!isShown(i)) checkboxOf(i).checked = false; });
        updateSelection();
      }
      function clearSelection() {
        tableRows.concat(gridCards).forEach(function (i) { checkboxOf(i).checked = false; });
        updateSelection();
      }

      tableRows.concat(gridCards).forEach(function (i) { checkboxOf(i).addEventListener('change', updateSelection); });
      [[selectAllStaff, tableRows], [selectAllGrid, gridCards]].forEach(function (pair) {
        if (!pair[0]) return;
        pair[0].addEventListener('change', function () {
          pair[1].forEach(function (i) { if (isShown(i)) checkboxOf(i).checked = pair[0].checked; });
          updateSelection();
        });
      });

      function exportCsv(ids) {
        var url = 'staff_manage?export=csv';
        if (ids && ids.length) url += '&ids=' + ids.join(',');
        window.location.href = url;
      }
      if (exportBtn) exportBtn.addEventListener('click', function () { exportCsv(); });

      if (bulkBar) {
        document.getElementById('bulkClear').addEventListener('click', clearSelection);
        document.getElementById('bulkExport').addEventListener('click', function () {
          exportCsv(selectedItems().map(function (i) { return checkboxOf(i).value; }));
        });

        function submitBulk(action, items) {
          document.getElementById('bulkStatus').value = action;
          var box = document.getElementById('bulkIds');
          box.innerHTML = '';
          items.forEach(function (i) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'user_ids[]';
            input.value = checkboxOf(i).value;
            box.appendChild(input);
          });
          var loader = document.getElementById('pageLoader');
          if (loader) loader.classList.add('show');
          bulkForm.submit();
        }

        // Activate: no confirmation — it only restores access; the result shows as a toast.
        bulkActivate.addEventListener('click', function () {
          var items = selectedItems().filter(function (i) { return i.dataset.status === 'inactive'; });
          if (items.length) submitBulk('activate', items);
        });

        // Deactivate: cuts off sign-in, so confirm first and say exactly what happens.
        function joinNames(names) {
          var n = names.length;
          if (n === 1) return names[0];
          if (n <= 3) return names.slice(0, -1).join(', ') + ' and ' + names[n - 1];
          return names.slice(0, 3).join(', ') + ' and ' + (n - 3) + ' more';
        }
        var pendingDeactivate = [];
        bulkDeactivate.addEventListener('click', function () {
          pendingDeactivate = selectedItems().filter(function (i) { return i.dataset.status === 'active'; });
          var n = pendingDeactivate.length;
          if (!n) return;
          var names = pendingDeactivate.map(function (i) { return i.dataset.displayName; });
          var noun = n === 1 ? 'account' : 'accounts';
          document.getElementById('bulkDeactTitle').textContent = 'Deactivate ' + n + ' ' + noun + '?';
          document.getElementById('bulkDeactLead').textContent = joinNames(names) + ' will lose access right away.';
          document.getElementById('bulkDeactWarn').textContent = (n === 1 ? 'This account won’t' : 'These accounts won’t') + ' be able to sign in to the portal.';
          document.getElementById('bulkDeactConfirm').textContent = 'Deactivate ' + n + ' ' + noun;
          bulkOverlay.classList.remove('hidden');
          document.getElementById('bulkDeactConfirm').focus();
        });
        document.getElementById('bulkDeactConfirm').addEventListener('click', function () {
          bulkOverlay.classList.add('hidden');
          submitBulk('deactivate', pendingDeactivate);
        });
      }

      sortItems();
      applyFilters();

      // ── Add / Edit form validation ───────────────────────────────────────
      // Mirrors staff_validate() on the server: names take letters, spaces,
      // hyphens, apostrophes and periods only (no digits or symbols); contact
      // is 11 digits starting with 09. Disallowed characters are stripped as
      // they are typed or pasted; messages appear under the field.
      var NAME_RE = /^\p{L}[\p{L} .'\-]*$/u;

      function fieldMessage(el) {
        var v = el.value.trim();
        var label = el.dataset.label || 'This field';
        if (!v) return el.required ? label + ' is required.' : '';
        var rule = el.dataset.rule;
        if (rule === 'name') {
          var min = parseInt(el.dataset.min || '2', 10);
          if (v.length < min) return label + ' must be at least ' + min + ' character' + (min > 1 ? 's' : '') + '.';
          if (!NAME_RE.test(v)) return label + ' can only contain letters, spaces, hyphens, apostrophes and periods.';
        } else if (rule === 'phone') {
          if (!/^09\d{9}$/.test(v)) return 'Enter 11 digits starting with 09 (e.g. 09171234567).';
        } else if (rule === 'email') {
          el.setCustomValidity('');
          if (el.validity.typeMismatch) return 'Enter a valid email address.';
        }
        return '';
      }

      function errorEl(el) {
        var group = el.closest('.form-group');
        var p = group.querySelector('.field-error');
        if (!p) {
          p = document.createElement('p');
          p.className = 'field-error';
          group.appendChild(p);
        }
        return p;
      }

      function showFieldState(el) {
        var msg = fieldMessage(el);
        el.setCustomValidity(msg);
        el.classList.toggle('is-invalid', !!msg);
        var group = el.closest('.form-group');
        var p = group.querySelector('.field-error');
        if (msg) { errorEl(el).textContent = msg; } else if (p) { p.remove(); }
        return msg;
      }

      function clearFieldErrors(scope) {
        scope.querySelectorAll('.field-error').forEach(function (p) { p.remove(); });
        scope.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); el.setCustomValidity(''); });
      }

      function sanitize(el) {
        var rule = el.dataset.rule;
        if (rule === 'name') el.value = el.value.replace(/[^\p{L} .'\-]/gu, '').replace(/^[^\p{L}]+/u, '');
        if (rule === 'phone') el.value = el.value.replace(/\D/g, '').slice(0, 11);
      }

      document.querySelectorAll('.staff-modal form').forEach(function (form) {
        form.querySelectorAll('[data-rule], select[required]').forEach(function (el) {
          el.addEventListener('input', function () { sanitize(el); if (el.classList.contains('is-invalid')) showFieldState(el); });
          el.addEventListener('change', function () { if (el.classList.contains('is-invalid')) showFieldState(el); });
          el.addEventListener('blur', function () { if (el.value.trim() !== '' || el.classList.contains('is-invalid')) showFieldState(el); });
        });
        // Runs before the browser would submit: mark every bad field, focus the first.
        form.addEventListener('submit', function (e) {
          var first = null;
          form.querySelectorAll('[data-rule], select[required]').forEach(function (el) {
            if (showFieldState(el) && !first) first = el;
          });
          if (first) { e.preventDefault(); e.stopImmediatePropagation(); first.focus(); }
        }, true);
      });

      // ── Add staff modal (closing is handled with the other modals below) ──
      var overlay = document.getElementById('addStaffOverlay');
      var openBtn = document.getElementById('btnOpenAddStaff');
      openBtn.addEventListener('click', function () {
        overlay.classList.remove('hidden');
        document.getElementById('sf_given_name').focus();
      });

      // ── Row / card overflow menu (View, Edit, Reset Password, Activate/Deactivate) ──
      function closeAllCardMenus() {
        document.querySelectorAll('.staff-card-menu.open').forEach(function (m) {
          m.classList.remove('open');
          m.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', 'false');
        });
      }

      // ── Edit modal, filled from the menu wrapper's data-* ────────────────
      var editOverlay = document.getElementById('editStaffOverlay');
      var roleLabels = <?= json_encode($staff_roles) ?>;

      function openEditModal(d) {
        document.getElementById('es_user_id').value     = d.userId;
        document.getElementById('es_username').textContent = d.username;
        document.getElementById('es_family_name').value = d.family;
        document.getElementById('es_given_name').value  = d.given;
        document.getElementById('es_middle_name').value = d.middle;
        document.getElementById('es_suffix').value      = d.suffix;
        document.getElementById('es_contact').value     = d.contact;
        document.getElementById('es_email').value       = d.email;
        document.getElementById('es_role').value        = d.dept;
        var err = editOverlay.querySelector('.alert-error');
        if (err) err.remove();
        clearFieldErrors(editOverlay);
        editOverlay.classList.remove('hidden');
      }

      // "Add" beside a missing email opens Edit staff on the email field.
      document.querySelectorAll('[data-add-email]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          openEditModal(btn.closest('tr, .staff-card').querySelector('.staff-card-menu').dataset);
          document.getElementById('es_email').focus();
        });
      });

      [overlay, editOverlay, bulkOverlay].forEach(function (ov) {
        ov.addEventListener('click', function (e) {
          if (e.target === ov || e.target.closest('[data-close-modal]')) ov.classList.add('hidden');
        });
      });
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        overlay.classList.add('hidden');
        editOverlay.classList.add('hidden');
        bulkOverlay.classList.add('hidden');
      });

      document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-menu-toggle]');
        if (toggle) {
          var menu = toggle.closest('.staff-card-menu');
          var wasOpen = menu.classList.contains('open');
          closeAllCardMenus();
          if (!wasOpen) {
            // Open upward when the dropdown wouldn't fit below the button
            // (last rows of a page / cards near the bottom of the viewport).
            var dropdown = menu.querySelector('.staff-card-menu-dropdown');
            var room = window.innerHeight - toggle.getBoundingClientRect().bottom;
            menu.classList.toggle('flip-up', room < dropdown.offsetHeight + 16);
            menu.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
          }
          return;
        }

        var actionBtn = e.target.closest('[data-action]');
        if (actionBtn) {
          var wrapper = actionBtn.closest('.staff-card-menu');
          closeAllCardMenus();
            if (actionBtn.dataset.action === 'edit') openEditModal(wrapper.dataset);
          return;
        }

        if (!e.target.closest('.staff-card-menu')) closeAllCardMenus();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllCardMenus();
      });
    });
  </script>

  <?php if ($new_credentials): ?>
    <div class="modal-overlay" id="credOverlay">
      <div class="modal-panel cred-modal">
        <h2><?= htmlspecialchars($cred_title) ?></h2>

        <?php if ($new_credentials['mail_sent']): ?>
          <p class="cred-sub">Credentials have also been emailed to <?= htmlspecialchars($new_credentials['name']) ?>.</p>
        <?php else: ?>
          <div class="alert alert-warning">
            <strong>Email delivery failed.</strong> These credentials were not sent to <?= htmlspecialchars($new_credentials['name']) ?> — copy them now and share them directly.
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
