<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('admin');

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS school_year_settings (
        school_year         VARCHAR(9) PRIMARY KEY,
        is_admission_open   TINYINT(1) NOT NULL DEFAULT 1,
        is_enrollment_open  TINYINT(1) NOT NULL DEFAULT 1,
        updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by           VARCHAR(100) NULL
    )
");
// Tables created before "Last updated → by whom" existed don't have updated_by yet.
if (mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM school_year_settings LIKE 'updated_by'")) === 0) {
    mysqli_query($conn, "ALTER TABLE school_year_settings ADD COLUMN updated_by VARCHAR(100) NULL");
}

// One row per change (open / close / add). Undo writes its own rows too, so
// the log always reads as the true sequence of what happened.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS school_year_history (
        history_id   INT AUTO_INCREMENT PRIMARY KEY,
        school_year  VARCHAR(9) NOT NULL,
        action       VARCHAR(10) NOT NULL,
        field        VARCHAR(20) NULL,
        note         VARCHAR(255) NULL,
        changed_by   VARCHAR(100) NOT NULL,
        changed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

const SY_FIELDS = [
    'is_admission_open'  => ['key' => 'admission',  'label' => 'Admission'],
    'is_enrollment_open' => ['key' => 'enrollment', 'label' => 'Enrollment'],
];
const SY_UNDO_WINDOW = 120; // seconds the server keeps honoring an Undo

$admin_name = (string)($_SESSION['username'] ?? 'Admin');
$current_sy = current_real_school_year();
$current_term = semester_for_date($current_sy, date('Y-m-d'));
$current_start = (int)substr($current_sy, 0, 4);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function sy_next_year(string $sy): string {
    $start = (int)substr($sy, 0, 4) + 1;
    return $start . '-' . ($start + 1);
}

function sy_log(mysqli $conn, string $sy, string $action, ?string $field, ?string $note, string $by): void {
    $stmt = $conn->prepare("INSERT INTO school_year_history (school_year, action, field, note, changed_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $sy, $action, $field, $note, $by);
    $stmt->execute();
    $stmt->close();
}

// Sets one flag on one year and — when opening — closes it everywhere else
// first, all in the caller's transaction. Returns the years that were closed
// as a side effect so the caller can describe (and undo) the change.
function sy_apply(mysqli $conn, string $sy, string $field, int $value, string $by, ?string $note = null): array {
    $closed = [];
    $key = SY_FIELDS[$field]['key'];
    if ($value === 1) {
        $stmt = $conn->prepare("SELECT school_year FROM school_year_settings WHERE $field = 1 AND school_year <> ?");
        $stmt->bind_param('s', $sy);
        $stmt->execute();
        $closed = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'school_year');
        $stmt->close();

        $stmt = $conn->prepare("UPDATE school_year_settings SET $field = 0, updated_by = ? WHERE $field = 1 AND school_year <> ?");
        $stmt->bind_param('ss', $by, $sy);
        $stmt->execute();
        $stmt->close();
        foreach ($closed as $c) {
            sy_log($conn, $c, 'close', $key, "Closed automatically when $sy was opened", $by);
        }
    }
    $stmt = $conn->prepare("UPDATE school_year_settings SET $field = ?, updated_by = ? WHERE school_year = ?");
    $stmt->bind_param('iss', $value, $by, $sy);
    if (!$stmt->execute()) {
        throw new mysqli_sql_exception($stmt->error);
    }
    $stmt->close();
    sy_log($conn, $sy, $value ? 'open' : 'close', $key, $note, $by);
    return $closed;
}

$error = '';

// ── Add the next school year (generated from the latest one — never typed) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_year'])) {
    $latest = mysqli_fetch_row(mysqli_query($conn, "SELECT MAX(school_year) FROM school_year_settings"))[0];
    $new_sy = sy_next_year($latest ?: current_real_school_year());
    if ($latest === null) { $new_sy = current_real_school_year(); }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT IGNORE INTO school_year_settings (school_year, is_admission_open, is_enrollment_open, updated_by) VALUES (?, 0, 0, ?)");
        $stmt->bind_param('ss', $new_sy, $admin_name);
        $stmt->execute();
        $added = $stmt->affected_rows > 0;
        $stmt->close();
        if ($added) { sy_log($conn, $new_sy, 'add', null, null, $admin_name); }
        $conn->commit();
        $_SESSION['sy_toast'] = ['type' => 'success', 'title' => "School year $new_sy added", 'message' => 'It starts closed for both Admission and Enrollment.', 'undo' => false];
        header("Location: school_year_settings"); exit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $error = 'Could not add the school year. Please try again.';
    }
}

