<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);
$valid_quarters = ['1', '2', '3', '4'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Management — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
    <style>
      .gb-picker { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:1.25rem; }
      .gb-picker select {
        height:38px; border:1px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 12px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }
      .gb-picker select:focus { outline:none; border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,0.12); }

      .gm-card { overflow:hidden; }

      .gb-table-scroll { overflow-x:auto; border-radius:12px; border:1px solid #EAF3EE; }
      .gb-score-table { width:100%; border-collapse:collapse; font-size:13px; }
      .gb-score-table th, .gb-score-table td { padding:12px 14px; border-bottom:1px solid #EEF3F0; text-align:left; }
      .gm-table thead th {
        font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
        color:var(--brand-primary); background:var(--brand-tint); border-bottom:1px solid #DCEAE1;
      }
      .gm-table thead th:first-child { border-top-left-radius:12px; }
      .gm-table thead th:last-child { border-top-right-radius:12px; }
      .gm-table tbody tr:last-child td { border-bottom:none; }
      .gm-table tbody tr:hover { background:#FAFCFA; }
      .gm-table .td-name { font-weight:500; color:#1A1A2E; }
      .gm-group-start { border-left:2px solid #D4D4E0; }
      .gm-cat-badge {
        display:inline-block; font-size:9px; font-weight:700; letter-spacing:.03em;
        border-radius:4px; padding:1px 5px; margin-bottom:3px;
      }
      .gm-cat-badge-quiz { color:#2F6B4F; background:#EAF4EE; }
      .gm-cat-badge-seatwork { color:#2C5AA0; background:#EAF1FB; }
      .gm-cat-badge-assignment { color:#7B4FA0; background:#F3EDFB; }
      .gm-cat-badge-performance_task { color:#C06A10; background:#FFF4E6; }
      .gm-cat-badge-exam { color:#C0392B; background:#FBEAEA; }
      .gm-cat-badge-attendance { color:#5A5A72; background:#F5F5F7; }
      .gm-max { font-size:10px; color:#8A8A9A; display:block; }

      .gb-score-table input[type="number"] {
        width:76px; height:34px; border:1px solid #D9DEE0; border-radius:8px; padding:0 10px; font-size:13px; font-family:inherit;
        transition:border-color .15s, box-shadow .15s;
      }
      .gb-score-table input[type="number"]:focus {
        outline:none; border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,0.12);
      }
      .gb-score-table input.gb-saved { border-color:var(--brand-accent); background:var(--brand-tint); transition:background .3s, border-color .3s; }
      .gb-score-table input.gb-save-error { border-color:#C0392B; background:#FBEAEA; transition:background .3s, border-color .3s; }

      .gb-total-cell { font-weight:700; font-size:14px; color:var(--brand-primary); }

      .gm-remarks-pill {
        display:inline-block; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700;
        text-transform:uppercase; letter-spacing:.03em;
      }
      .gm-remarks-passed { background:var(--brand-tint); color:var(--brand-primary); }
      .gm-remarks-failed { background:#FBEAEA; color:#C0392B; }
      .gm-remarks-no_record { background:#F5F5F7; color:#5A5A72; }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';

    // Every distinct class (subject+section+school_year) this teacher is
    // assigned to — same source as Assessment/My Schedule.
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
    $sel_quarter = in_array($_GET['quarter'] ?? '', $valid_quarters, true) ? $_GET['quarter'] : '1';

    // A teacher can have separate Sem1/Sem2 assignments for the same
    // subject+section+school_year (different quarters) — match the one
    // whose semester actually covers the selected quarter.
    $current = null;
    foreach ($classes as $c) {
        if ((int) $c['subject_id'] === $sel_subject && (int) $c['section_id'] === $sel_section && $c['school_year'] === $sel_sy
            && (int) $c['semester'] === quarter_to_semester($sel_quarter)) {
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

    $roster = [];
    $quizItems = [];
    $seatworkItems = [];
    $assignmentItems = [];
    $performanceTaskItems = [];
    $examItems = [];
    $scoresByItemStudent = [];

    if ($current !== null) {
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

        // A student only belongs on this quarter's roster if they're
        // actually in the semester that quarter falls in — genuinely
        // (approved AND no outstanding accountabilities), not just by
        // status='enrolled'. Same live gate as My Classes/My Schedule.
        $roster = array_values(array_filter($roster, function ($s) use ($conn, $sel_quarter) {
            $isGenuinelySem2 = $s['semester2_status'] === 'approved'
                && !has_outstanding_accountabilities($conn, (int) $s['enrollment_id'], (int) $s['jhs_is_public']);
            return quarter_to_semester($sel_quarter) === 2 ? $isGenuinelySem2 : !$isGenuinelySem2;
        }));

        // Quiz/Seatwork/Assignment/Performance Task/Exam columns are
        // real gradebook_items — the exact same items Assessment
        // creates/manages. Classification matches
        // grade_management_grade() in config.php: is_quiz=1 is Quiz;
        // quarterly_assessment category (non-quiz) is Exam;
        // performance_task category (non-quiz) is Performance Task;
        // written_work + accepts_submission is Assignment (a file the
        // student uploads); written_work without submission is Seatwork
        // (done in class, manually scored).
        $it_stmt = $conn->prepare("
            SELECT item_id, title, max_score, is_quiz, category, accepts_submission
            FROM gradebook_items
            WHERE subject_id=? AND section_id=? AND school_year=? AND quarter=? AND teacher_id=?
            ORDER BY item_id
        ");
        $it_stmt->bind_param('iissi', $sel_subject, $sel_section, $sel_sy, $sel_quarter, $teacher_id);
        $it_stmt->execute();
        foreach ($it_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            if ($row['is_quiz']) {
                $quizItems[] = $row;
            } elseif ($row['category'] === 'quarterly_assessment') {
                $examItems[] = $row;
            } elseif ($row['category'] === 'performance_task') {
                $performanceTaskItems[] = $row;
            } elseif ($row['accepts_submission']) {
                $assignmentItems[] = $row;
            } else {
                $seatworkItems[] = $row;
            }
        }
        $it_stmt->close();

        $anyItems = !empty($quizItems) || !empty($seatworkItems) || !empty($assignmentItems) || !empty($performanceTaskItems) || !empty($examItems);
        if (!empty($roster) && $anyItems) {
            $sc_stmt = $conn->prepare("
                SELECT gs.item_id, gs.student_id, gs.raw_score
                FROM gradebook_scores gs
                JOIN gradebook_items gi ON gi.item_id = gs.item_id
                WHERE gi.subject_id=? AND gi.section_id=? AND gi.school_year=? AND gi.quarter=? AND gi.teacher_id=?
            ");
            $sc_stmt->bind_param('iissi', $sel_subject, $sel_section, $sel_sy, $sel_quarter, $teacher_id);
            $sc_stmt->execute();
            foreach ($sc_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $scoresByItemStudent[(int) $row['item_id']][(int) $row['student_id']] = $row['raw_score'];
            }
            $sc_stmt->close();
        }
    }

    function gm_fmt_max(float $max): string
    {
        return rtrim(rtrim(number_format($max, 2), '0'), '.');
    }
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Grade Management
          <span class="teacher-topbar-subtitle">Press Enter in a score field to save it</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <?php if (empty($classes)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>

        <div class="teacher-panel-block gm-card">
        <form method="GET" class="gb-picker">
          <?php
            // The class picker chooses subject+section+school_year only —
            // which semester applies is decided by the Quarter picker next
            // to it, so a teacher with the same class in both semesters
            // (a common case) sees it once here, not duplicated.
            $class_options = [];
            foreach ($classes as $c) {
                $key = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year'];
                if (!isset($class_options[$key])) { $class_options[$key] = $c; }
            }
          ?>
          <select name="class" onchange="var v=this.value.split('|'); location.href='teacher_grade_management?subject_id='+v[0]+'&section_id='+v[1]+'&sy='+encodeURIComponent(v[2])+'&quarter=<?= urlencode($sel_quarter) ?>';">
            <?php foreach ($class_options as $c): $val = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year']; ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= ((int)$c['subject_id']===$sel_subject && (int)$c['section_id']===$sel_section && $c['school_year']===$sel_sy) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['subject_name']) ?> — <?= htmlspecialchars($c['section_name']) ?> (SY <?= htmlspecialchars($c['school_year']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select name="quarter" onchange="location.href='teacher_grade_management?subject_id=<?= $sel_subject ?>&section_id=<?= $sel_section ?>&sy=<?= urlencode($sel_sy) ?>&quarter='+this.value;">
            <?php foreach ($valid_quarters as $q): ?>
              <option value="<?= $q ?>" <?= $sel_quarter === $q ? 'selected' : '' ?>>Quarter <?= $q ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($current === null): ?>
          <div class="notice notice-info">Select a class above to view its grades.</div>
        <?php elseif (empty($roster)): ?>
          <p class="empty-state">No students currently enrolled in this section.</p>
        <?php else: ?>

          <div class="gb-table-scroll">
            <table class="gb-score-table gm-table">
              <thead>
                <tr>
                  <th>Student</th>
                  <?php foreach ($quizItems as $i => $it): ?>
                    <th class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                      <span class="gm-cat-badge gm-cat-badge-quiz">QUIZ</span>
                      <?= htmlspecialchars($it['title']) ?><span class="gm-max">/ <?= gm_fmt_max((float)$it['max_score']) ?></span>
                    </th>
                  <?php endforeach; ?>
                  <?php foreach ($seatworkItems as $i => $it): ?>
                    <th class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                      <span class="gm-cat-badge gm-cat-badge-seatwork">SEATWORK</span>
                      <?= htmlspecialchars($it['title']) ?><span class="gm-max">/ <?= gm_fmt_max((float)$it['max_score']) ?></span>
                    </th>
                  <?php endforeach; ?>
                  <?php foreach ($assignmentItems as $i => $it): ?>
                    <th class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                      <span class="gm-cat-badge gm-cat-badge-assignment">ASSIGNMENT</span>
                      <?= htmlspecialchars($it['title']) ?><span class="gm-max">/ <?= gm_fmt_max((float)$it['max_score']) ?></span>
                    </th>
                  <?php endforeach; ?>
                  <?php foreach ($performanceTaskItems as $i => $it): ?>
                    <th class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                      <span class="gm-cat-badge gm-cat-badge-performance_task">PERFORMANCE TASK</span>
                      <?= htmlspecialchars($it['title']) ?><span class="gm-max">/ <?= gm_fmt_max((float)$it['max_score']) ?></span>
                    </th>
                  <?php endforeach; ?>
                  <?php foreach ($examItems as $i => $it): ?>
                    <th class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                      <span class="gm-cat-badge gm-cat-badge-exam">EXAM</span>
                      <?= htmlspecialchars($it['title']) ?><span class="gm-max">/ <?= gm_fmt_max((float)$it['max_score']) ?></span>
                    </th>
                  <?php endforeach; ?>
                  <th class="gm-group-start">
                    <span class="gm-cat-badge gm-cat-badge-attendance">ATTENDANCE</span>
                    <span class="gm-max">10%</span>
                  </th>
                  <th class="gm-group-start">Total (100%)</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($roster as $s):
                  $sid = (int) $s['student_id'];
                  $grade = grade_management_grade($conn, $sid, $sel_subject, $sel_section, $sel_sy, $sel_quarter);
                ?>
                  <tr data-student-row="<?= $sid ?>">
                    <td class="td-name"><?= htmlspecialchars($s['family_name'] . ', ' . $s['given_name']) ?></td>
                    <?php foreach ($quizItems as $i => $it):
                      $existing = $scoresByItemStudent[(int)$it['item_id']][$sid] ?? '';
                    ?>
                      <td class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                        <input type="number" min="0" max="<?= htmlspecialchars($it['max_score']) ?>" step="0.01"
                               class="gm-score-input" data-item-id="<?= (int)$it['item_id'] ?>" data-student-id="<?= $sid ?>"
                               value="<?= $existing === '' ? '' : htmlspecialchars($existing) ?>" title="Press Enter to save">
                      </td>
                    <?php endforeach; ?>
                    <?php foreach ($seatworkItems as $i => $it):
                      $existing = $scoresByItemStudent[(int)$it['item_id']][$sid] ?? '';
                    ?>
                      <td class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                        <input type="number" min="0" max="<?= htmlspecialchars($it['max_score']) ?>" step="0.01"
                               class="gm-score-input" data-item-id="<?= (int)$it['item_id'] ?>" data-student-id="<?= $sid ?>"
                               value="<?= $existing === '' ? '' : htmlspecialchars($existing) ?>" title="Press Enter to save">
                      </td>
                    <?php endforeach; ?>
                    <?php foreach ($assignmentItems as $i => $it):
                      $existing = $scoresByItemStudent[(int)$it['item_id']][$sid] ?? '';
                    ?>
                      <td class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                        <input type="number" min="0" max="<?= htmlspecialchars($it['max_score']) ?>" step="0.01"
                               class="gm-score-input" data-item-id="<?= (int)$it['item_id'] ?>" data-student-id="<?= $sid ?>"
                               value="<?= $existing === '' ? '' : htmlspecialchars($existing) ?>" title="Press Enter to save">
                      </td>
                    <?php endforeach; ?>
                    <?php foreach ($performanceTaskItems as $i => $it):
                      $existing = $scoresByItemStudent[(int)$it['item_id']][$sid] ?? '';
                    ?>
                      <td class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                        <input type="number" min="0" max="<?= htmlspecialchars($it['max_score']) ?>" step="0.01"
                               class="gm-score-input" data-item-id="<?= (int)$it['item_id'] ?>" data-student-id="<?= $sid ?>"
                               value="<?= $existing === '' ? '' : htmlspecialchars($existing) ?>" title="Press Enter to save">
                      </td>
                    <?php endforeach; ?>
                    <?php foreach ($examItems as $i => $it):
                      $existing = $scoresByItemStudent[(int)$it['item_id']][$sid] ?? '';
                    ?>
                      <td class="<?= $i === 0 ? 'gm-group-start' : '' ?>">
                        <input type="number" min="0" max="<?= htmlspecialchars($it['max_score']) ?>" step="0.01"
                               class="gm-score-input" data-item-id="<?= (int)$it['item_id'] ?>" data-student-id="<?= $sid ?>"
                               value="<?= $existing === '' ? '' : htmlspecialchars($existing) ?>" title="Press Enter to save">
                      </td>
                    <?php endforeach; ?>
                    <td class="gm-group-start" data-attendance-cell="<?= $sid ?>">
                      <?= $grade['attendance'] === null ? '<span class="empty-state" style="padding:0;">—</span>' : number_format($grade['attendance'], 1) . '%' ?>
                    </td>
                    <td class="gb-total-cell gm-group-start" data-total-cell="<?= $sid ?>"><?= $grade['total'] === null ? '—' : number_format($grade['total'], 2) ?></td>
                    <td>
                      <span class="gm-remarks-pill gm-remarks-<?= htmlspecialchars($grade['remarks']) ?>" data-remarks-cell="<?= $sid ?>">
                        <?= $grade['remarks'] === 'no_record' ? 'No Record Yet' : ucfirst($grade['remarks']) ?>
                      </span>
                    </td>
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
    (function () {
      var ctx = {
        subject_id: <?= json_encode($sel_subject) ?>,
        section_id: <?= json_encode($sel_section) ?>,
        school_year: <?= json_encode($sel_sy) ?>,
        quarter: <?= json_encode($sel_quarter) ?>
      };

      function applyResult(studentId, data) {
        var totalCell = document.querySelector('[data-total-cell="' + studentId + '"]');
        if (totalCell) totalCell.textContent = data.total === null ? '—' : Number(data.total).toFixed(2);
        var pill = document.querySelector('[data-remarks-cell="' + studentId + '"]');
        if (pill) {
          pill.className = 'gm-remarks-pill gm-remarks-' + data.remarks;
          pill.textContent = data.remarks === 'no_record' ? 'No Record Yet' : (data.remarks.charAt(0).toUpperCase() + data.remarks.slice(1));
        }
      }

      function wireAutosave(selector, endpoint, extraParams) {
        document.querySelectorAll(selector).forEach(function (input) {
          input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();

            var studentId = input.dataset.studentId;
            var body = new URLSearchParams();
            extraParams(input, body);
            body.set('student_id', studentId);
            body.set('subject_id', ctx.subject_id);
            body.set('section_id', ctx.section_id);
            body.set('school_year', ctx.school_year);
            body.set('quarter', ctx.quarter);
            body.set('value', input.value);

            input.disabled = true;
            input.classList.remove('gb-saved', 'gb-save-error');

            fetch(endpoint, { method: 'POST', body: body })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                input.disabled = false;
                if (data.success) {
                  input.value = data.value === null ? '' : data.value;
                  input.classList.add('gb-saved');
                  setTimeout(function () { input.classList.remove('gb-saved'); }, 800);
                  applyResult(studentId, data);
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
      }

      wireAutosave('.gm-score-input', '<?= APP_URL ?>/ajax/teacher_save_score', function (input, body) {
        body.set('item_id', input.dataset.itemId);
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
