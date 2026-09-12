<?php
// Distinct cookie name keeps the student session independent from admin/staff (see student_login.php).
session_name('STUDENT_SESSID');
session_start();
include_once '../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule — SHS Enrollment</title>
    <link rel="stylesheet" href="../assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_student.css') ?>">
</head>
<body class="student-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'student_sidebar.php';

    $student_id = (int) $_SESSION['student_id'];

    // Distinct school years this student has an enrollment record for, most recent first —
    // drives the school-year selector in the toolbar below.
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

    $selected_sem = (string) ($_GET['sem'] ?? '1');
    if (!in_array($selected_sem, ['1', '2'], true)) {
        $selected_sem = '1';
    }

    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.section_id, st.strand_code AS strand, e.admission_grade_level AS grade_level, e.school_year,
               e.status, e.semester2_status, se.is_public AS jhs_is_public
        FROM enrollments e
        JOIN strands st ON st.strand_id = e.admission_strand
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
    // both finalized AND paid (status flips back to 'enrolled' only after
    // Treasury payment), AND accountabilities are still clear right now —
    // re-checked live rather than trusted from approval time, so a document
    // that later lapses (or was approved under an earlier, looser rule)
    // correctly withholds Semester 2 access again.
    $sem2_not_yet_enrolled = $selected_sem === '2' && !(
        $enrollment && $enrollment['status'] === 'enrolled' && $enrollment['semester2_status'] === 'approved'
        && !has_outstanding_accountabilities($conn, (int) $enrollment['enrollment_id'], (int) $enrollment['jhs_is_public'])
    );

    $section_name  = null;
    $schedule_rows = [];

    if ($enrollment && $enrollment['section_id']) {
        $sec_stmt = mysqli_prepare($conn, "SELECT section_name FROM sections WHERE section_id = ?");
        mysqli_stmt_bind_param($sec_stmt, "i", $enrollment['section_id']);
        mysqli_stmt_execute($sec_stmt);
        mysqli_stmt_bind_result($sec_stmt, $section_name);
        mysqli_stmt_fetch($sec_stmt);
        mysqli_stmt_close($sec_stmt);
    }

    if ($enrollment && $enrollment['section_id'] && !$sem2_not_yet_enrolled) {
        $sched_stmt = mysqli_prepare($conn, "
            SELECT sub.subject_name, ss.day, ss.start_time, ss.end_time, ss.room, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
            WHERE ss.section_id = ? AND ss.semester = ?
            ORDER BY FIELD(ss.day, 'Mon','Tue','Wed','Thu','Fri'), ss.start_time
        ");
        mysqli_stmt_bind_param($sched_stmt, "ii", $enrollment['section_id'], $selected_sem);
        mysqli_stmt_execute($sched_stmt);
        $schedule_rows = mysqli_fetch_all(mysqli_stmt_get_result($sched_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($sched_stmt);
    }

    // No "class type" column exists on section_subjects, so each subject is bucketed
    // into one of the three display categories deterministically (by name) purely for
    // consistent, repeatable colour-coding of the grid — it carries no curricular meaning.
    $sch_types = ['lecture', 'discussion', 'practice'];
    foreach ($schedule_rows as &$sr) {
        $sr['type'] = $sch_types[crc32($sr['subject_name']) % 3];
    }
    unset($sr);

    $ico_calendar = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm3 8h2m4 0h2m-8 4h2m4 0h2"/></svg>';
    $ico_clock    = '<svg fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3.2 1.9"/></svg>';
    $ico_print    = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V4h12v5"/><rect x="4" y="9" width="16" height="8" rx="1.5"/><path stroke-linejoin="round" d="M6 14h12v6H6z"/></svg>';
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          My Schedule
          <span class="student-topbar-subtitle">Subjects &amp; weekly class schedule</span>
        </div>
      </div>
      <?php include 'student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <?php if (!$enrollment): ?>
        <div class="notice notice-info">No enrollment record found yet.</div>
      <?php elseif (!$enrollment['section_id']): ?>
        <div class="notice notice-info">You haven't been assigned to a section yet — check back once enrollment is finalized.</div>
      <?php else: ?>

        <div class="sch-page-head">
          <div>
            <h1 class="sch-page-title">My Schedule</h1>
            <p class="sch-page-sub"><?= htmlspecialchars('Grade ' . $enrollment['grade_level'] . ' — ' . $enrollment['strand'] . ' — ' . ($section_name ?? '') . ' — S.Y. ' . $enrollment['school_year']) ?></p>
          </div>
          <div class="sch-toolbar">
            <div class="sch-field-select">
              <select id="schSySelect" onchange="location.href='student_schedule?sy='+encodeURIComponent(this.value)+'&sem=<?= urlencode($selected_sem) ?>'" <?= count($sy_list) <= 1 ? 'disabled' : '' ?>>
                <?php foreach ($sy_list as $sy): ?>
                  <option value="<?= htmlspecialchars($sy) ?>" <?= $sy === $selected_sy ? 'selected' : '' ?>>S.Y. <?= htmlspecialchars($sy) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sch-field-select">
              <select id="schSemSelect" onchange="location.href='student_schedule?sy=<?= urlencode($selected_sy) ?>&sem='+encodeURIComponent(this.value)">
                <option value="1" <?= $selected_sem === '1' ? 'selected' : '' ?>>1st Semester</option>
                <option value="2" <?= $selected_sem === '2' ? 'selected' : '' ?>>2nd Semester</option>
              </select>
            </div>
            <button class="sch-btn-print" id="schPrintBtn"><?= $ico_print ?> Print / Export</button>
          </div>
        </div>

        <?php if (!empty($schedule_rows)): ?>
        <div class="sch-next-card" id="schNextCard">
          <div class="sch-next-icon"><?= $ico_clock ?></div>
          <div>
            <div class="sch-next-label" id="schNextLabel">Next class</div>
            <div class="sch-next-subject" id="schNextSubject">—</div>
            <div class="sch-next-time" id="schNextTime">—</div>
          </div>
          <div class="sch-next-pill" id="schNextPill">—</div>
        </div>

        <div class="sch-legend">
          <div class="sch-legend-item"><span class="sch-legend-swatch sch-sw-lecture"></span>Specialized</div>
          <div class="sch-legend-item"><span class="sch-legend-swatch sch-sw-discussion"></span>Applied</div>
          <div class="sch-legend-item"><span class="sch-legend-swatch sch-sw-practice"></span>Core</div>
        </div>
        <?php endif; ?>

        <div class="student-panel-block">
          <div class="student-panel-header">
            <div class="student-panel-header-left">
              <span class="student-panel-icon"><?= $ico_calendar ?></span>
              <div>
                <div class="student-panel-title">Weekly Class Schedule</div>
                <div class="student-panel-sub"><?= htmlspecialchars(($section_name ?? '') . ' — ' . ($selected_sem === '2' ? '2nd' : '1st') . ' Semester') ?></div>
              </div>
            </div>
          </div>
          <?php if ($sem2_not_yet_enrolled): ?>
            <p class="empty-state">You're not yet enrolled in Semester 2 for this school year.</p>
          <?php elseif (empty($schedule_rows)): ?>
            <p class="empty-state">No schedule available for the <?= $selected_sem === '2' ? '2nd' : '1st' ?> Semester yet.</p>
          <?php else: ?>
            <div class="sch-table-wrap">
              <table class="sch-table">
                <colgroup>
                  <col class="sch-col-time">
                  <col><col><col><col><col>
                </colgroup>
                <thead>
                  <tr>
                    <th class="corner"></th>
                    <th data-day="Mon">Monday</th>
                    <th data-day="Tue">Tuesday</th>
                    <th data-day="Wed">Wednesday</th>
                    <th data-day="Thu">Thursday</th>
                    <th data-day="Fri">Friday</th>
                  </tr>
                </thead>
                <tbody id="schScheduleBody">
                  <!-- rows injected by JS -->
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>

    </div>
  </div>

  <?php if (!empty($schedule_rows)): ?>
  <script>
    const SCH_SCHEDULE = <?= json_encode(array_map(function ($r) {
        return [
            'day'     => $r['day'],
            'start'   => substr($r['start_time'], 0, 5),
            'end'     => substr($r['end_time'], 0, 5),
            'subject' => $r['subject_name'],
            'teacher' => $r['teacher_name'] ?: '—',
            'room'    => $r['room'],
            'type'    => $r['type'],
        ];
    }, $schedule_rows)) ?>;

    const SCH_DAYS = ["Mon","Tue","Wed","Thu","Fri"];

    function schMinutes(hhmm) {
      const [h, m] = hhmm.split(':').map(Number);
      return h * 60 + m;
    }
    function schFmtTime(hhmm) {
      const [h, m] = hhmm.split(':').map(Number);
      const period = h >= 12 ? 'PM' : 'AM';
      let hh = h % 12; if (hh === 0) hh = 12;
      return hh + ':' + String(m).padStart(2, '0') + ' ' + period;
    }
    function schFmtRange(start, end) { return schFmtTime(start) + ' – ' + schFmtTime(end); }

    // mark today's column header
    const schDayMapNum = {1:"Mon",2:"Tue",3:"Wed",4:"Thu",5:"Fri"};
    const schTodayCode = schDayMapNum[new Date().getDay()];
    document.querySelectorAll('.sch-table thead th[data-day]').forEach(th => {
      if (th.dataset.day === schTodayCode) th.classList.add('today');
    });

    // ---- build weekly grid rows from the distinct start times actually in use ----
    const schSlots = Array.from(new Set(SCH_SCHEDULE.map(s => s.start))).sort();

    // occupancy matrix: rows = schSlots, cols = SCH_DAYS
    const schMatrix = schSlots.map(() => SCH_DAYS.map(() => undefined));

    SCH_SCHEDULE.forEach(item => {
      const colIndex = SCH_DAYS.indexOf(item.day);
      const rowStart = schSlots.indexOf(item.start);
      if (colIndex === -1 || rowStart === -1) return;

      // span forward while the next slot boundary starts before this class ends
      let rowSpan = 1;
      for (let r = rowStart + 1; r < schSlots.length && schMinutes(schSlots[r]) < schMinutes(item.end); r++) {
        rowSpan++;
      }

      schMatrix[rowStart][colIndex] = { start: true, item, rowSpan };
      for (let r = rowStart + 1; r < rowStart + rowSpan && r < schSlots.length; r++) {
        schMatrix[r][colIndex] = { skip: true };
      }
    });

    const schTbody = document.getElementById('schScheduleBody');

    schSlots.forEach((slot, rowIdx) => {
      const tr = document.createElement('tr');

      const timeTd = document.createElement('td');
      timeTd.className = 'sch-time-col';
      timeTd.textContent = schFmtTime(slot);
      tr.appendChild(timeTd);

      SCH_DAYS.forEach((day, colIdx) => {
        const cell = schMatrix[rowIdx][colIdx];
        if (cell && cell.skip) return;

        const td = document.createElement('td');

        if (cell && cell.start) {
          const item = cell.item;
          td.className = 'sch-class-cell';
          if (cell.rowSpan > 1) td.rowSpan = cell.rowSpan;

          const block = document.createElement('div');
          block.className = 'sch-class-block sch-type-' + item.type;
          let html = `<div class="c-time">${schFmtRange(item.start, item.end)}</div>`;
          html += `<div class="c-subject"></div>`;
          html += `<div class="c-meta"></div>`;
          block.innerHTML = html;
          block.querySelector('.c-subject').textContent = item.subject;
          block.querySelector('.c-meta').textContent = item.teacher;
          if (item.room) {
            const roomEl = document.createElement('span');
            roomEl.className = 'sch-room-override';
            roomEl.textContent = item.room;
            block.appendChild(roomEl);
          }
          td.appendChild(block);
        } else {
          td.className = 'sch-empty-cell';
        }

        tr.appendChild(td);
      });

      schTbody.appendChild(tr);
    });

    // ---- live "next / current class" banner ----
    function schUpdateNextClass() {
      const now = new Date();
      const dowMap = {1:"Mon",2:"Tue",3:"Wed",4:"Thu",5:"Fri"};
      const nowMinutesToday = now.getHours() * 60 + now.getMinutes();
      const todayCode = dowMap[now.getDay()];

      let current = null, next = null;

      if (todayCode) {
        current = SCH_SCHEDULE.find(s => s.day === todayCode && nowMinutesToday >= schMinutes(s.start) && nowMinutesToday < schMinutes(s.end));
      }

      if (!current) {
        const order = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
        const todayPos = order.indexOf(todayCode !== undefined ? todayCode : "Sun");
        for (let offset = 0; offset < 8 && !next; offset++) {
          const dayCode = order[(todayPos + offset) % 7];
          const candidates = SCH_SCHEDULE.filter(s => s.day === dayCode)
            .filter(s => offset > 0 || schMinutes(s.start) > nowMinutesToday)
            .sort((a, b) => schMinutes(a.start) - schMinutes(b.start));
          if (candidates.length) next = candidates[0];
        }
      }

      const labelEl = document.getElementById('schNextLabel');
      const subjEl  = document.getElementById('schNextSubject');
      const timeEl  = document.getElementById('schNextTime');
      const pillEl  = document.getElementById('schNextPill');
      if (!labelEl) return;

      if (current) {
        labelEl.textContent = "Ongoing class";
        subjEl.textContent = current.subject;
        timeEl.textContent = schFmtRange(current.start, current.end);
        pillEl.textContent = "Now";
      } else if (next) {
        labelEl.textContent = "Next class";
        subjEl.textContent = next.subject;
        timeEl.textContent = next.day + " · " + schFmtRange(next.start, next.end);
        pillEl.textContent = "Upcoming";
      } else {
        labelEl.textContent = "Next class";
        subjEl.textContent = "No classes scheduled";
        timeEl.textContent = "—";
        pillEl.textContent = "";
      }
    }
    schUpdateNextClass();
    setInterval(schUpdateNextClass, 60000);

    document.getElementById('schPrintBtn').addEventListener('click', () => window.print());
  </script>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
