<?php
session_name('TEACHER_SESSID');
session_start();
include_once '../config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../teacherportal/teacher_login");
    exit();
}
guard_password_change('teacher_change_password', 'teacher');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule — SHS Enrollment</title>
    <link rel="stylesheet" href="../assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_teacher.css') ?>">
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'teacher_sidebar.php';

    $teacher_id = (int) $_SESSION['teacher_id'];

    // Distinct school years this teacher has an assignment for, most
    // recent first — drives the school-year selector in the toolbar.
    $sy_list = [];
    $sy_stmt = mysqli_prepare($conn, "SELECT DISTINCT school_year FROM teacher_assignments WHERE teacher_id = ? AND is_active = 1 ORDER BY school_year DESC");
    mysqli_stmt_bind_param($sy_stmt, "i", $teacher_id);
    mysqli_stmt_execute($sy_stmt);
    $sy_res = mysqli_stmt_get_result($sy_stmt);
    while ($row = mysqli_fetch_assoc($sy_res)) { $sy_list[] = $row['school_year']; }
    mysqli_stmt_close($sy_stmt);

    $selected_sy = $_GET['sy'] ?? '';
    if (!in_array($selected_sy, $sy_list, true)) {
        $selected_sy = $sy_list[0] ?? '';
    }

    $selected_sem = (string) ($_GET['sem'] ?? '1');
    if (!in_array($selected_sem, ['1', '2'], true)) { $selected_sem = '1'; }

    // Semester 2 only shows a class once it genuinely has an enrolled
    // Semester 2 student — not merely because a Sem2 schedule slot was set
    // up — so a teacher never sees/prepares for a class that might still
    // change before anyone is actually in it. Accountabilities are
    // re-checked live here too (inlined, mirroring
    // has_outstanding_accountabilities()'s rule) rather than trusting
    // semester2_status alone, since a student can be workflow-approved yet
    // still have an outstanding document.
    $sem2_enrolled_clause = $selected_sem === '2'
        ? "AND EXISTS (
               SELECT 1 FROM enrollments e
               JOIN students st2 ON st2.student_id = e.student_id
               LEFT JOIN student_education se2 ON se2.student_id = st2.student_id
               WHERE e.section_id = ta.section_id AND e.school_year = ta.school_year
                 AND e.status = 'enrolled' AND e.semester2_status = 'approved'
                 AND NOT EXISTS (
                     SELECT 1 FROM enrollment_requirements er
                     JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                     WHERE er.enrollment_id = e.enrollment_id AND rt.is_active = 1
                       AND er.status != 'submitted'
                       AND (rt.applicable_to != 'public_jhs_only' OR se2.is_public = 1)
                 )
           )"
        : '';

    $sch_stmt = mysqli_prepare($conn, "
        SELECT sec.section_name, sec.grade_level, st.strand_code AS strand,
               sub.subject_name, ta.semester,
               ss.day, ss.start_time, ss.end_time,
               COALESCE(ss.room, sec.room) AS room
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.subject_id = ta.subject_id
        JOIN sections sec ON sec.section_id = ta.section_id
        JOIN strands st ON st.strand_id = sec.strand
        LEFT JOIN section_subjects ss
               ON ss.section_id = ta.section_id
              AND ss.subject_id = ta.subject_id
              AND ss.teacher_id = ta.teacher_id
              AND ss.semester = ta.semester
        WHERE ta.teacher_id = ? AND ta.is_active = 1 AND ta.school_year = ? AND ta.semester = ?
        $sem2_enrolled_clause
        ORDER BY FIELD(ss.day, 'Mon','Tue','Wed','Thu','Fri'), ss.start_time
    ");
    mysqli_stmt_bind_param($sch_stmt, "isi", $teacher_id, $selected_sy, $selected_sem);
    mysqli_stmt_execute($sch_stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($sch_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($sch_stmt);

    $scheduled   = array_values(array_filter($rows, fn($r) => $r['day'] && $r['start_time'] && $r['end_time']));
    $unscheduled = array_values(array_filter($rows, fn($r) => !$r['day'] || !$r['start_time'] || !$r['end_time']));

    // No "class type" column exists on section_subjects, so each subject is
    // bucketed into one of the three display categories deterministically
    // (by name) purely for consistent, repeatable colour-coding of the
    // grid — carries no curricular meaning. Same approach as
    // studentportal/student_schedule.php.
    $sch_types = ['lecture', 'discussion', 'practice'];
    foreach ($scheduled as &$sr) {
        $sr['type'] = $sch_types[crc32($sr['subject_name']) % 3];
    }
    unset($sr);

    $ico_calendar = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm3 8h2m4 0h2m-8 4h2m4 0h2"/></svg>';
    $ico_clock    = '<svg fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3.2 1.9"/></svg>';
    $ico_print    = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V4h12v5"/><rect x="4" y="9" width="16" height="8" rx="1.5"/><path stroke-linejoin="round" d="M6 14h12v6H6z"/></svg>';
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          My Schedule
          <span class="teacher-topbar-subtitle">Days, times, and rooms for your subjects</span>
        </div>
      </div>
      <?php include 'teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <?php if (empty($rows)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>

        <div class="sch-page-head">
          <div>
            <h1 class="sch-page-title">My Schedule</h1>
            <p class="sch-page-sub">S.Y. <?= htmlspecialchars($selected_sy) ?> — <?= $selected_sem === '2' ? '2nd' : '1st' ?> Semester</p>
          </div>
          <div class="sch-toolbar">
            <div class="sch-field-select">
              <select id="schSySelect" onchange="location.href='teacher_schedule?sy='+encodeURIComponent(this.value)+'&sem=<?= urlencode($selected_sem) ?>'" <?= count($sy_list) <= 1 ? 'disabled' : '' ?>>
                <?php foreach ($sy_list as $sy): ?>
                  <option value="<?= htmlspecialchars($sy) ?>" <?= $sy === $selected_sy ? 'selected' : '' ?>>S.Y. <?= htmlspecialchars($sy) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sch-field-select">
              <select id="schSemSelect" onchange="location.href='teacher_schedule?sy=<?= urlencode($selected_sy) ?>&sem='+encodeURIComponent(this.value)">
                <option value="1" <?= $selected_sem === '1' ? 'selected' : '' ?>>1st Semester</option>
                <option value="2" <?= $selected_sem === '2' ? 'selected' : '' ?>>2nd Semester</option>
              </select>
            </div>
            <button class="sch-btn-print" id="schPrintBtn" type="button"><?= $ico_print ?> Print / Export</button>
          </div>
        </div>

        <?php if (!empty($scheduled)): ?>
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

        <div class="teacher-panel-block">
          <div class="teacher-panel-header">
            <div class="teacher-panel-header-left">
              <span class="teacher-panel-icon"><?= $ico_calendar ?></span>
              <div>
                <div class="teacher-panel-title">Weekly Class Schedule</div>
              </div>
            </div>
          </div>
          <?php if (empty($scheduled)): ?>
            <p class="empty-state">No schedule has been set for your subjects yet.</p>
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

        <?php if (!empty($unscheduled)): ?>
          <div class="teacher-panel-block" style="margin-top:1rem;">
            <div class="teacher-panel-header">
              <div class="teacher-panel-header-left">
                <div class="teacher-panel-title">Not Yet Scheduled</div>
              </div>
            </div>
            <p class="field-hint" style="margin-bottom:.6rem;">These subjects don't have a day/time set yet — check with the Scheduler office.</p>
            <table class="data-table">
              <thead><tr><th>Subject</th><th>Section</th></tr></thead>
              <tbody>
                <?php foreach ($unscheduled as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['subject_name']) ?></td>
                    <td class="td-meta">
                      <?= htmlspecialchars($r['section_name']) ?>
                      — G<?= htmlspecialchars($r['grade_level']) ?> <?= htmlspecialchars($r['strand']) ?>
                      · Sem <?= (int)$r['semester'] ?>
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

  <?php if (!empty($scheduled)): ?>
  <script>
    const SCH_SCHEDULE = <?= json_encode(array_map(function ($r) {
        return [
            'day'     => $r['day'],
            'start'   => substr($r['start_time'], 0, 5),
            'end'     => substr($r['end_time'], 0, 5),
            'subject' => $r['subject_name'],
            'section' => $r['section_name'] . ' — G' . $r['grade_level'] . ' ' . $r['strand'] . ' · Sem ' . $r['semester'],
            'room'    => $r['room'],
            'type'    => $r['type'],
        ];
    }, $scheduled)) ?>;

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

    const schDayMapNum = {1:"Mon",2:"Tue",3:"Wed",4:"Thu",5:"Fri"};
    const schTodayCode = schDayMapNum[new Date().getDay()];
    document.querySelectorAll('.sch-table thead th[data-day]').forEach(th => {
      if (th.dataset.day === schTodayCode) th.classList.add('today');
    });

    const schSlots = Array.from(new Set(SCH_SCHEDULE.map(s => s.start))).sort();
    const schMatrix = schSlots.map(() => SCH_DAYS.map(() => undefined));

    SCH_SCHEDULE.forEach(item => {
      const colIndex = SCH_DAYS.indexOf(item.day);
      const rowStart = schSlots.indexOf(item.start);
      if (colIndex === -1 || rowStart === -1) return;

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
          block.querySelector('.c-meta').textContent = item.section;
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
