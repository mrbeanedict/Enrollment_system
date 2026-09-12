<?php
session_start();
include_once '../config.php';
require_once '../config/mail.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login"); exit();
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
    $parts = [$st['given_name'] ?? '', $st['family_name'] ?? '', $st['username'] ?? '', $st['email'] ?? ''];
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

// Guards against silently minting a second account for the same person —
// e.g. a re-click during the synchronous email send below, or a re-submit
// of the Add Staff form after a successful create (see $form_values below).
function staff_email_exists(mysqli $conn, string $email): bool {
    $stmt = $conn->prepare("SELECT 1 FROM staff WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

$success          = '';
$error            = '';
$new_credentials  = null; // set only right after a successful create, for the one-time reveal

// ── Add staff POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $family_name = trim($_POST['family_name'] ?? '');
    $given_name  = trim($_POST['given_name'] ?? '');
    $contact     = trim($_POST['contact_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $role_key    = $_POST['role'] ?? '';

    if (!$family_name || !$given_name || !$email) {
        $error = 'First name, last name, and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!array_key_exists($role_key, $staff_roles)) {
        $error = 'Please select a valid role.';
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

            $s = $conn->prepare("INSERT INTO staff (user_id, department_id, family_name, given_name, contact_number, email) VALUES (?, ?, ?, ?, ?, ?)");
            $s->bind_param('iissss', $uid, $department_id, $family_name, $given_name, $contact, $email);
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

// ── Fetch staff list ───────────────────────────────────────────────────────
$sql = "
    SELECT u.user_id, u.username, u.is_active, u.created_at,
           st.family_name, st.given_name, st.contact_number, st.email,
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
            trim(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? '')),
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
  <link rel="stylesheet" href="../assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_admin.css') ?>">
</head>
<body>

  <?php include_once 'sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right">
        <span class="topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Staff Directory</span></p>
        <h1 class="page-title">Staff Management</h1>
        <p class="staff-hero-desc">Oversee staff credentials, departmental assignments, and authentication states across the senior high system.</p>
      </div>

      <div class="staff-overlap">

      <!-- Filters + actions — same strip pattern as admin/enrollments.php's
           .filter-bar (search + selects on the left) combined with
           teacher_accounts.php's .card-header-actions placement for
           Export CSV / Add Staff (top-right, beside the view toggle).
           Kept OUTSIDE either #staffViewTable/#staffViewGrid card because
           these controls have to stay visible and functional across both
           views — nesting them inside one view's card would hide them,
           along with the only way back, the moment the other view is
           active. -->
      <div class="card staff-list-controls">
        <div class="staff-toolbar-filters">
          <label for="staffSearchInput" class="sr-only">Search</label>
          <input type="search" id="staffSearchInput" placeholder="Search name, username, or email…">
          <select class="staff-filter-select" id="filterStatus" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <select class="staff-filter-select" id="filterDepartment" aria-label="Filter by department">
            <option value="">All Departments</option>
            <?php foreach ($staff_roles as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="staff-toolbar-actions">
          <div class="view-toggle" role="group" aria-label="Switch view">
            <button type="button" class="view-toggle-btn active" data-view="table" aria-label="Table view">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10"/></svg>
            </button>
            <button type="button" class="view-toggle-btn" data-view="grid" aria-label="Grid view">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
            </button>
          </div>
          <button type="button" class="btn-export-csv" id="btnExportCsv">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            <span id="btnExportCsvLabel">Export CSV</span>
          </button>
          <button type="button" class="btn-add-staff" id="btnOpenAddStaff">+ Add Staff</button>
        </div>
      </div>

      <!-- Staff list — table view -->
      <div class="card staff-view" id="staffViewTable">
        <div class="card-header">
          <span class="card-title">Staff Accounts Directory <span class="staff-record-count">(<?= count($staff_list) ?> records)</span></span>
        </div>

        <?php if (empty($staff_list)): ?>
          <p class="empty-state">No staff accounts yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th class="staff-table-checkbox-col"><input type="checkbox" id="selectAllStaff" aria-label="Select all"></th>
                <th>Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Contact Number</th>
                <th>Role</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($staff_list as $st): ?>
                <?php $dept_color = staff_department_color($st['department_name'] ?? null); ?>
                <tr data-status="<?= $st['is_active'] ? 'active' : 'inactive' ?>" data-department="<?= htmlspecialchars($st['department_name'] ?? '') ?>" data-search="<?= htmlspecialchars(staff_search_key($st)) ?>">
                  <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$st['user_id'] ?>" aria-label="Select row"></td>
                  <td class="td-name">
                    <div class="td-name-cell">
                      <span class="staff-table-avatar" style="background:<?= $dept_color ?>;color:#ffffff;"><?= htmlspecialchars(staff_initials($st['given_name'] ?? '', $st['family_name'] ?? '')) ?></span>
                      <?= htmlspecialchars(trim(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? ''))) ?>
                    </div>
                  </td>
                  <td class="td-meta"><?= htmlspecialchars($st['username']) ?></td>
                  <td class="td-meta"><?= htmlspecialchars($st['email'] ?? '—') ?></td>
                  <td class="td-meta"><?= htmlspecialchars($st['contact_number'] ?: '—') ?></td>
                  <td>
                    <?php if ($st['department_name']): ?>
                      <span class="role-chip"><span class="role-chip-dot" style="background:<?= $dept_color ?>;"></span><?= htmlspecialchars(ucfirst($st['department_name'])) ?></span>
                    <?php else: ?>
                      <span class="td-meta">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($st['is_active']): ?>
                      <span class="badge badge-enrolled">Active</span>
                    <?php else: ?>
                      <span class="badge badge-cancelled">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="POST" action="staff_manage" style="display:inline;"
                          data-confirm="<?= $st['is_active'] ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? '')) ?>?">
                      <input type="hidden" name="user_id"   value="<?= (int)$st['user_id'] ?>">
                      <input type="hidden" name="is_active" value="<?= (int)$st['is_active'] ?>">
                      <button type="submit" name="toggle_active" class="btn-toggle">
                        <?= $st['is_active'] ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="staff-table-pagination">
            <label class="staff-pagination-rows">
              <select id="rowsPerPage">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
              Results per page
            </label>

            <div class="staff-pagination-pages" id="paginationPages">
              <button type="button" id="paginationPrev" class="staff-page-btn staff-page-arrow" aria-label="Previous page">&lsaquo;</button>
              <span id="paginationNumbers" class="staff-pagination-numbers"></span>
              <button type="button" id="paginationNext" class="staff-page-btn staff-page-arrow" aria-label="Next page">&rsaquo;</button>
            </div>

            <span class="staff-pagination-summary"><span id="paginationFrom">1</span>-<span id="paginationTo">1</span> / <span id="paginationTotal"><?= count($staff_list) ?></span> results</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- Staff list — grid view -->
      <div class="card staff-view hidden" id="staffViewGrid">
        <div class="card-header">
          <span class="card-title">Staff Accounts (<?= count($staff_list) ?>)</span>
        </div>

        <?php if (empty($staff_list)): ?>
          <p class="empty-state">No staff accounts yet.</p>
        <?php else: ?>
          <div class="staff-grid">
            <?php foreach ($staff_list as $i => $st): ?>
              <?php $dept_color = staff_department_color($st['department_name'] ?? null); ?>
              <div class="staff-card" style="--staff-card-i: <?= min($i, 12) ?>" data-status="<?= $st['is_active'] ? 'active' : 'inactive' ?>" data-department="<?= htmlspecialchars($st['department_name'] ?? '') ?>" data-search="<?= htmlspecialchars(staff_search_key($st)) ?>">

                <div class="staff-card-menu">
                  <button type="button" class="staff-card-menu-btn" aria-label="More actions" aria-haspopup="true" aria-expanded="false" data-menu-toggle>
                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                  </button>
                  <div class="staff-card-menu-dropdown">
                    <form method="POST" action="staff_manage"
                          data-confirm="<?= $st['is_active'] ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? '')) ?>?">
                      <input type="hidden" name="user_id"   value="<?= (int)$st['user_id'] ?>">
                      <input type="hidden" name="is_active" value="<?= (int)$st['is_active'] ?>">
                      <button type="submit" name="toggle_active" class="staff-card-menu-item<?= $st['is_active'] ? ' staff-card-menu-item-danger' : '' ?>">
                        <?= $st['is_active'] ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  </div>
                </div>

                <div class="staff-card-profile">
                  <div class="staff-card-avatar" style="background:<?= $dept_color ?>;"><?= htmlspecialchars(staff_initials($st['given_name'] ?? '', $st['family_name'] ?? '')) ?></div>
                  <span class="staff-card-name"><?= htmlspecialchars(trim(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? ''))) ?></span>
                  <span class="staff-card-role"><?= htmlspecialchars($st['department_name'] ? ucfirst($st['department_name']) : '—') ?></span>
                  <?php if ($st['is_active']): ?>
                    <span class="staff-status staff-status-active">Active</span>
                  <?php else: ?>
                    <span class="staff-status staff-status-inactive">Inactive</span>
                  <?php endif; ?>
                </div>

                <div class="staff-card-contact">
                  <div class="staff-card-contact-row">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5.5A2.5 2.5 0 0 1 4.5 3h15A2.5 2.5 0 0 1 22 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-15A2.5 2.5 0 0 1 2 18.5v-13z"/><path d="m3 6 9 7 9-7"/></svg>
                    <span><?= htmlspecialchars($st['email'] ?? '—') ?></span>
                  </div>
                  <div class="staff-card-contact-row">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span><?= htmlspecialchars($st['contact_number'] ?: '—') ?></span>
                  </div>
                </div>

                <div class="staff-card-info">
                  <div class="staff-card-info-col">
                    <span class="staff-card-info-label">Department</span>
                    <span class="dept-tag" style="color:<?= $dept_color ?>;"><span class="role-chip-dot" style="background:<?= $dept_color ?>;"></span><?= htmlspecialchars($st['department_name'] ? ucfirst($st['department_name']) : '—') ?></span>
                  </div>
                  <div class="staff-card-info-col">
                    <span class="staff-card-info-label">Date of Joining</span>
                    <span class="staff-card-info-value"><?= htmlspecialchars($st['created_at'] ? date('M j, Y', strtotime($st['created_at'])) : '—') ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <!-- Add Staff modal -->
  <div class="modal-overlay staff-modal-overlay<?= $error ? '' : ' hidden' ?>" id="addStaffOverlay">
    <div class="modal-panel staff-modal">
      <div class="staff-modal-header">
        <h2>Add Staff Account</h2>
        <button type="button" class="staff-modal-close" id="btnCloseAddStaff" aria-label="Close">&times;</button>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="staff_manage" data-confirm="Create this staff account?" data-icon="question">
        <div class="form-group">
          <label for="sf_family_name">Last Name <span style="color:#C0392B">*</span></label>
          <input id="sf_family_name" type="text" name="family_name" placeholder="e.g. Dela Cruz" required
            value="<?= htmlspecialchars($_POST['family_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="sf_given_name">First Name <span style="color:#C0392B">*</span></label>
          <input id="sf_given_name" type="text" name="given_name" placeholder="e.g. Maria" required
            value="<?= htmlspecialchars($_POST['given_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="sf_contact">Contact Number</label>
          <input id="sf_contact" type="text" name="contact_number" placeholder="e.g. 09xx-xxx-xxxx"
            value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="sf_email">Email Address <span style="color:#C0392B">*</span></label>
          <input id="sf_email" type="email" name="email" placeholder="name@example.com" required
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="sf_role">Role <span style="color:#C0392B">*</span></label>
          <select id="sf_role" name="role" required>
            <option value="" disabled <?= empty($_POST['role']) ? 'selected' : '' ?>>Select a role</option>
            <?php foreach ($staff_roles as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= ($_POST['role'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="add_staff" class="btn-add">Create Staff Account</button>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // ── View toggle ──────────────────────────────────────────────────────
      var toggleBtns = document.querySelectorAll('.view-toggle-btn');
      var viewTable  = document.getElementById('staffViewTable');
      var viewGrid   = document.getElementById('staffViewGrid');

      toggleBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          toggleBtns.forEach(function (b) { b.classList.remove('active'); });
          btn.classList.add('active');
          var isGrid = btn.dataset.view === 'grid';
          viewGrid.classList.toggle('hidden', !isGrid);
          viewTable.classList.toggle('hidden', isGrid);
        });
      });

      // ── Filters (client-side — covers both table rows and grid cards) ───
      var statusSel   = document.getElementById('filterStatus');
      var deptSel     = document.getElementById('filterDepartment');
      var searchInput = document.getElementById('staffSearchInput');

      // Table rows also paginate on top of filtering, so filtering just
      // records which rows match (via a plain JS property, not the
      // .hidden class) and paginate() below decides final visibility for
      // that view; grid cards have no pagination, so filtering still
      // toggles .hidden on them directly.
      var tableRows = Array.prototype.slice.call(document.querySelectorAll('#staffViewTable tbody tr[data-status]'));

      function matchesFilters(el, status, dept, search) {
        var matchesStatus = !status || el.dataset.status === status;
        var matchesDept   = !dept   || el.dataset.department === dept;
        var matchesSearch = !search || el.dataset.search.indexOf(search) !== -1;
        return matchesStatus && matchesDept && matchesSearch;
      }

      function applyFilters() {
        var status = statusSel.value;
        var dept   = deptSel.value;
        var search = searchInput ? searchInput.value.trim().toLowerCase() : '';
        document.querySelectorAll('[data-status]').forEach(function (el) {
          var match = matchesFilters(el, status, dept, search);
          if (el.closest('#staffViewTable')) {
            el._filterMatch = match;
          } else {
            el.classList.toggle('hidden', !match);
          }
        });
        currentPage = 1;
        paginate();
      }
      statusSel.addEventListener('change', applyFilters);
      deptSel.addEventListener('change', applyFilters);
      if (searchInput) searchInput.addEventListener('input', applyFilters);

      // ── Table pagination ─────────────────────────────────────────────────
      var rowsPerPageSel   = document.getElementById('rowsPerPage');
      var paginationFrom   = document.getElementById('paginationFrom');
      var paginationTo     = document.getElementById('paginationTo');
      var paginationTotal  = document.getElementById('paginationTotal');
      var paginationNumbers = document.getElementById('paginationNumbers');
      var prevBtn = document.getElementById('paginationPrev');
      var nextBtn = document.getElementById('paginationNext');
      var currentPage = 1;

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

      function renderPageNumbers(current, total) {
        paginationNumbers.innerHTML = '';
        buildPageList(current, total).forEach(function (item) {
          if (item === 'ellipsis') {
            var span = document.createElement('span');
            span.className = 'staff-page-ellipsis';
            span.textContent = '…';
            paginationNumbers.appendChild(span);
            return;
          }
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'staff-page-btn' + (item === current ? ' active' : '');
          btn.textContent = item;
          btn.addEventListener('click', function () { currentPage = item; paginate(); });
          paginationNumbers.appendChild(btn);
        });
      }

      function paginate() {
        if (!tableRows.length) return;
        var rowsPerPage = parseInt(rowsPerPageSel.value, 10);
        var matched = tableRows.filter(function (r) { return r._filterMatch !== false; });
        var total = matched.length;
        var totalPages = Math.max(1, Math.ceil(total / rowsPerPage));
        if (currentPage > totalPages) currentPage = totalPages;

        var start = (currentPage - 1) * rowsPerPage;
        var end = start + rowsPerPage;
        matched.forEach(function (r, i) {
          r.classList.toggle('hidden', !(i >= start && i < end));
        });
        tableRows.forEach(function (r) {
          if (r._filterMatch === false) r.classList.add('hidden');
        });

        paginationFrom.textContent = total === 0 ? 0 : start + 1;
        paginationTo.textContent = Math.min(end, total);
        paginationTotal.textContent = total;
        renderPageNumbers(currentPage, totalPages);
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
      }

      if (rowsPerPageSel) {
        rowsPerPageSel.addEventListener('change', function () { currentPage = 1; paginate(); });
        prevBtn.addEventListener('click', function () { if (currentPage > 1) { currentPage--; paginate(); } });
        nextBtn.addEventListener('click', function () { currentPage++; paginate(); });
      }

      // ── Select-all + row checkboxes drive the Export CSV button: checking
      // any rows switches it to "Export Selected (n)" and exports just
      // those accounts; with nothing checked it exports the full list. ───
      var selectAllStaff = document.getElementById('selectAllStaff');
      var exportBtn      = document.getElementById('btnExportCsv');
      var exportBtnLabel = document.getElementById('btnExportCsvLabel');
      var rowCheckboxes  = Array.prototype.slice.call(document.querySelectorAll('#staffViewTable .staff-row-checkbox'));

      function updateExportLabel() {
        var checkedCount = rowCheckboxes.filter(function (cb) { return cb.checked; }).length;
        exportBtnLabel.textContent = checkedCount > 0 ? 'Export Selected (' + checkedCount + ')' : 'Export CSV';
      }

      rowCheckboxes.forEach(function (cb) { cb.addEventListener('change', updateExportLabel); });

      if (selectAllStaff) {
        selectAllStaff.addEventListener('change', function () {
          document.querySelectorAll('#staffViewTable tbody tr[data-status]:not(.hidden) .staff-row-checkbox').forEach(function (cb) {
            cb.checked = selectAllStaff.checked;
          });
          updateExportLabel();
        });
      }

      if (exportBtn) {
        exportBtn.addEventListener('click', function () {
          var ids = rowCheckboxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
          var url = 'staff_manage?export=csv';
          if (ids.length) url += '&ids=' + ids.join(',');
          window.location.href = url;
        });
      }

      applyFilters();

      // ── Add Staff modal open/close ───────────────────────────────────────
      var overlay   = document.getElementById('addStaffOverlay');
      var openBtn   = document.getElementById('btnOpenAddStaff');
      var closeBtn  = document.getElementById('btnCloseAddStaff');

      function openModal()  { overlay.classList.remove('hidden'); }
      function closeModal() { overlay.classList.add('hidden'); }

      openBtn.addEventListener('click', openModal);
      closeBtn.addEventListener('click', closeModal);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.classList.contains('hidden')) closeModal();
      });

      // ── Staff card overflow menu (Activate/Deactivate) ───────────────────
      function closeAllCardMenus() {
        document.querySelectorAll('.staff-card-menu.open').forEach(function (m) {
          m.classList.remove('open');
          m.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', 'false');
        });
      }
      document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-menu-toggle]');
        if (toggle) {
          var menu = toggle.closest('.staff-card-menu');
          var wasOpen = menu.classList.contains('open');
          closeAllCardMenus();
          if (!wasOpen) { menu.classList.add('open'); toggle.setAttribute('aria-expanded', 'true'); }
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
        <h2>Staff Account Created</h2>

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