// ── Open / close one flag for one school year ───────────────────────────────
// Only one school year may have a given flag open at a time — opening it here
// closes that same flag everywhere else first, in one transaction, so the
// table can never again end up with two rows both claiming to be "open" (the
// exact state that caused the wrong cycle to be picked up elsewhere).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_open'])) {
    $sy    = $_POST['school_year'] ?? '';
    $field = $_POST['field'] ?? '';
    $value = ($_POST['value'] ?? '') === '1' ? 1 : 0;

    $row = null;
    if (isset(SY_FIELDS[$field]) && preg_match('/^\d{4}-\d{4}$/', $sy)) {
        $stmt = $conn->prepare("SELECT school_year, $field AS flag FROM school_year_settings WHERE school_year = ?");
        $stmt->bind_param('s', $sy);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$row) {
        $error = 'Invalid request.';
    } elseif ($value === 1 && (int)substr($sy, 0, 4) < $current_start) {
        $error = "$sy is archived and can't be reopened.";
    } elseif ((int)$row['flag'] === $value) {
        header("Location: school_year_settings"); exit();
    } else {
        $label = SY_FIELDS[$field]['label'];
        $conn->begin_transaction();
        try {
            $closed = sy_apply($conn, $sy, $field, $value, $admin_name);
            $conn->commit();
            $_SESSION['sy_undo'] = ['field' => $field, 'year' => $sy, 'value' => $value, 'closed' => $closed, 'at' => time()];
            $_SESSION['sy_toast'] = [
                'type'    => 'success',
                'title'   => $value ? "$label opened for $sy" : "$label closed for $sy",
                'message' => $value && $closed ? implode(', ', $closed) . ' was closed.' : '',
                'undo'    => true,
            ];
            header("Location: school_year_settings"); exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = 'Could not update school year settings. Please try again.';
        }
    }
}

// ── Undo the last open / close (server-checked, not just a client timer) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_change'])) {
    $u = $_SESSION['sy_undo'] ?? null;
    unset($_SESSION['sy_undo']);
    if (!$u || time() - $u['at'] > SY_UNDO_WINDOW) {
        $error = 'That change can no longer be undone.';
    } else {
        $field = $u['field'];
        $stmt = $conn->prepare("SELECT $field AS flag FROM school_year_settings WHERE school_year = ?");
        $stmt->bind_param('s', $u['year']);
        $stmt->execute();
        $now = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$now || (int)$now['flag'] !== (int)$u['value']) {
            $error = 'That setting was changed again, so this undo was skipped.';
        } else {
            $conn->begin_transaction();
            try {
                sy_apply($conn, $u['year'], $field, $u['value'] ? 0 : 1, $admin_name, 'Undo');
                if ($u['value']) {
                    // Undoing an "open" puts back whichever year(s) it had closed.
                    foreach ($u['closed'] as $prev) {
                        $stmt = $conn->prepare("UPDATE school_year_settings SET $field = 1, updated_by = ? WHERE school_year = ?");
                        $stmt->bind_param('ss', $admin_name, $prev);
                        $stmt->execute();
                        $stmt->close();
                        sy_log($conn, $prev, 'open', SY_FIELDS[$field]['key'], 'Undo', $admin_name);
                    }
                }
                $conn->commit();
                $_SESSION['sy_toast'] = ['type' => 'info', 'title' => 'Change undone', 'message' => SY_FIELDS[$field]['label'] . ' is back to how it was.', 'undo' => false];
                header("Location: school_year_settings"); exit();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $error = 'Could not undo that change. Please try again.';
            }
        }
    }
}

$toast = $_SESSION['sy_toast'] ?? null;
unset($_SESSION['sy_toast']);

