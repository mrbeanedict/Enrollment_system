<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);

// PH SHS school-year convention (same as current_real_school_year() in
// config.php): "2026-2027" spans June 2026 - May 2027. Builds the list
// of selectable months (YYYY-MM => "Month YYYY (Quarter N)") for a
// given school year, with no admin setup required — attendance
// columns are generated directly from real calendar dates within
// whichever month is picked. The "(Quarter N)" suffix mirrors the
// fixed calendar convention config.php's quarter_calendar_range() uses
// to decide which quarter a month's attendance counts toward for
// Grade Management (Q1=Jun-Aug, Q2=Sep-Nov, Q3=Dec-Feb, Q4=Mar-May) —
// shown here so it's never a mystery which quarter a month belongs to.
function school_year_months(string $schoolYear): array
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m)) return [];
    $startYear = (int) $m[1];
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $ts = mktime(0, 0, 0, 6 + $i, 1, $startYear);
        $quarter = intdiv($i, 3) + 1;
        $months[date('Y-m', $ts)] = date('F Y', $ts) . " (Quarter $quarter)";
    }
    return $months;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
    <style>
      .gb-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .gb-picker select {
        height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 10px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }
      .gb-att-card { overflow:hidden; }
      .gb-table-scroll { overflow-x:auto; border-radius:12px; border:1px solid #EAF3EE; }
      .gb-score-table { width:100%; border-collapse:collapse; font-size:13px; }
      .gb-score-table th, .gb-score-table td { padding:10px 14px; border-bottom:1px solid #EEF3F0; text-align:left; }
      .gb-score-table th { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:#8A8A9A; }
      .gb-score-table input[type="text"] {
        width:120px; height:30px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 8px; font-size:12px; font-family:inherit;
      }
      .gb-score-table input.gb-saved { border-color:#2F6B4F; background:#EAF4EE; transition:background .3s, border-color .3s; }
      .gb-score-table input.gb-save-error { border-color:#C0392B; background:#FBEAEA; transition:background .3s, border-color .3s; }

      .gb-attendance-table thead th {
        white-space:nowrap; font-size:11px; font-weight:700; text-transform:none; letter-spacing:.02em;
        color:var(--brand-primary); background:var(--brand-tint); border-bottom:1px solid #DCEAE1;
      }
      .gb-attendance-table thead th:first-child { border-top-left-radius:12px; text-transform:uppercase; letter-spacing:.04em; font-size:10px; }
      .gb-attendance-table thead th:last-child { border-top-right-radius:12px; }
      .gb-attendance-table tbody tr:last-child td { border-bottom:none; }
      .gb-attendance-table tbody tr:hover { background:#FAFCFA; }
      .gb-attendance-table .td-name { font-weight:500; color:#1A1A2E; }
      .gb-attendance-table input[type="text"] {
        width:34px; height:30px; text-align:center; font-weight:700; text-transform:uppercase;
      }
      .gb-attendance-table input.gb-att-p { border-color:#A8D9C5; background:#EAF4EE; color:#1A6B4A; }
      .gb-attendance-table input.gb-att-l { border-color:#F0D9A8; background:#FFF4E6; color:#C06A10; }
      .gb-attendance-table input.gb-att-a { border-color:#F5C6C2; background:#FBEAEA; color:#C0392B; }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';

    // Every distinct class (subject+section+school_year) this teacher is
    // assigned to — same source as Grade Management/My Schedule.
    $cls_stmt = $conn->prepare("
        SELECT ta.subject_id, ta.section_id, ta.school_year, ta.semester, sub.subject_name,
               sec.section_name, sec.grade_level, st.strand_code AS strand
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.subject_id = ta.subject_id
        JOIN sections sec ON sec.section_id = ta.section_id
        JOIN strands st ON st.strand_id = sec.strand
        WHERE ta.teacher_id = ? AND ta.is_active = 1
        ORDER BY ta.school_year DESC, sec.grade_level, sec.section_name, sub.subject_name
    ");
    $cls_stmt->bind_param('i', $teacher_id);
    $cls_stmt->execute();
    $classes = $cls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cls_stmt->close();

    $sel_subject = (int) ($_GET['subject_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_sy      = $_GET['sy'] ?? '';

    $current = null;
    foreach ($classes as $c) {
        if ((int) $c['subject_id'] === $sel_subject && (int) $c['section_id'] === $sel_section && $c['school_year'] === $sel_sy) {
            $current = $c;
            break;
        }
    }
    // Nothing selected yet (or an invalid combo) — default to the first class.
    if ($current === null && !empty($classes)) {
        $current = $classes[0];
        $sel_subject = (int) $current['subject_id'];
        $sel_section = (int) $current['section_id'];
        $sel_sy      = $current['school_year'];
    }

    // Month picker — defaults to the real current month if it falls within
    // this class's school year, otherwise the school year's first month.
    $months = $sel_sy !== '' ? school_year_months($sel_sy) : [];
    $sel_month = $_GET['month'] ?? '';
    if (!isset($months[$sel_month])) {
        $nowKey = date('Y-m');
        $sel_month = isset($months[$nowKey]) ? $nowKey : (array_key_first($months) ?? '');
    }

    $roster = [];
    $attendanceByStudentDate = [];
    $sessionDates = [];

    if ($current !== null && $sel_month !== '') {
        $ros_stmt = $conn->prepare("
            SELECT s.student_id, s.family_name, s.given_name, se.is_public AS jhs_is_public, e.enrollment_id, e.semester2_status
            FROM enrollments e
            JOIN students s ON s.student_id = e.student_id
            LEFT JOIN student_education se ON se.student_id = s.student_id
            WHERE e.section_id = ? AND e.status = 'enrolled'
            ORDER BY s.family_name, s.given_name
        ");
        $ros_stmt->bind_param('i', $sel_section);
        $ros_stmt->execute();
        $roster = $ros_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ros_stmt->close();

        // A student only belongs on this month's roster if they're
        // actually in the semester that month falls in — genuinely
        // (approved AND no outstanding accountabilities), not just by
        // status='enrolled'. Same live gate as My Classes/Grade Management.
        $sel_semester = semester_for_date($sel_sy, $sel_month . '-01');
        $roster = array_values(array_filter($roster, function ($s) use ($conn, $sel_semester) {
            $isGenuinelySem2 = $s['semester2_status'] === 'approved'
                && !has_outstanding_accountabilities($conn, (int) $s['enrollment_id'], (int) $s['jhs_is_public']);
            return $sel_semester === 2 ? $isGenuinelySem2 : !$isGenuinelySem2;
        }));

        // This class's recurring scheduled weekday(s) — a class can have
        // more than one row (one per meeting day) for the same subject+section.
        $day_stmt = $conn->prepare("SELECT DISTINCT day FROM section_subjects WHERE section_id=? AND subject_id=?");
        $day_stmt->bind_param('ii', $sel_section, $sel_subject);
        $day_stmt->execute();
        $scheduledDays = array_column($day_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'day');
        $day_stmt->close();

        if (!empty($scheduledDays)) {
            $cursor = new DateTime($sel_month . '-01');
            $monthEnd = (clone $cursor)->modify('last day of this month');
            while ($cursor <= $monthEnd) {
                if (in_array($cursor->format('D'), $scheduledDays, true)) {
                    $sessionDates[] = $cursor->format('Y-m-d');
                }
                $cursor->modify('+1 day');
            }
        }

        if (!empty($roster) && !empty($sessionDates)) {
            $att_stmt = $conn->prepare("
                SELECT student_id, session_date, value FROM gradebook_attendance
                WHERE subject_id=? AND section_id=? AND school_year=? AND teacher_id=?
                  AND session_date BETWEEN ? AND ?
            ");
            $monthStartStr = $sel_month . '-01';
            $monthEndStr = (new DateTime($monthStartStr))->modify('last day of this month')->format('Y-m-d');
            $att_stmt->bind_param('iisiss', $sel_subject, $sel_section, $sel_sy, $teacher_id, $monthStartStr, $monthEndStr);
            $att_stmt->execute();
            foreach ($att_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $attendanceByStudentDate[$row['session_date']][(int) $row['student_id']] = $row['value'];
            }
            $att_stmt->close();
        }
    }
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Attendance
          <span class="teacher-topbar-subtitle">Press Enter in a field to save it</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <?php if (empty($classes)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>

        <?php
          // A teacher with the same subject+section in both semesters (a
          // common case) sees it once here — the Month picker next to it
          // is what actually determines which semester's dates show.
          $class_options = [];
          foreach ($classes as $c) {
              $key = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year'];
              if (!isset($class_options[$key])) { $class_options[$key] = $c; }
          }
        ?>
        <div class="teacher-panel-block gb-att-card">
        <form method="GET" class="gb-picker">
          <select name="class" onchange="var v=this.value.split('|'); location.href='teacher_attendance?subject_id='+v[0]+'&section_id='+v[1]+'&sy='+encodeURIComponent(v[2])+'&month=<?= urlencode($sel_month) ?>';">
            <?php foreach ($class_options as $c): $val = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year']; ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= ((int)$c['subject_id']===$sel_subject && (int)$c['section_id']===$sel_section && $c['school_year']===$sel_sy) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['subject_name']) ?> — <?= htmlspecialchars($c['section_name']) ?> (SY <?= htmlspecialchars($c['school_year']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select name="month" onchange="location.href='teacher_attendance?subject_id=<?= $sel_subject ?>&section_id=<?= $sel_section ?>&sy=<?= urlencode($sel_sy) ?>&month='+this.value;">
            <?php foreach ($months as $mKey => $mLabel): ?>
              <option value="<?= $mKey ?>" <?= $sel_month === $mKey ? 'selected' : '' ?>><?= $mLabel ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($current === null): ?>
          <div class="notice notice-info">Select a class above to view its attendance.</div>
        <?php elseif (empty($roster)): ?>
          <p class="empty-state">No students currently enrolled in this section.</p>
        <?php elseif (empty($sessionDates)): ?>
          <div class="notice notice-info">This class has no scheduled classes in <?= htmlspecialchars($months[$sel_month] ?? $sel_month) ?>.</div>
        <?php else: ?>

          <div class="gb-table-scroll">
            <table class="gb-score-table gb-attendance-table">
              <thead>
                <tr>
                  <th>Student</th>
                  <?php foreach ($sessionDates as $d): ?>
                    <th><?= date('D, M j', strtotime($d)) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($roster as $s): $sid = (int) $s['student_id']; ?>
                  <tr>
                    <td class="td-name"><?= htmlspecialchars($s['family_name'] . ', ' . $s['given_name']) ?></td>
                    <?php foreach ($sessionDates as $d):
                      $existing = $attendanceByStudentDate[$d][$sid] ?? '';
                    ?>
                      <td>
                        <input type="text" maxlength="1" class="gb-attendance-input gb-att-<?= strtolower($existing) ?>"
                               data-student-id="<?= $sid ?>" data-date="<?= $d ?>"
                               value="<?= htmlspecialchars($existing) ?>"
                               placeholder="—" title="P = Present, L = Late, A = Absent — press Enter to save">
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <script>
    // Per-cell autosave — press Enter in an attendance box to save just
    // that value via AJAX.
    (function () {
      var ctx = {
        subject_id: <?= json_encode($sel_subject) ?>,
        section_id: <?= json_encode($sel_section) ?>,
        school_year: <?= json_encode($sel_sy) ?>
      };

      function applyAttClass(input, value) {
        input.classList.remove('gb-att-p', 'gb-att-l', 'gb-att-a');
        var v = (value || '').toLowerCase();
        if (v === 'p' || v === 'l' || v === 'a') input.classList.add('gb-att-' + v);
      }

      document.querySelectorAll('.gb-attendance-input').forEach(function (input) {
        input.addEventListener('input', function () {
          input.value = input.value.toUpperCase();
        });

        input.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter') return;
          e.preventDefault();

          var val = input.value.trim().toUpperCase();
          if (val !== '' && val !== 'P' && val !== 'L' && val !== 'A') {
            input.classList.add('gb-save-error');
            input.title = 'Only P, L, or A is allowed.';
            return;
          }

          var body = new URLSearchParams();
          body.set('student_id', input.dataset.studentId);
          body.set('session_date', input.dataset.date);
          body.set('subject_id', ctx.subject_id);
          body.set('section_id', ctx.section_id);
          body.set('school_year', ctx.school_year);
          body.set('value', val);

          input.disabled = true;
          input.classList.remove('gb-saved', 'gb-save-error');

          fetch('../ajax/teacher_save_attendance', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              input.disabled = false;
              if (data.success) {
                input.value = data.value === null ? '' : data.value;
                applyAttClass(input, data.value);
                input.classList.add('gb-saved');
                setTimeout(function () { input.classList.remove('gb-saved'); }, 800);
              } else {
                input.classList.add('gb-save-error');
                input.title = data.error || 'Could not save.';
              }
            })
            .catch(function () {
              input.disabled = false;
              input.classList.add('gb-save-error');
              input.title = 'Could not save. Please try again.';
            });
        });
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
