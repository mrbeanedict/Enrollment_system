<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('admin');

// Given-name-only username, lowercase letters only; falls back to
// appending the teacher_id on a uniqueness collision (none exist today,
// but this keeps future additions safe).
function generate_teacher_username($conn, $given_name, $teacher_id) {
    $base = strtolower(preg_replace('/[^a-zA-Z]/', '', $given_name));
    if ($base === '') {
        $base = 'teacher';
    }
    $username = $base;
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        $username = $base . $teacher_id;
    }
    mysqli_stmt_close($stmt);
    return $username;
}

// An 8-character temporary password from an unambiguous charset (no 0/O,
// 1/l/I) — same convention as admin/staff_manage.php's
// generate_temp_password(), never derived from any account information,
// only ever held in memory for this one request (display), only its
// bcrypt hash is stored.
function generate_temp_password() {
    $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max     = strlen($charset) - 1;
    $pw      = '';
    for ($i = 0; $i < 8; $i++) {
        $pw .= $charset[random_int(0, $max)];
    }
    return $pw;
}

// Returns ['username' => ..., 'password' => ...] — the plaintext password
// is only ever the caller's to display once; must_change_password forces
// the teacher to set their own on first login (see teacher_login.php /
// teacher_change_password.php, which already gate on this column).
// The teacher's name comes from the database, not the request, and a teacher
// who already has a login is skipped (null) — so refreshing the page after a
// create can't mint a second account and orphan the first.
function provision_teacher_account($conn, $teacher_id) {
    $lookup = mysqli_prepare($conn, "
        SELECT t.given_name, u.user_id
        FROM teachers t
        LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
        WHERE t.teacher_id = ?
    ");
    mysqli_stmt_bind_param($lookup, "i", $teacher_id);
    mysqli_stmt_execute($lookup);
    $teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
    mysqli_stmt_close($lookup);
    if (!$teacher || $teacher['user_id'] !== null) {
        return null;
    }

    $username = generate_teacher_username($conn, $teacher['given_name'], $teacher_id);
    $temp_password = generate_temp_password();
    $hash = password_hash($temp_password, PASSWORD_BCRYPT);

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO users (username, password_hash, role, is_active, must_change_password, created_at)
            VALUES (?, ?, 'teacher', 1, 1, CURRENT_TIMESTAMP)
        ");
        mysqli_stmt_bind_param($stmt, "ss", $username, $hash);
        mysqli_stmt_execute($stmt);
        $new_user_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $upd = mysqli_prepare($conn, "UPDATE teachers SET user_id = ? WHERE teacher_id = ?");
        mysqli_stmt_bind_param($upd, "ii", $new_user_id, $teacher_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        return null;
    }

    return ['username' => $username, 'password' => $temp_password];
}

$success = '';
$error   = '';
$new_credentials  = null; // single create — one-time reveal, mirrors admin/staff_manage.php
$bulk_credentials = [];   // create-all-missing / create-selected — one row per newly provisioned account

// "Family, Given[ Middle][ Suffix]" -> two-letter avatar initials — same
// technique as students.php/enrollments.php's name_initials().
function name_initials(string $commaName): string {
    $parts  = array_map('trim', explode(',', $commaName, 2));
    $family = $parts[0] ?? '';
    $given  = $parts[1] ?? '';
    $initials = mb_strtoupper(mb_substr($family, 0, 1) . mb_substr($given, 0, 1));
    return $initials !== '' ? $initials : '?';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
    $teacher_id = (int) ($_POST['teacher_id'] ?? 0);
    if ($teacher_id) {
        $new_credentials = provision_teacher_account($conn, $teacher_id);
        if (!$new_credentials) {
            $error = 'That teacher already has an account, or it could not be created.';
        }
    }
}

// Create accounts for every teacher without one ("Create all missing"), or
// only the checked rows ("Create accounts" in the selection bar). Teachers
// who already have a login are skipped either way.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['create_all_missing']) || isset($_POST['create_selected']))) {
    $only_ids = isset($_POST['create_selected'])
        ? array_values(array_unique(array_filter(array_map('intval', (array)($_POST['teacher_ids'] ?? [])))))
        : null;

    $missing_stmt = mysqli_prepare($conn, "
        SELECT t.teacher_id
        FROM teachers t
        LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
        WHERE u.user_id IS NULL
    ");
    mysqli_stmt_execute($missing_stmt);
    $missing = mysqli_fetch_all(mysqli_stmt_get_result($missing_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($missing_stmt);

    foreach ($missing as $m) {
        if ($only_ids !== null && !in_array((int)$m['teacher_id'], $only_ids, true)) {
            continue;
        }
        $cred = provision_teacher_account($conn, (int)$m['teacher_id']);
        if ($cred) {
            $bulk_credentials[] = $cred;
        }
    }

    if (!$bulk_credentials) {
        $success = "No accounts needed creating — every selected teacher already has one.";
    }
}

// ── Fetch teacher list with account status ─────────────────────────────────
$list_stmt = mysqli_prepare($conn, "
    SELECT t.teacher_id, t.family_name, t.given_name, t.middle_name, t.suffix,
           t.email, t.contact_number, t.created_at, s.strand_code,
           u.username, u.is_active
    FROM teachers t
    LEFT JOIN strands s ON s.strand_id = t.strand
    LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
    ORDER BY t.family_name ASC, t.given_name ASC
");
mysqli_stmt_execute($list_stmt);
$teachers = mysqli_fetch_all(mysqli_stmt_get_result($list_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($list_stmt);

$conn->close();

foreach ($teachers as &$t) {
    $t['full_name'] = trim($t['family_name'] . ', ' . $t['given_name']
        . ($t['middle_name'] ? ' ' . $t['middle_name'] : '')
        . ($t['suffix'] ? ' ' . $t['suffix'] : ''));
    $t['state'] = $t['username'] === null ? 'none' : ($t['is_active'] ? 'active' : 'inactive');
}
unset($t);

// "Handled strand" is the teacher's specialty/homeroom assignment, not a
// cap on what they can teach — same distinction coordinator/teacher_manage.php
// makes when it lets any teacher be assigned to any strand.

$state_counts  = array_count_values(array_column($teachers, 'state'));
$missing_count = $state_counts['none'] ?? 0;

// ── Export CSV ───────────────────────────────────────────────────────────
// POSTed by the page (see assets/js/admin_table.js); `ids` (comma list of
// teacher ids) limits it to the checked / filtered rows, none = everyone.
// Usernames only — passwords are never stored in a recoverable form.
$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if (($req['export'] ?? '') === 'csv') {
    $export_rows = $teachers;
    if (!empty($req['ids'])) {
        $selected_ids = array_map('intval', explode(',', (string)$req['ids']));
        $export_rows = array_values(array_filter($teachers, function ($t) use ($selected_ids) {
            return in_array((int)$t['teacher_id'], $selected_ids, true);
        }));
    }

    $state_labels = ['none' => 'No account', 'active' => 'Active', 'inactive' => 'Inactive'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teacher_accounts_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Username', 'Email', 'Contact Number', 'Handled Strand', 'Account Status', 'Date Joined']);
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

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
// Same contact-line icons as admin/students.php's $svg_mail/$svg_phone.
$svg_mail  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
$svg_phone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Teacher Accounts — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero staff-hero--row">
        <div class="staff-hero-text">
          <p class="page-eyebrow">Admin Portal</p>
          <h1 class="page-title">Teacher accounts</h1>
          <p class="staff-hero-desc">Give teachers a portal login. Each new account gets a temporary password, shown once, that the teacher must change at first sign-in.</p>
        </div>
        <?php if ($teachers): ?>
          <div class="staff-hero-actions">
            <button type="button" class="btn-export-csv" id="tblExport">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
              <span id="tblExportLabel">Export CSV</span>
            </button>
            <?php if ($missing_count > 0): ?>
              <form method="POST" action="teacher_accounts"
                    data-confirm="Create accounts for all <?= $missing_count ?> teacher(s) without a login? Each will get a random temporary password (shown once after creation) and must change it on first login.">
                <button type="submit" name="create_all_missing" class="btn-add-staff">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                  Create all missing (<?= $missing_count ?>)
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="staff-overlap">

      <?php if ($success): ?>
        <div class="alert alert-success" data-ui-toast><?= $h($success) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><?= $h($error) ?></div>
      <?php endif; ?>

      <div class="card staff-panel">
        <?php if (empty($teachers)): ?>
          <p class="empty-state">No teachers found.</p>
        <?php else: ?>

        <div class="staff-toolbar">
          <div class="staff-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <label for="tblSearch" class="sr-only">Search teachers</label>
            <input type="search" id="tblSearch" placeholder="Search by name or username" autocomplete="off">
          </div>
          <select class="staff-filter-select" id="tblSort" aria-label="Sort teachers">
            <option value="name:asc">Sort: Name (A to Z)</option>
            <option value="name:desc">Sort: Name (Z to A)</option>
            <option value="state:desc">Sort: Needs account first</option>
          </select>
          <button type="button" class="staff-toolbar-reset hidden" id="tblReset">Reset filters</button>
        </div>

        <div class="staff-pills" role="group" aria-label="Filter by account status" data-pills data-attr="state" data-param="status">
          <button type="button" class="staff-pill active" data-value="" aria-pressed="true">All <span data-count><?= count($teachers) ?></span></button>
          <button type="button" class="staff-pill" data-value="none" aria-pressed="false">No account <span data-count><?= $missing_count ?></span></button>
          <button type="button" class="staff-pill" data-value="active" aria-pressed="false">Active <span data-count><?= (int)($state_counts['active'] ?? 0) ?></span></button>
          <button type="button" class="staff-pill" data-value="inactive" aria-pressed="false">Inactive <span data-count><?= (int)($state_counts['inactive'] ?? 0) ?></span></button>
        </div>

        <div class="staff-bulkbar hidden" id="tblBulkBar" role="region" aria-label="Bulk actions">
          <strong class="staff-bulk-count" id="tblBulkCount" aria-live="polite">0 selected</strong>
          <button type="button" class="staff-bulk-btn" id="bulkCreate">Create accounts</button>
          <button type="button" class="staff-bulk-btn" data-bulk="export">Export selected</button>
          <button type="button" class="staff-bulk-clear" data-bulk="clear">Clear selection</button>
        </div>

        <table class="data-table staff-table" id="dataTable">
          <thead>
            <tr>
              <th class="staff-table-checkbox-col"><input type="checkbox" id="tblSelectAll" aria-label="Select all on this page"></th>
              <th>Teacher</th>
              <th>Contact</th>
              <th>Handled Strand</th>
              <th>Status</th>
              <th>Date Joined</th>
              <th class="staff-actions-col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($teachers as $t):
              $search = mb_strtolower(implode(' ', [
                  $t['family_name'], $t['given_name'], $t['middle_name'] ?? '',
                  $t['given_name'] . ' ' . $t['family_name'], $t['username'] ?? '',
                  $t['email'] ?? '', $t['strand_code'] ?? '',
              ]));
            ?>
              <tr class="<?= $t['state'] === 'active' ? '' : 'is-dim' ?>"
                  data-search="<?= $h($search) ?>"
                  data-state="<?= $t['state'] ?>"
                  data-sort-name="<?= $h(mb_strtolower($t['full_name'])) ?>"
                  data-sort-state="<?= $t['state'] === 'none' ? 1 : 0 ?>">
                <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$t['teacher_id'] ?>" aria-label="Select <?= $h($t['full_name']) ?>"></td>
                <td class="td-name">
                  <div class="td-name-cell">
                    <span class="staff-table-avatar"><?= $h(name_initials($t['full_name'])) ?></span>
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
                  <?php if (empty($t['email']) && empty($t['contact_number'])): ?><span class="td-muted">—</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($t['strand_code']): ?>
                    <span class="staff-role-tag"><?= $h($t['strand_code']) ?></span>
                  <?php else: ?>
                    <span class="td-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($t['state'] === 'none'): ?>
                    <span class="staff-state staff-state--pending"><i></i>No account</span>
                  <?php elseif ($t['state'] === 'active'): ?>
                    <span class="staff-state staff-state--active"><i></i>Active</span>
                  <?php else: ?>
                    <span class="staff-state staff-state--muted"><i></i>Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="staff-joined"><?= $t['created_at'] ? $h(date('M j, Y', strtotime($t['created_at']))) : '—' ?></td>
                <td class="staff-actions-col">
                  <?php if ($t['state'] === 'none'): ?>
                    <form method="POST" action="teacher_accounts">
                      <input type="hidden" name="teacher_id" value="<?= (int)$t['teacher_id'] ?>">
                      <button type="submit" name="create_account" class="tbl-action-btn">Create account</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="empty-state hidden" data-no-match>
          <strong>No teachers match these filters</strong>
          Try a different name or username.
          <div><button type="button" class="staff-btn staff-btn--ghost" data-clear-filters>Clear filters</button></div>
        </div>

        <?php $tbl_total = count($teachers); $tbl_noun = 'teachers'; include BASE_PATH . '/shared/includes/admin_table_footer.php'; ?>

        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <?php if ($teachers): ?>
    <form method="POST" action="teacher_accounts" id="bulkCreateForm" class="hidden" data-confirm="Create accounts for the selected teachers?">
      <input type="hidden" name="create_selected" value="1">
      <div id="bulkCreateIds"></div>
    </form>

    <script src="<?= APP_URL ?>/assets/js/admin_table.js?v=<?= filemtime(__DIR__ . '/../../assets/js/admin_table.js') ?>"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        // "Create accounts" only applies to selected teachers who don't have one yet.
        function needAccount(rows) { return rows.filter(function (r) { return r.getAttribute('data-state') === 'none'; }); }

        AdminTable.init({
          noun: 'teachers',
          bulk: [{
            id: 'bulkCreate',
            when: function (rows) { return needAccount(rows).length > 0; },
            run: function (rows) {
              var todo = needAccount(rows);
              if (!todo.length) return;
              var form = document.getElementById('bulkCreateForm');
              var box  = document.getElementById('bulkCreateIds');
              box.innerHTML = '';
              todo.forEach(function (r) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'teacher_ids[]';
                input.value = r._cb.value;
                box.appendChild(input);
              });
              form.dataset.confirm = 'Create accounts for ' + todo.length + ' teacher' + (todo.length === 1 ? '' : 's') +
                '? Each gets a random temporary password (shown once after creation) and must change it on first login.';
              form.requestSubmit();
            }
          }]
        });
      });
    </script>
  <?php endif; ?>

  <?php if ($new_credentials): ?>
    <div class="modal-overlay" id="credOverlay">
      <div class="modal-panel cred-modal">
        <h2>Teacher Account Created</h2>

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

  <?php if ($bulk_credentials): ?>
    <div class="modal-overlay" id="bulkCredOverlay">
      <div class="modal-panel cred-modal">
        <h2><?= count($bulk_credentials) ?> Teacher Account(s) Created</h2>

        <div class="cred-rows" style="max-height:320px; overflow-y:auto;">
          <?php foreach ($bulk_credentials as $cred): ?>
            <div class="cred-row">
              <span class="cred-row-label"><?= htmlspecialchars($cred['username']) ?></span>
              <span class="cred-row-value"><?= htmlspecialchars($cred['password']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <p class="cred-note"><strong>These passwords will not be shown again.</strong> Copy them before closing — each account must change its password on first login.</p>

        <div class="cred-actions">
          <button type="button" class="btn-cred-copy" id="btnCopyBulkCred">Copy All Credentials</button>
          <button type="button" class="btn-cred-done" id="btnDoneBulkCred">Done</button>
        </div>
      </div>
    </div>

    <script>
      (function () {
        var overlay = document.getElementById('bulkCredOverlay');
        var copyBtn = document.getElementById('btnCopyBulkCred');
        var doneBtn = document.getElementById('btnDoneBulkCred');
        var pairs = <?= json_encode(array_map(function ($c) {
            return $c['username'] . "\t" . $c['password'];
        }, $bulk_credentials), JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var hasCopied = false;

        copyBtn.addEventListener('click', function () {
          var text = pairs.map(function (p) {
            var parts = p.split('\t');
            return 'Username: ' + parts[0] + '  Temporary Password: ' + parts[1];
          }).join('\n');
          var done = function () {
            hasCopied = true;
            copyBtn.textContent = 'Copied!';
            copyBtn.classList.add('copied');
            setTimeout(function () {
              copyBtn.textContent = 'Copy All Credentials';
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

          var warning = 'You haven’t copied these temporary passwords yet. They cannot be recovered once this closes.';
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
