<?php
session_start();
include_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login"); exit();
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS school_year_settings (
        school_year         VARCHAR(9) PRIMARY KEY,
        is_admission_open   TINYINT(1) NOT NULL DEFAULT 1,
        is_enrollment_open  TINYINT(1) NOT NULL DEFAULT 1,
        updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

$success = '';
$error   = '';

// ── Add a new school year row ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_year'])) {
    $sy = trim($_POST['new_school_year'] ?? '');
    if (!preg_match('/^\d{4}-\d{4}$/', $sy)) {
        $error = 'Enter the school year as YYYY-YYYY, e.g. 2027-2028.';
    } else {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO school_year_settings (school_year, is_admission_open, is_enrollment_open) VALUES (?, 0, 0)"
        );
        $stmt->bind_param('s', $sy);
        $stmt->execute();
        $stmt->close();
        header("Location: school_year_settings?saved=1"); exit();
    }
}

// ── Toggle admission/enrollment open for one school year ───────────────────
// Only one school year may have a given flag open at a time — opening it here
// closes that same flag everywhere else first, in one transaction, so the
// table can never again end up with two rows both claiming to be "open" (the
// exact state that caused the wrong cycle to be picked up elsewhere).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_open'])) {
    $sy    = $_POST['school_year'] ?? '';
    $field = $_POST['field'] ?? '';
    $value = isset($_POST['value']) && $_POST['value'] === '1' ? 1 : 0;

    if (!in_array($field, ['is_admission_open', 'is_enrollment_open'], true) || $sy === '') {
        $error = 'Invalid request.';
    } else {
        $conn->begin_transaction();
        try {
            if ($value === 1) {
                $conn->query("UPDATE school_year_settings SET $field = 0");
            }
            $stmt = $conn->prepare("UPDATE school_year_settings SET $field = ? WHERE school_year = ?");
            $stmt->bind_param('is', $value, $sy);
            if (!$stmt->execute()) {
                throw new mysqli_sql_exception($stmt->error);
            }
            $stmt->close();
            $conn->commit();
            header("Location: school_year_settings?saved=1"); exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = 'Could not update school year settings. Please try again.';
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'School year settings saved.';
}

$years = mysqli_query($conn, "SELECT school_year, is_admission_open, is_enrollment_open, updated_at FROM school_year_settings ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>School Year Settings — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_admin.css') ?>">
  <style>
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error   { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }
    .add-year-form { display:flex; gap:8px; margin-bottom:1.25rem; align-items:flex-end; }
    .add-year-form input { height:38px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC; padding:0 12px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; width:180px; }
    .add-year-form input:focus { border-color:#386641; box-shadow:0 0 0 3px rgba(123,111,205,.14); background:#fff; }
    .btn-add-inline { height:38px; padding:0 16px; background:#386641; border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-add-inline:hover { background:#2F5636; }
    .toggle-pill { display:inline-flex; border:0.5px solid #D4D4E0; border-radius:20px; overflow:hidden; }
    .toggle-pill button { border:none; background:#fff; font-size:11px; font-weight:600; padding:5px 12px;
      cursor:pointer; font-family:inherit; color:#5A5A72; }
    .toggle-pill button.on { background:#386641; color:#fff; }
    .toggle-pill button:disabled { cursor:default; }
    .td-meta { font-size:11px; color:#8A8A9A; }
  </style>
</head>
<body>

  <?php include_once 'sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">School Year Settings</span></p>
        <h1 class="page-title">School Year Settings</h1>
        <p class="page-sub">Only one school year can be open for admission and one for enrollment at a time — every page across the system (Admission, Pre-Enrollment) reads whichever row is flagged open here.</p>
      </div>

      <div class="staff-overlap">

      <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="card">
        <div class="card-header">
          <span class="card-title">School Years</span>
        </div>

        <form method="POST" action="school_year_settings" class="add-year-form">
          <div>
            <label class="field-hint" for="new_school_year" style="display:block;margin-bottom:.35rem;">Add a school year (YYYY-YYYY)</label>
            <input id="new_school_year" type="text" name="new_school_year" placeholder="2027-2028" pattern="\d{4}-\d{4}" required>
          </div>
          <button type="submit" name="add_year" class="btn-add-inline">Add</button>
        </form>

        <?php if (empty($years)): ?>
          <p class="empty-state">No school years configured yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>School Year</th>
                <th>Admission</th>
                <th>Enrollment</th>
                <th>Last Updated</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($years as $y): ?>
                <tr>
                  <td class="td-name"><?= htmlspecialchars($y['school_year']) ?></td>
                  <td>
                    <div class="toggle-pill">
                      <form method="POST" action="school_year_settings" style="display:inline;">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($y['school_year']) ?>">
                        <input type="hidden" name="field" value="is_admission_open">
                        <input type="hidden" name="value" value="0">
                        <button type="submit" name="set_open" <?= !$y['is_admission_open'] ? 'class="on" disabled' : '' ?>>Closed</button>
                      </form>
                      <form method="POST" action="school_year_settings" style="display:inline;">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($y['school_year']) ?>">
                        <input type="hidden" name="field" value="is_admission_open">
                        <input type="hidden" name="value" value="1">
                        <button type="submit" name="set_open" <?= $y['is_admission_open'] ? 'class="on" disabled' : '' ?>>Open</button>
                      </form>
                    </div>
                  </td>
                  <td>
                    <div class="toggle-pill">
                      <form method="POST" action="school_year_settings" style="display:inline;">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($y['school_year']) ?>">
                        <input type="hidden" name="field" value="is_enrollment_open">
                        <input type="hidden" name="value" value="0">
                        <button type="submit" name="set_open" <?= !$y['is_enrollment_open'] ? 'class="on" disabled' : '' ?>>Closed</button>
                      </form>
                      <form method="POST" action="school_year_settings" style="display:inline;">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($y['school_year']) ?>">
                        <input type="hidden" name="field" value="is_enrollment_open">
                        <input type="hidden" name="value" value="1">
                        <button type="submit" name="set_open" <?= $y['is_enrollment_open'] ? 'class="on" disabled' : '' ?>>Open</button>
                      </form>
                    </div>
                  </td>
                  <td class="td-meta"><?= date('M j, Y g:i A', strtotime($y['updated_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

</body>
</html>
