<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . APP_URL . "/login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

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
function provision_teacher_account($conn, $teacher_id, $given_name) {
    $username = generate_teacher_username($conn, $given_name, $teacher_id);
    $temp_password = generate_temp_password();
    $hash = password_hash($temp_password, PASSWORD_BCRYPT);

    mysqli_begin_transaction($conn);
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

    return ['username' => $username, 'password' => $temp_password];
}

$success = '';
$error   = '';
$new_credentials  = null; // single create — one-time reveal, mirrors admin/staff_manage.php
$bulk_credentials = [];   // create-all-missing — one row per newly provisioned account

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
    $teacher_id  = (int) ($_POST['teacher_id'] ?? 0);
    $given_name  = trim($_POST['given_name'] ?? '');
    if ($teacher_id && $given_name) {
        $new_credentials = provision_teacher_account($conn, $teacher_id, $given_name);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_all_missing'])) {
    $missing_stmt = mysqli_prepare($conn, "
        SELECT t.teacher_id, t.given_name
        FROM teachers t
        LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
        WHERE u.user_id IS NULL
    ");
    mysqli_stmt_execute($missing_stmt);
    $missing = mysqli_fetch_all(mysqli_stmt_get_result($missing_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($missing_stmt);

    foreach ($missing as $m) {
        $bulk_credentials[] = provision_teacher_account($conn, $m['teacher_id'], $m['given_name']);
    }

    if (!$bulk_credentials) {
        $success = "All teachers already have accounts.";
    }
}

// ── Fetch teacher list with account status ─────────────────────────────────
$list_stmt = mysqli_prepare($conn, "
    SELECT t.teacher_id, t.family_name, t.given_name, t.middle_name, t.suffix,
           u.username, u.is_active
    FROM teachers t
    LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
    ORDER BY t.family_name ASC, t.given_name ASC
");
mysqli_stmt_execute($list_stmt);
$teachers = mysqli_fetch_all(mysqli_stmt_get_result($list_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($list_stmt);

$missing_count = count(array_filter($teachers, fn($t) => $t['username'] === null));

$conn->close();

// ── Export CSV ───────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_teachers = $teachers;
    if (!empty($_GET['ids'])) {
        $selected_ids = array_map('intval', explode(',', $_GET['ids']));
        $export_teachers = array_values(array_filter($teachers, function ($t) use ($selected_ids) {
            return in_array((int)$t['teacher_id'], $selected_ids, true);
        }));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teacher_accounts_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Username', 'Status']);
    foreach ($export_teachers as $t) {
        $full = trim($t['family_name'] . ', ' . $t['given_name']
            . ($t['middle_name'] ? ' ' . $t['middle_name'] : '')
            . ($t['suffix'] ? ' ' . $t['suffix'] : ''));
        $status = $t['username'] === null ? 'No Account' : ($t['is_active'] ? 'Active' : 'Inactive');
        fputcsv($out, [$full, $t['username'] ?? '', $status]);
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
  <title>Teacher Accounts — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
  <style>
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error   { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }
    .badge-active   { background:#EBF7F2; color:#1A7A5E; }
    .badge-inactive { background:#FDF0EF; color:#C0392B; }
    .btn-toggle { height:28px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; color:#5A5A72; }
    .btn-toggle:hover { border-color:#386641; color:#386641; }
    .btn-add { height:28px; padding:0 10px; border:none; border-radius:6px;
      background:#386641; color:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-add:hover { background:#2F5636; }
    .btn-add-all { height:38px; padding:0 16px; border:none; border-radius:8px;
      background:#386641; color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-add-all:hover { background:#2F5636; }
    .btn-add-all:disabled { background:#ADADBD; cursor:not-allowed; }
    .card-header-actions { display:flex; align-items:center; justify-content:space-between; }
  </style>
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Teacher Accounts</span></p>
        <h1 class="page-title">Teacher Account Provisioning</h1>
      </div>

      <div class="staff-overlap">

      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header card-header-actions">
          <span class="card-title">Teacher Directory <span class="staff-record-count">(<?= count($teachers) ?> records)</span> — <?= $missing_count ?> without a login</span>
          <div style="display:flex; align-items:center; gap:8px;">
            <button type="button" class="btn-export-csv" id="btnExportCsv">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
              <span id="btnExportCsvLabel">Export CSV</span>
            </button>
            <?php if ($missing_count > 0): ?>
              <form method="POST" action="teacher_accounts"
                    data-confirm="Create accounts for all <?= $missing_count ?> teacher(s) without a login? Each will get a random temporary password (shown once after creation) and must change it on first login.">
                <button type="submit" name="create_all_missing" class="btn-add-all">Create All Missing (<?= $missing_count ?>)</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($teachers)): ?>
          <p class="empty-state">No teachers found.</p>
        <?php else: ?>
          <table class="data-table" id="dataTable">
            <thead>
              <tr>
                <th class="staff-table-checkbox-col"><input type="checkbox" id="selectAllRows" aria-label="Select all"></th>
                <th>Name</th>
                <th>Username</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($teachers as $t):
                $full = trim($t['family_name'] . ', ' . $t['given_name']
                    . ($t['middle_name'] ? ' ' . $t['middle_name'] : '')
                    . ($t['suffix'] ? ' ' . $t['suffix'] : ''));
              ?>
                <tr>
                  <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$t['teacher_id'] ?>" aria-label="Select row"></td>
                  <td class="td-name">
                    <div class="td-name-cell">
                      <span class="staff-table-avatar"><?= htmlspecialchars(name_initials($full)) ?></span>
                      <span><?= htmlspecialchars($full) ?></span>
                    </div>
                  </td>
                  <td class="td-meta"><?= $t['username'] ? htmlspecialchars($t['username']) : '—' ?></td>
                  <td>
                    <?php if ($t['username'] === null): ?>
                      <span class="badge badge-inactive">No Account</span>
                    <?php elseif ($t['is_active']): ?>
                      <span class="badge badge-active">Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($t['username'] === null): ?>
                      <form method="POST" action="teacher_accounts" style="display:inline;">
                        <input type="hidden" name="teacher_id" value="<?= (int)$t['teacher_id'] ?>">
                        <input type="hidden" name="given_name" value="<?= htmlspecialchars($t['given_name']) ?>">
                        <button type="submit" name="create_account" class="btn-add">Create Account</button>
                      </form>
                    <?php endif; ?>
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

            <span class="staff-pagination-summary"><span id="paginationFrom">1</span>-<span id="paginationTo">1</span> / <span id="paginationTotal"><?= count($teachers) ?></span> results</span>
          </div>
        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var tableRows = Array.prototype.slice.call(document.querySelectorAll('#dataTable tbody tr'));
      if (!tableRows.length) return;

      var rowsPerPageSel  = document.getElementById('rowsPerPage');
      var paginationFrom  = document.getElementById('paginationFrom');
      var paginationTo    = document.getElementById('paginationTo');
      var paginationTotal = document.getElementById('paginationTotal');
      var paginationNumbers = document.getElementById('paginationNumbers');
      var prevBtn = document.getElementById('paginationPrev');
      var nextBtn = document.getElementById('paginationNext');
      var currentPage = 1;

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
        var rowsPerPage = parseInt(rowsPerPageSel.value, 10);
        var total = tableRows.length;
        var totalPages = Math.max(1, Math.ceil(total / rowsPerPage));
        if (currentPage > totalPages) currentPage = totalPages;

        var start = (currentPage - 1) * rowsPerPage;
        var end = start + rowsPerPage;
        tableRows.forEach(function (r, i) {
          r.classList.toggle('hidden', !(i >= start && i < end));
        });

        paginationFrom.textContent = total === 0 ? 0 : start + 1;
        paginationTo.textContent = Math.min(end, total);
        paginationTotal.textContent = total;
        renderPageNumbers(currentPage, totalPages);
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
      }

      rowsPerPageSel.addEventListener('change', function () { currentPage = 1; paginate(); });
      prevBtn.addEventListener('click', function () { if (currentPage > 1) { currentPage--; paginate(); } });
      nextBtn.addEventListener('click', function () { currentPage++; paginate(); });
      paginate();

      var selectAll = document.getElementById('selectAllRows');
      var exportBtn = document.getElementById('btnExportCsv');
      var exportBtnLabel = document.getElementById('btnExportCsvLabel');
      var rowCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.staff-row-checkbox'));

      function updateExportLabel() {
        var checkedCount = rowCheckboxes.filter(function (cb) { return cb.checked; }).length;
        exportBtnLabel.textContent = checkedCount > 0 ? 'Export Selected (' + checkedCount + ')' : 'Export CSV';
      }
      rowCheckboxes.forEach(function (cb) { cb.addEventListener('change', updateExportLabel); });

      if (selectAll) {
        selectAll.addEventListener('change', function () {
          document.querySelectorAll('#dataTable tbody tr:not(.hidden) .staff-row-checkbox').forEach(function (cb) {
            cb.checked = selectAll.checked;
          });
          updateExportLabel();
        });
      }

      exportBtn.addEventListener('click', function () {
        var ids = rowCheckboxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
        var url = new URL(window.location.href);
        url.searchParams.set('export', 'csv');
        if (ids.length) { url.searchParams.set('ids', ids.join(',')); } else { url.searchParams.delete('ids'); }
        window.location.href = url.toString();
      });
    });
  </script>

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