$years = mysqli_query($conn, "SELECT school_year, is_admission_open, is_enrollment_open, updated_at, updated_by FROM school_year_settings ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
$history = mysqli_query($conn, "SELECT school_year, action, field, note, changed_by, changed_at FROM school_year_history ORDER BY history_id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$open = ['is_admission_open' => null, 'is_enrollment_open' => null];
foreach ($years as $y) {
    foreach ($open as $f => $v) {
        if ($v === null && $y[$f]) { $open[$f] = $y['school_year']; }
    }
}
$latest_year = $years ? $years[0]['school_year'] : null;
$next_year   = $latest_year ? sy_next_year($latest_year) : $current_sy;

$tiles = [
    'is_admission_open'  => ['Admission',  'Accepting new applicants', 'Not accepting new applicants'],
    'is_enrollment_open' => ['Enrollment', 'Enrolling students',       'Not enrolling students'],
];

function sy_history_text(array $r): string {
    $f = $r['field'] ? ucfirst($r['field']) : '';
    $text = match ($r['action']) {
        'open'  => "Opened for $f",
        'close' => "Closed for $f",
        'add'   => 'Added school year',
        default => $r['action'],
    };
    return $r['note'] ? "$text — {$r['note']}" : $text;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>School Year Settings — Admin</title>
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

      <div class="staff-hero">
        <p class="page-eyebrow">Admin Portal</p>
        <h1 class="page-title">School Year Settings</h1>
        <p class="page-sub">Control which school year accepts new applicants and enrollments. Changes apply across Admission and Pre-Enrollment immediately.</p>
      </div>

      <div class="staff-overlap sy-wrap">

        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>

        <div class="sy-tiles">
          <?php foreach ($tiles as $f => [$label, $on_text, $off_text]):
            $is_open = $open[$f] !== null; ?>
            <div class="sy-tile<?= $is_open ? '' : ' is-empty' ?>">
              <div class="sy-tile-head">
                <span class="sy-tile-label"><?= $label ?></span>
                <span class="sy-status <?= $is_open ? 'sy-status--open' : 'sy-status--closed' ?>"><i></i><?= $is_open ? 'Open' : 'Closed' ?></span>
              </div>
              <p class="sy-tile-year"><?= $is_open ? h($open[$f]) : 'None open' ?></p>
              <p class="sy-tile-sub"><?= $is_open ? $on_text : $off_text ?></p>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card sy-card">
          <div class="sy-card-head">
            <div>
              <h2 class="sy-card-title">School years</h2>
              <p class="sy-card-sub">Only one year can be open for Admission and one for Enrollment. Opening another year closes the current one.</p>
            </div>
            <div class="sy-card-actions">
              <button type="button" class="sy-btn" id="syHistoryBtn" aria-expanded="false" aria-controls="syHistory">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>
                <span>View history</span>
              </button>
              <form method="POST" action="school_year_settings">
                <button type="submit" name="add_year" class="sy-btn sy-btn--primary">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                  Add <?= h($next_year) ?>
                </button>
              </form>
            </div>
          </div>

          <?php if (empty($years)): ?>
            <p class="empty-state">No school years configured yet. Use the button above to add <?= h($next_year) ?>.</p>
          <?php else: ?>
            <div class="sy-table-wrap">
              <table class="data-table sy-table">
                <thead>
                  <tr>
                    <th>School year</th>
                    <th>Admission <span class="sy-th-sub">New applicants</span></th>
                    <th>Enrollment <span class="sy-th-sub">Pre-Enrollment</span></th>
                    <th>Last updated</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($years as $y):
                    $sy = $y['school_year'];
                    $is_current  = $sy === $current_sy;
                    $is_archived = (int)substr($sy, 0, 4) < $current_start;
                  ?>
                    <tr<?= $is_archived ? ' class="is-archived"' : '' ?>>
                      <td>
                        <span class="sy-year"><?= h($sy) ?></span>
                        <?php if ($is_current): ?>
                          <span class="sy-badge sy-badge--current" title="Matches A.Y. <?= h($current_sy) ?> • Term <?= $current_term ?> in the top bar">Current</span>
                        <?php elseif ($is_archived): ?>
                          <span class="sy-badge sy-badge--archived" title="Archived years can't be reopened">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Archived
                          </span>
                        <?php endif; ?>
                      </td>
                      <?php foreach (SY_FIELDS as $f => $meta):
                        $on = (bool)$y[$f];
                        // Archived years can be closed if they somehow are open, never reopened.
                        $locked = $is_archived && !$on;
                        $sw_label = $meta['label'] . ' for ' . $sy;
                      ?>
                        <td>
                          <form method="POST" action="school_year_settings" data-sy-form
                                data-year="<?= h($sy) ?>" data-field-label="<?= $meta['label'] ?>"
                                data-opening="<?= $on ? '0' : '1' ?>" data-prev="<?= h($open[$f] ?? '') ?>">
                            <input type="hidden" name="set_open" value="1">
                            <input type="hidden" name="school_year" value="<?= h($sy) ?>">
                            <input type="hidden" name="field" value="<?= $f ?>">
                            <input type="hidden" name="value" value="<?= $on ? '0' : '1' ?>">
                            <button type="submit" class="sy-switch<?= $on ? ' is-on' : '' ?>" role="switch"
                                    aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= h($sw_label) ?>"
                                    <?= $locked ? 'disabled title="Archived years can\'t be reopened"' : '' ?>>
                              <span class="sy-switch-track"><span class="sy-switch-thumb"></span></span>
                              <span class="sy-switch-text"><?= $on ? 'Open' : 'Closed' ?></span>
                            </button>
                          </form>
                        </td>
                      <?php endforeach; ?>
                      <td>
                        <span class="sy-when"><?= date('M j, Y g:i A', strtotime($y['updated_at'])) ?></span>
                        <span class="sy-by">by <?= h($y['updated_by'] ?: 'System') ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="card sy-card sy-history" id="syHistory" hidden>
          <div class="sy-card-head">
            <div>
              <h2 class="sy-card-title">Change history</h2>
              <p class="sy-card-sub">The 20 most recent changes, newest first.</p>
            </div>
          </div>
          <?php if (empty($history)): ?>
            <p class="empty-state">No changes recorded yet.</p>
          <?php else: ?>
            <div class="sy-table-wrap">
              <table class="data-table sy-table">
                <thead><tr><th>When</th><th>School year</th><th>Change</th><th>By</th></tr></thead>
                <tbody>
                  <?php foreach ($history as $r): ?>
                    <tr>
                      <td><span class="sy-when"><?= date('M j, Y g:i A', strtotime($r['changed_at'])) ?></span></td>
                      <td><span class="sy-year sy-year--sm"><?= h($r['school_year']) ?></span></td>
                      <td><?= h(sy_history_text($r)) ?></td>
                      <td><?= h($r['changed_by']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>

    </div>
  </div>

  <script>
    (function () {
      const loader = document.getElementById('pageLoader');
      const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

      // ── History panel ────────────────────────────────────────────────────
      const histBtn = document.getElementById('syHistoryBtn');
      const histPanel = document.getElementById('syHistory');
      histBtn.addEventListener('click', () => {
        const show = histPanel.hidden;
        histPanel.hidden = !show;
        histBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
        histBtn.querySelector('span').textContent = show ? 'Hide history' : 'View history';
        if (show) histPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });

      // ── Confirm before any open/close — say what will happen first ───────
      document.querySelectorAll('[data-sy-form]').forEach(form => {
        form.addEventListener('submit', e => {
          if (form.dataset.confirmed === '1') return;
          e.preventDefault();

          const { year, fieldLabel, opening, prev } = form.dataset;
          const isOpening = opening === '1';
          const target = fieldLabel === 'Admission' ? 'the Admission page' : 'Pre-Enrollment';
          const impact = [];
          if (isOpening) {
            if (prev && prev !== year) impact.push(`<strong>${esc(prev)}</strong> will be closed for ${esc(fieldLabel)}.`);
            impact.push(fieldLabel === 'Admission'
              ? `The Admission page will start accepting applicants for <strong>${esc(year)}</strong>.`
              : `Pre-Enrollment will start enrolling students for <strong>${esc(year)}</strong>.`);
          } else {
            impact.push(`No school year will be open for ${esc(fieldLabel)} until you open one.`);
            impact.push(fieldLabel === 'Admission'
              ? 'New applicants will not be able to apply.'
              : 'Students will not be able to complete Pre-Enrollment.');
          }
          const html = '<ul class="sy-impact">' + impact.map(t => `<li>${t}</li>`).join('') +
            '</ul><p class="sy-impact-note">This applies system-wide right away. You can undo it for 8 seconds afterwards.</p>';

          const go = () => {
            form.dataset.confirmed = '1';
            if (loader) loader.classList.add('show');
            HTMLFormElement.prototype.submit.call(form);
          };

          if (typeof Swal === 'undefined') {
            if (window.confirm((isOpening ? 'Open ' : 'Close ') + year + ' for ' + fieldLabel + '?')) go();
            return;
          }
          Swal.fire({
            title: (isOpening ? 'Open ' : 'Close ') + year + ' for ' + fieldLabel + '?',
            html,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#1E4D3B',
            cancelButtonColor: '#aaa',
            confirmButtonText: isOpening ? 'Open ' + year : 'Close ' + year,
            cancelButtonText: 'Cancel'
          }).then(r => { if (r.isConfirmed) go(); });
        });
      });

      // ── Post-change toast with a timed Undo ──────────────────────────────
      <?php if ($toast): ?>
      const toast = <?= json_encode($toast, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      const opts = { type: toast.type, title: toast.title, message: toast.message };
      if (toast.undo) {
        opts.duration = 8000;
        opts.action = {
          label: 'Undo',
          onClick: () => {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'school_year_settings';
            f.innerHTML = '<input type="hidden" name="undo_change" value="1">';
            document.body.appendChild(f);
            if (loader) loader.classList.add('show');
            f.submit();
          }
        };
      }
      uiToast(opts);
      <?php endif; ?>
    })();
  </script>

</body>
</html>
