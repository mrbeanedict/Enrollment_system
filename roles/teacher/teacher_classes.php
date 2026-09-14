<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

// Redirect non-teacher roles before any HTML output — see teacher_dashboard.php
// for why this can't just live inside teacher_sidebar.php's own guard.
require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';

    $teacher_id = (int) $_SESSION['teacher_id'];

    $selected_sem = (string) ($_GET['sem'] ?? '1');
    if (!in_array($selected_sem, ['1', '2'], true)) { $selected_sem = '1'; }

    // Same assignment query as the dashboard, plus the actual roster per
    // section — this page is the "drill in" from the dashboard's summary.
    // Scoped to one semester at a time, same convention as My Schedule.
    $asn_stmt = mysqli_prepare($conn, "
        SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.room AS homeroom,
               sub.subject_id, sub.subject_name,
               ss.day, ss.start_time, ss.end_time, ss.room AS room_override,
               ta.school_year
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.subject_id = ta.subject_id
        JOIN sections sec ON sec.section_id = ta.section_id
        JOIN strands st ON st.strand_id = sec.strand
        LEFT JOIN section_subjects ss
               ON ss.section_id = ta.section_id
              AND ss.subject_id = ta.subject_id
              AND ss.teacher_id = ta.teacher_id
              AND ss.semester = ta.semester
        WHERE ta.teacher_id = ? AND ta.is_active = 1 AND ta.semester = ?
        ORDER BY sec.grade_level, sec.section_name, sub.subject_name
    ");
    mysqli_stmt_bind_param($asn_stmt, "ii", $teacher_id, $selected_sem);
    mysqli_stmt_execute($asn_stmt);
    $assignments = mysqli_fetch_all(mysqli_stmt_get_result($asn_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($asn_stmt);

    $classes = [];
    foreach ($assignments as $row) {
        $sid = $row['section_id'];
        if (!isset($classes[$sid])) {
            $classes[$sid] = [
                'section_name' => $row['section_name'],
                'grade_level'  => $row['grade_level'],
                'strand'       => $row['strand'],
                'homeroom'     => $row['homeroom'],
                'subjects'     => [],
            ];
        }
        $classes[$sid]['subjects'][] = $row['subject_name'];
    }

    // Roster per section — only fetched for sections this teacher actually
    // teaches, same one-query-per-row pattern already used elsewhere in
    // this app (e.g. records/document_review.php's per-applicant detail).
    // Semester 2 additionally requires the same live gate My Schedule uses
    // (approved AND no outstanding accountabilities) — a student who's
    // only enrolled in Semester 1, or approved-but-still-holding on a
    // document, must not appear as one of this class's Semester 2 students.
    foreach ($classes as $sid => &$cls) {
        if ($selected_sem === '2') {
            $ros_stmt = mysqli_prepare($conn, "
                SELECT s.student_id, s.family_name, s.given_name, s.middle_name, s.suffix, se.is_public AS jhs_is_public, e.status, e.enrollment_id
                FROM enrollments e
                JOIN students s ON s.student_id = e.student_id
                LEFT JOIN student_education se ON se.student_id = s.student_id
                WHERE e.section_id = ? AND e.status = 'enrolled' AND e.semester2_status = 'approved'
                ORDER BY s.family_name ASC, s.given_name ASC
            ");
        } else {
            $ros_stmt = mysqli_prepare($conn, "
                SELECT s.student_id, s.family_name, s.given_name, s.middle_name, s.suffix, se.is_public AS jhs_is_public, e.status, e.enrollment_id, e.semester2_status
                FROM enrollments e
                JOIN students s ON s.student_id = e.student_id
                LEFT JOIN student_education se ON se.student_id = s.student_id
                WHERE e.section_id = ? AND e.status = 'enrolled'
                ORDER BY s.family_name ASC, s.given_name ASC
            ");
        }
        mysqli_stmt_bind_param($ros_stmt, "i", $sid);
        mysqli_stmt_execute($ros_stmt);
        $roster = mysqli_fetch_all(mysqli_stmt_get_result($ros_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($ros_stmt);

        if ($selected_sem === '2') {
            $roster = array_values(array_filter($roster, function ($s) use ($conn) {
                return !has_outstanding_accountabilities($conn, (int) $s['enrollment_id'], (int) $s['jhs_is_public']);
            }));
        } else {
            // A student already genuinely in Semester 2 (approved AND no
            // outstanding accountabilities — the same live gate, inverted)
            // has moved on from Semester 1, so they no longer belong on
            // this roster. Still-holding Sem2-approved students stay here,
            // since they haven't actually moved on yet.
            $roster = array_values(array_filter($roster, function ($s) use ($conn) {
                $isGenuinelySem2 = $s['semester2_status'] === 'approved'
                    && !has_outstanding_accountabilities($conn, (int) $s['enrollment_id'], (int) $s['jhs_is_public']);
                return !$isGenuinelySem2;
            }));
        }
        $cls['roster'] = $roster;
    }
    unset($cls);
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          My Classes
          <span class="teacher-topbar-subtitle">Sections and subjects you teach</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <div class="sch-toolbar" style="margin-bottom:1rem;">
        <div class="sch-field-select">
          <select id="classesSemSelect" onchange="location.href='teacher_classes?sem='+encodeURIComponent(this.value)">
            <option value="1" <?= $selected_sem === '1' ? 'selected' : '' ?>>1st Semester</option>
            <option value="2" <?= $selected_sem === '2' ? 'selected' : '' ?>>2nd Semester</option>
          </select>
        </div>
      </div>

      <?php if (empty($classes)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>
        <?php foreach ($classes as $cls): ?>
          <div class="teacher-panel-block" style="margin-bottom:1rem;">
            <div class="teacher-panel-header">
              <div class="teacher-panel-header-left">
                <div>
                  <div class="teacher-panel-title">
                    <?= htmlspecialchars($cls['section_name']) ?>
                    <span class="field-hint">— Grade <?= htmlspecialchars($cls['grade_level']) ?> <?= htmlspecialchars($cls['strand']) ?></span>
                  </div>
                  <div class="teacher-panel-sub">
                    <?= htmlspecialchars(implode(', ', $cls['subjects'])) ?>
                    <?= $cls['homeroom'] ? ' &middot; Room ' . htmlspecialchars($cls['homeroom']) : '' ?>
                  </div>
                </div>
              </div>
            </div>

            <?php if (empty($cls['roster'])): ?>
              <p class="empty-state">No students currently enrolled in this section.</p>
            <?php else: ?>
              <table class="data-table" style="margin-top:.5rem;">
                <thead>
                  <tr><th>Student</th><th>Status</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($cls['roster'] as $s):
                    $full = trim($s['family_name'] . ', ' . $s['given_name']
                        . ($s['middle_name'] ? ' ' . $s['middle_name'] : '')
                        . ($s['suffix'] ? ' ' . $s['suffix'] : ''));
                  ?>
                    <tr>
                      <td class="td-name"><?= htmlspecialchars($full) ?></td>
                      <td><span class="badge badge-enrolled">Enrolled</span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
