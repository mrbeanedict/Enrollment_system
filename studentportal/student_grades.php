<?php
// My Grades belongs to the Student Portal (see student_sidebar.php) —
// always use that session, fixed, rather than guessing between
// STUDENT_SESSID and STUDENT_LMS_SESSID. A student can be validly
// signed into both at once; this page must never flip to LMS branding
// just because an unrelated LMS session also happens to be alive.
session_name('STUDENT_SESSID');
session_start();
$is_lms_mode = false;
include_once '../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades — SHS Enrollment</title>
    <link rel="stylesheet" href="../assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_student.css') ?>">
    <style>
      .grd-picker { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:1.1rem; }
      .grd-picker select {
        height:37px; border:1px solid var(--color-info); border-radius:9px; background:#FBFDFC;
        padding:0 30px 0 12px; font-size:13px; font-weight:600; font-family:inherit; color:var(--color-dark);
        appearance:none; cursor:pointer;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23386641' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right 9px center; background-size:14px;
        transition:border-color .15s, box-shadow .15s;
      }
      .grd-picker select:hover  { border-color: var(--color-primary); }
      .grd-picker select:focus { outline:none; border-color: var(--color-primary); box-shadow:0 0 0 3px rgba(56,102,65,0.14); }

      .grd-table-wrap { overflow-x:auto; border:1px solid var(--color-info); border-radius:14px; }
      .grd-table { width:100%; min-width:560px; border-collapse:collapse; font-size:13px; }
      .grd-table th, .grd-table td { padding:12px 14px; text-align:left; }
      .grd-table th:not(:first-child), .grd-table td:not(:first-child) { text-align:center; }

      .grd-table thead th {
        background: var(--color-primary); color: var(--color-accent);
        font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
        padding:13px 14px; white-space:nowrap;
      }
      .grd-table thead th:first-child { border-top-left-radius:13px; }
      .grd-table thead th:last-child  { border-top-right-radius:13px; }

      .grd-table tbody tr { border-bottom:1px solid #EEF3F1; transition:background-color .12s; }
      .grd-table tbody tr:last-child { border-bottom:none; }
      .grd-table tbody tr:nth-child(even) { background: rgba(202, 222, 222, 0.18); }
      .grd-table tbody tr:hover { background: rgba(56, 102, 65, 0.07); }

      .grd-table .td-name { font-weight:700; color:var(--color-dark); }

      .grd-grade { font-weight:700; font-size:15px; color:var(--color-dark); }
      .grd-remarks {
        display:inline-block; padding:4px 12px; border-radius:999px;
        font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em;
      }
      .grd-remarks-passed   { background: rgba(56, 102, 65, 0.13); color: var(--color-primary-active); }
      .grd-remarks-failed   { background:#FBEAEA; color:#C0392B; }
      .grd-remarks-enrolled { background: var(--color-info); color:#3E5C5C; }
      .grd-none { color:#9FADAD; font-size:12px; font-style:italic; }
    </style>
</head>
<body class="student-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="<?= $is_lms_mode ? 'lms_login' : 'student_login' ?>">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once $is_lms_mode ? 'lms_sidebar.php' : 'student_sidebar.php';

    $student_id = (int) $_SESSION['student_id'];

    // Same school-year selector convention as student_schedule.php.
    $sy_list = [];
    $sy_stmt = mysqli_prepare($conn, "SELECT DISTINCT school_year FROM enrollments WHERE student_id = ? ORDER BY school_year DESC");
    mysqli_stmt_bind_param($sy_stmt, "i", $student_id);
    mysqli_stmt_execute($sy_stmt);
    $sy_res = mysqli_stmt_get_result($sy_stmt);
    while ($row = mysqli_fetch_assoc($sy_res)) { $sy_list[] = $row['school_year']; }
    mysqli_stmt_close($sy_stmt);

    $selected_sy = $_GET['sy'] ?? '';
    if (!in_array($selected_sy, $sy_list, true)) {
        $selected_sy = $sy_list[0] ?? '';
    }

    // Quarters 1-2 fall in semester 1, quarters 3-4 in semester 2 (standard
    // DepEd SHS convention) — both quarters of the selected semester are
    // shown side-by-side per subject, no separate quarter picker needed.
    $selected_sem = (string) ($_GET['sem'] ?? '1');
    if (!in_array($selected_sem, ['1', '2'], true)) $selected_sem = '1';
    $quarters_for_sem = $selected_sem === '1' ? ['1', '2'] : ['3', '4'];

    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.section_id, e.status, e.semester2_status, se.is_public AS jhs_is_public
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN student_education se ON se.student_id = s.student_id
        WHERE e.student_id = ? AND e.school_year = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "is", $student_id, $selected_sy);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    // Semester 2 only actually exists once the registrar's wizard pass is
    // both finalized AND paid, AND accountabilities are still clear right
    // now (re-checked live) — see studentportal/student_schedule.php for
    // the same gate and reasoning.
    $sem2_not_yet_enrolled = $selected_sem === '2' && !(
        $enrollment && $enrollment['status'] === 'enrolled' && $enrollment['semester2_status'] === 'approved'
        && !has_outstanding_accountabilities($conn, (int) $enrollment['enrollment_id'], (int) $enrollment['jhs_is_public'])
    );

    $grade_rows = [];
    if ($enrollment && $enrollment['section_id'] && !$sem2_not_yet_enrolled) {
        $sec_id = (int) $enrollment['section_id'];
        $subj_stmt = mysqli_prepare($conn, "
            SELECT DISTINCT sub.subject_id, sub.subject_name
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            WHERE ss.section_id = ? AND ss.semester = ?
            ORDER BY sub.subject_name
        ");
        mysqli_stmt_bind_param($subj_stmt, "ii", $sec_id, $selected_sem);
        mysqli_stmt_execute($subj_stmt);
        $subjects = mysqli_fetch_all(mysqli_stmt_get_result($subj_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($subj_stmt);

        foreach ($subjects as $sub) {
            $subject_id = (int) $sub['subject_id'];

            // Quarter 1 and Quarter 2 (or 3/4 for semester 2) each show
            // their own column, plus one Final Grade combining both —
            // same "average both quarters" convention already used by
            // semester1_failing_subjects() in config.php. A final grade
            // only exists once BOTH quarters have a real total; otherwise
            // the student is simply still "Enrolled" (in progress), not
            // failing or missing anything.
            $q1 = grade_management_grade($conn, $student_id, $subject_id, $sec_id, $selected_sy, $quarters_for_sem[0]);
            $q2 = grade_management_grade($conn, $student_id, $subject_id, $sec_id, $selected_sy, $quarters_for_sem[1]);

            if ($q1['total'] !== null && $q2['total'] !== null) {
                $final = round(($q1['total'] + $q2['total']) / 2, 2);
                $status = $final >= 75 ? 'passed' : 'failed';
            } else {
                $final = null;
                $status = 'enrolled';
            }

            $grade_rows[] = [
                'subject_name' => $sub['subject_name'],
                'q1'           => $q1['total'],
                'q2'           => $q2['total'],
                'final'        => $final,
                'status'       => $status,
            ];
        }
    }
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          My Grades
          <span class="student-topbar-subtitle">Your final grade per subject</span>
        </div>
      </div>
      <?php include 'student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg></span>
            <div>
              <div class="student-panel-title">Grades</div>
              <div class="student-panel-sub"><?= $selected_sy ? htmlspecialchars('SY ' . $selected_sy . ' — Semester ' . $selected_sem) : 'No school year on file yet' ?></div>
            </div>
          </div>
        </div>

        <?php if (empty($sy_list)): ?>
          <p class="empty-state">No enrollment record found yet.</p>
        <?php else: ?>
          <form method="GET" class="grd-picker">
            <select name="sy" onchange="this.form.submit()">
              <?php foreach ($sy_list as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $selected_sy === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="sem" onchange="this.form.submit()">
              <option value="1" <?= $selected_sem === '1' ? 'selected' : '' ?>>Semester 1</option>
              <option value="2" <?= $selected_sem === '2' ? 'selected' : '' ?>>Semester 2</option>
            </select>
          </form>

          <?php if ($sem2_not_yet_enrolled): ?>
            <p class="empty-state">You're not yet enrolled in Semester 2 for this school year.</p>
          <?php elseif (empty($grade_rows)): ?>
            <p class="empty-state">No subjects found for this semester.</p>
          <?php else: ?>
            <div class="grd-table-wrap">
            <table class="grd-table">
              <thead>
                <tr>
                  <th>Subject</th>
                  <th>Quarter <?= htmlspecialchars($quarters_for_sem[0]) ?></th>
                  <th>Quarter <?= htmlspecialchars($quarters_for_sem[1]) ?></th>
                  <th>Final Grade</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($grade_rows as $r): ?>
                  <tr>
                    <td class="td-name"><?= htmlspecialchars($r['subject_name']) ?></td>
                    <td>
                      <?php if ($r['q1'] === null): ?>
                        <span class="grd-none">Not yet graded</span>
                      <?php else: ?>
                        <span class="grd-grade"><?= htmlspecialchars(number_format($r['q1'], 2)) ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($r['q2'] === null): ?>
                        <span class="grd-none">Not yet graded</span>
                      <?php else: ?>
                        <span class="grd-grade"><?= htmlspecialchars(number_format($r['q2'], 2)) ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($r['final'] === null): ?>
                        <span class="grd-none">—</span>
                      <?php else: ?>
                        <span class="grd-grade"><?= htmlspecialchars(number_format($r['final'], 2)) ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="grd-remarks grd-remarks-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
