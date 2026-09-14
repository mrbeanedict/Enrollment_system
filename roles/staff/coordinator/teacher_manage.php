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

// Strands a teacher can be assigned to. Optional — not every teacher (e.g.
// a core-subject teacher shared across strands) needs one set.
$strand_stmt = $conn->prepare("SELECT strand_id, strand_code FROM strands ORDER BY strand_code ASC");
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

$success         = '';
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
    } else {
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
                INSERT INTO users (username, password_hash, role, is_active, must_change_password)
                VALUES (?, ?, 'teacher', 1, 1)
            ");
            $u->bind_param('ss', $username, $hash);
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
            ];
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to create teacher account. Please try again.';
        }
    }
}

// ── Toggle login active status ──────────────────────────────────────────────
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

// ── Fetch teacher list ───────────────────────────────────────────────────────
$sql = "
    SELECT t.teacher_id, t.family_name, t.given_name, t.middle_name, t.suffix, t.email,
           s.strand_code, u.user_id, u.username, u.is_active
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
// NOTE: do NOT close $conn here — staff_sidebar.php (included below, in the
// HTML) needs it open to resolve the department nav.
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
  <style>
    .two-col { display:grid; grid-template-columns:1fr 340px; gap:14px; align-items:start; }
    .form-group { margin-bottom:0.9rem; }
    .form-group label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.35rem; }
    .form-group input, .form-group select { width:100%; height:38px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC; padding:0 12px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; }
    .form-group input:focus, .form-group select:focus { border-color:#2F6B4F; box-shadow:0 0 0 3px rgba(123,111,205,.14); background:#fff; }
    .btn-add { width:100%; height:38px; background:#1E4D3B; border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:.25rem; }
    .btn-add:hover { background:#163829; }
    .badge-active   { background:#EBF7F2; color:#1A7A5E; }
    .badge-inactive { background:#FDF0EF; color:#C0392B; }
    .btn-toggle { height:28px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; color:#5A5A72; }
    .btn-toggle:hover { border-color:#2F6B4F; color:#1E4D3B; }
    .divider-form { border:none; border-top:0.5px solid #EBEBF0; margin:1rem 0; }

    /* ── Credentials reveal modal ─────────────────────────────────────────── */
    .cred-overlay {
      position:fixed; inset:0; background:rgba(18,32,26,0.45); z-index:1000;
      display:flex; align-items:center; justify-content:center; padding:20px;
    }
    .cred-modal {
      width:100%; max-width:420px; background:#fff; border-radius:16px;
      box-shadow:0 24px 60px rgba(18,32,26,0.20); padding:1.75rem 1.75rem 1.5rem;
    }
    .cred-modal h2 { font-size:1.05rem; color:#141C17; margin-bottom:.35rem; }
    .cred-modal .cred-sub { font-size:12.5px; color:#5A5A72; margin-bottom:1.1rem; }
    .cred-rows { background:#F8FAF9; border:1px solid #E6ECE8; border-radius:10px; padding:14px 16px; margin-bottom:1rem; }
    .cred-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; font-size:13px; }
    .cred-row + .cred-row { border-top:1px solid #EBEBF0; }
    .cred-row-label { color:#5A5A72; font-weight:500; }
    .cred-row-value { font-family:'Courier New',monospace; font-weight:700; color:#1A1A2E; letter-spacing:.02em; }
    .cred-note { font-size:12px; color:#8A978F; margin-bottom:1.1rem; }
    .cred-actions { display:flex; gap:10px; }
    .btn-cred-copy { flex:1; height:38px; border:0.5px solid #D4D4E0; border-radius:8px; background:#fff;
      color:#1E4D3B; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
    .btn-cred-copy:hover { border-color:#2F6B4F; }
    .btn-cred-copy.copied { background:#EBF7F2; border-color:#A8D9C5; color:#1A6B4A; }
    .btn-cred-done { flex:1; height:38px; border:none; border-radius:8px; background:#1E4D3B;
      color:#fff; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
    .btn-cred-done:hover { background:#163829; }
  </style>
</head>
<body class="staff-layout">

  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>

  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <button class="btn-sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" type="button">
          <span></span><span></span><span></span>
        </button>
        <div class="staff-topbar-title">
          Teachers
          <span class="staff-topbar-subtitle">Coordinator</span>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <p class="page-eyebrow">Coordinator Portal</p>
      <h1 class="page-title">Teacher Management</h1>

      <div class="two-col">

        <!-- Teacher list -->
        <div class="panel">
          <div class="card-header">
            <span class="card-title">Teachers (<?= count($teacher_list) ?>)</span>
          </div>

          <?php if (empty($teacher_list)): ?>
            <p class="empty-state">No teachers yet.</p>
          <?php else: ?>
            <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Username</th>
                  <th>Email</th>
                  <th>Strand</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($teacher_list as $t):
                  $full = trim($t['given_name'] . ' ' . ($t['middle_name'] ? $t['middle_name'] . ' ' : '') . $t['family_name'] . ($t['suffix'] ? ' ' . $t['suffix'] : ''));
                ?>
                  <tr>
                    <td class="td-name"><?= htmlspecialchars($full) ?></td>
                    <td class="td-meta"><?= $t['username'] ? htmlspecialchars($t['username']) : '—' ?></td>
                    <td class="td-meta"><?= htmlspecialchars($t['email'] ?? '—') ?></td>
                    <td class="td-meta"><?= htmlspecialchars($t['strand_code'] ?? '—') ?></td>
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
                      <?php if ($t['username'] !== null): ?>
                        <form method="POST" action="teacher_manage" style="display:inline;"
                              data-confirm="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars($full) ?>'s login?">
                          <input type="hidden" name="user_id" value="<?= (int)$t['user_id'] ?>">
                          <input type="hidden" name="is_active"       value="<?= (int)$t['is_active'] ?>">
                          <button type="submit" name="toggle_active" class="btn-toggle">
                            <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Add teacher form -->
        <div class="panel">
          <div class="card-header">
            <span class="card-title">Add Teacher</span>
          </div>

          <?php if ($error): ?>
            <div class="notice notice-error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form method="POST" action="teacher_manage" data-confirm="Create this teacher account?" data-icon="question">
            <div class="form-group">
              <label for="tf_family_name">Last Name <span style="color:#C0392B">*</span></label>
              <input id="tf_family_name" type="text" name="family_name" placeholder="e.g. Dela Cruz" required
                value="<?= htmlspecialchars($_POST['family_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="tf_given_name">First Name <span style="color:#C0392B">*</span></label>
              <input id="tf_given_name" type="text" name="given_name" placeholder="e.g. Maria" required
                value="<?= htmlspecialchars($_POST['given_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="tf_middle_name">Middle Name</label>
              <input id="tf_middle_name" type="text" name="middle_name" placeholder="Optional"
                value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="tf_suffix">Suffix</label>
              <input id="tf_suffix" type="text" name="suffix" placeholder="Jr., Sr., III, etc. (optional)"
                value="<?= htmlspecialchars($_POST['suffix'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="tf_contact">Contact Number</label>
              <input id="tf_contact" type="text" name="contact_number" placeholder="e.g. 09xx-xxx-xxxx"
                value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="tf_email">Email Address <span style="color:#C0392B">*</span></label>
              <input id="tf_email" type="email" name="email" placeholder="name@example.com" required
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="tf_strand">Strand</label>
              <select id="tf_strand" name="strand_id">
                <option value="">Not assigned</option>
                <?php foreach ($strands as $st): ?>
                  <option value="<?= (int)$st['strand_id'] ?>" <?= ($_POST['strand_id'] ?? '') == $st['strand_id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['strand_code']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <hr class="divider-form">
            <p class="field-hint" style="margin-bottom:.9rem;">Department is set to Faculty automatically. Username and a temporary password are generated automatically.</p>
            <button type="submit" name="add_teacher" class="btn-add">Create Teacher Account</button>
          </form>
        </div>

      </div>

    </div>
  </div>

  <?php if ($new_credentials): ?>
    <div class="cred-overlay" id="credOverlay">
      <div class="cred-modal">
        <h2>Teacher Account Created</h2>
        <p class="cred-sub">
          <?php if ($new_credentials['mail_sent']): ?>
            Credentials have also been emailed to the new teacher.
          <?php else: ?>
            <span style="color:#8A6100;">Account created, but credentials could not be emailed.</span> Please share these with the teacher directly.
          <?php endif; ?>
        </p>

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

        <p class="cred-note">Password change required on first login.</p>

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

        copyBtn.addEventListener('click', function () {
          var text = 'Username: ' + username + '\nTemporary Password: ' + password;
          var done = function () {
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

        doneBtn.addEventListener('click', function () {
          overlay.remove();
        });
      })();
    </script>
  <?php endif; ?>

</body>
</html>
