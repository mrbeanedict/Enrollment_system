<?php
/* ============================================================
   _schedule_lib.php — shared class-schedule logic
   ------------------------------------------------------------
   Used by scheduler/scheduling.php (the builder), scheduler/sections.php
   (auto-generated first schedule) and coordinator/approvals.php (review).

   Data model:
     section_subjects          one row per subject per section per semester;
                               holds the TEACHER (and a mirror of the first
                               meeting's day/time/room for older readers)
     section_subject_meetings  every weekly class meeting of that subject —
                               a subject can meet several times a week, at
                               most once per day (schema/section_subject_meetings.sql)

   Clash rules (per semester):
     - a teacher can't be in two meetings at once, across sections;
     - a room (meeting override, else the section homeroom) can't host two
       meetings at once, across sections;
     - inside one section no two meetings may overlap (students attend all).

   Semester 1 uses the original sections.schedule_* columns and the "SCH-"
   request prefix; Semester 2 uses the *_sem2 shadow columns and "SCH2-".
   ============================================================ */

const SCHED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

function sched_col(string $base, int $semester): string {
    return $semester === 2 ? $base . '_sem2' : $base;
}

// 07:00:00 … 17:00:00 in 30-minute steps — the school-day grid. Existing
// schedules use both :00 blocks (7–9, 9–11) and :30 blocks (7:30–8:30 …),
// so the grid has to allow either.
function sched_time_bounds(): array {
    $b = [];
    for ($m = 7 * 60; $m <= 17 * 60; $m += 30) $b[] = sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    return $b;
}

function sched_time_label(?string $t): string {
    return $t ? date('g:i A', strtotime($t)) : '';
}

function sched_minutes(string $t): int {
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

// "Mon 7:00–9:00 AM" style one-liner for a list of meetings (COR, printouts…).
function sched_meetings_text(array $meetings): string {
    return implode(', ', array_map(fn($m) => $m['day'] . ' ' . sched_time_label($m['start_time']) . '–' . sched_time_label($m['end_time']), $meetings));
}

// "11-3" -> "ABM 11-3", "Stem 12-5" -> "STEM 12-5" (display only).
function sched_section_label(string $name, ?string $strand): string {
    if (!$strand) return $name;
    if (preg_match('/^' . preg_quote($strand, '/') . '\b/i', $name)) return $strand . substr($name, strlen($strand));
    if (preg_match('/^\d{2}\b/', $name)) return $strand . ' ' . $name;
    return $name;
}

/**
 * First teacher or room clash for one meeting, against every OTHER section's
 * meetings in the same semester (overlaps inside the section are checked by
 * sched_own_overlap()). $room is the effective room (override or homeroom).
 * Returns null or ['kind' => 'teacher'|'room', 'text' => …].
 */
function sched_find_clash(mysqli $conn, int $section_id, int $sem, ?int $teacher_id, ?string $room,
                          string $day, string $start, string $end, bool $lock = false): ?array {
    $tail = $lock ? ' FOR UPDATE' : ' LIMIT 1';
    $base = "SELECT sec2.section_name, st2.strand_code, sub2.subject_name
             FROM section_subject_meetings m2
             JOIN section_subjects ss2 ON ss2.section_id = m2.section_id AND ss2.subject_id = m2.subject_id AND ss2.semester = m2.semester
             JOIN sections sec2 ON sec2.section_id = m2.section_id
             JOIN strands st2   ON st2.strand_id  = sec2.strand
             JOIN subjects sub2 ON sub2.subject_id = m2.subject_id
             WHERE %s AND m2.day = ? AND m2.semester = ? AND m2.section_id <> ?
               AND m2.start_time < ? AND m2.end_time > ?" . $tail;

    if ($teacher_id) {
        $st = $conn->prepare(sprintf($base, 'ss2.teacher_id = ?'));
        $st->bind_param('isiiss', $teacher_id, $day, $sem, $section_id, $end, $start);
        $st->execute();
        $x = $st->get_result()->fetch_assoc();
        $st->close();
        if ($x) {
            return ['kind' => 'teacher', 'text' => 'This teacher is already teaching "' . $x['subject_name'] . '" in '
                . sched_section_label($x['section_name'], $x['strand_code']) . ' at that time.'];
        }
    }
    if ($room !== null && trim($room) !== '') {
        $st = $conn->prepare(sprintf($base, "COALESCE(NULLIF(m2.room, ''), sec2.room) = ?"));
        $st->bind_param('ssiiss', $room, $day, $sem, $section_id, $end, $start);
        $st->execute();
        $x = $st->get_result()->fetch_assoc();
        $st->close();
        if ($x) {
            return ['kind' => 'room', 'text' => 'Room ' . $room . ' is already booked for "' . $x['subject_name'] . '" in '
                . sched_section_label($x['section_name'], $x['strand_code']) . ' at that time.'];
        }
    }
    return null;
}

/**
 * First pair of meetings inside one section that overlap. $subjects: list of
 * ['name' => …, 'meetings' => [['day','start_time','end_time'], …]].
 * Returns null or ['a' => subject index, 'b' => subject index, 'text' => …].
 */
function sched_own_overlap(array $subjects): ?array {
    $flat = [];
    foreach (array_values($subjects) as $i => $s) {
        foreach ($s['meetings'] as $m) $flat[] = [$i, $s['name'], $m['day'], $m['start_time'], $m['end_time']];
    }
    $n = count($flat);
    for ($a = 0; $a < $n; $a++) {
        for ($b = $a + 1; $b < $n; $b++) {
            [$ia, $na, $da, $sa, $ea] = $flat[$a];
            [$ib, $nb, $db, $sb, $eb] = $flat[$b];
            if ($da === $db && $sa < $eb && $sb < $ea) {
                $text = $ia === $ib ? "\"$na\" has two classes overlapping on $da."
                                    : "\"$na\" and \"$nb\" overlap on $da.";
                return ['a' => $ia, 'b' => $ib, 'text' => $text];
            }
        }
    }
    return null;
}

/**
 * Keeps teacher_assignments (what the teacher portal reads) in step with
 * section_subjects.teacher_id. No unique key on that table, so clear then
 * insert. Notifies the teacher only when the teacher actually changed.
 */
function sched_sync_teacher_assignment(mysqli $conn, int $section_id, int $subject_id, string $school_year, int $sem,
                                       ?int $teacher_id, string $section_name, string $subject_name): void {
    $st = $conn->prepare("SELECT teacher_id FROM teacher_assignments WHERE subject_id = ? AND section_id = ? AND school_year = ? AND semester = ?");
    $st->bind_param('iisi', $subject_id, $section_id, $school_year, $sem);
    $st->execute();
    $prev = (int)($st->get_result()->fetch_assoc()['teacher_id'] ?? 0);
    $st->close();

    $st = $conn->prepare("DELETE FROM teacher_assignments WHERE subject_id = ? AND section_id = ? AND school_year = ? AND semester = ?");
    $st->bind_param('iisi', $subject_id, $section_id, $school_year, $sem);
    $st->execute();
    $st->close();

    if (!$teacher_id) return;

    $st = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, subject_id, section_id, school_year, semester) VALUES (?, ?, ?, ?, ?)");
    $st->bind_param('iiisi', $teacher_id, $subject_id, $section_id, $school_year, $sem);
    $st->execute();
    $st->close();

    if ($teacher_id !== $prev) {
        $st = $conn->prepare("SELECT user_id FROM teachers WHERE teacher_id = ?");
        $st->bind_param('i', $teacher_id);
        $st->execute();
        $uid = (int)($st->get_result()->fetch_assoc()['user_id'] ?? 0);
        $st->close();
        notify_teacher_users($conn, [$uid], "You've been assigned to teach \"$subject_name\" — $section_name.", 'roles/teacher/teacher_schedule', 'schedule');
    }
}

// Section header row for one semester (null if missing).
function sched_fetch_section(mysqli $conn, int $section_id, int $sem): ?array {
    $c = fn($b) => sched_col($b, $sem);
    $st = $conn->prepare("
        SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.room, sec.school_year,
               sec.is_active, sec.approval_status,
               sec.{$c('schedule_status')} AS schedule_status, sec.{$c('schedule_request_id')} AS schedule_request_id,
               sec.{$c('schedule_rejection_reason')} AS schedule_rejection_reason
        FROM sections sec JOIN strands st ON st.strand_id = sec.strand
        WHERE sec.section_id = ?");
    $st->bind_param('i', $section_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

// Every meeting of a section in one semester, grouped by subject_id and
// ordered Mon→Fri, then by start time.
function sched_fetch_meetings(mysqli $conn, int $section_id, int $sem): array {
    $st = $conn->prepare("
        SELECT subject_id, day, start_time, end_time, room
        FROM section_subject_meetings
        WHERE section_id = ? AND semester = ?
        ORDER BY FIELD(day, 'Mon','Tue','Wed','Thu','Fri'), start_time");
    $st->bind_param('ii', $section_id, $sem);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $m) {
        $out[(int)$m['subject_id']][] = ['day' => $m['day'], 'start_time' => $m['start_time'], 'end_time' => $m['end_time'], 'room' => $m['room'] ?: null];
    }
    $st->close();
    return $out;
}

// The section's subjects for one semester (by name), each with its meetings.
function sched_fetch_rows(mysqli $conn, int $section_id, int $sem): array {
    $st = $conn->prepare("
        SELECT ss.subject_id, sub.subject_name, ss.teacher_id
        FROM section_subjects ss JOIN subjects sub ON sub.subject_id = ss.subject_id
        WHERE ss.section_id = ? AND ss.semester = ?
        ORDER BY sub.subject_name");
    $st->bind_param('ii', $section_id, $sem);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $meetings = sched_fetch_meetings($conn, $section_id, $sem);
    foreach ($rows as &$r) $r['meetings'] = $meetings[(int)$r['subject_id']] ?? [];
    return $rows;
}

// Sort meetings Mon→Fri, then by start time.
function sched_sort_meetings(array $ms): array {
    usort($ms, fn($a, $b) => [array_search($a['day'], SCHED_DAYS), $a['start_time']] <=> [array_search($b['day'], SCHED_DAYS), $b['start_time']]);
    return $ms;
}

/**
 * Replaces one subject's meetings (inside the caller's transaction) and
 * mirrors the first meeting onto section_subjects' legacy columns.
 */
function sched_write_meetings(mysqli $conn, int $section_id, int $subject_id, int $sem, array $meetings): void {
    $st = $conn->prepare("DELETE FROM section_subject_meetings WHERE section_id = ? AND subject_id = ? AND semester = ?");
    $st->bind_param('iii', $section_id, $subject_id, $sem);
    $st->execute();
    $st->close();

    $meetings = sched_sort_meetings($meetings);
    $ins = $conn->prepare("INSERT INTO section_subject_meetings (section_id, subject_id, semester, day, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($meetings as $m) {
        $room = $m['room'] ?? null;
        $ins->bind_param('iiissss', $section_id, $subject_id, $sem, $m['day'], $m['start_time'], $m['end_time'], $room);
        $ins->execute();
    }
    $ins->close();

    $f = $meetings[0] ?? ['day' => null, 'start_time' => null, 'end_time' => null, 'room' => null];
    $st = $conn->prepare("UPDATE section_subjects SET day = ?, start_time = ?, end_time = ?, room = ? WHERE section_id = ? AND subject_id = ? AND semester = ?");
    $room = $f['room'] ?? null;
    $st->bind_param('ssssiii', $f['day'], $f['start_time'], $f['end_time'], $room, $section_id, $subject_id, $sem);
    $st->execute();
    $st->close();
}

/**
 * Saves a whole section's schedule for one semester in ONE transaction —
 * all or nothing. $subjects: list of
 *   ['subject_id', 'teacher_id' (int|null), 'meetings' => [['day','start_time','end_time','room'], …]]
 * A subject may have no meetings yet (draft). Semester 2 subject rows are
 * inserted on first save.
 *
 * Returns ['ok' => true, 'changed' => bool, 'reverted' => bool]
 *      or ['ok' => false, 'error' => string, 'row' => int|null]  (row = subject index)
 */
function sched_save_slots(mysqli $conn, int $section_id, int $sem, array $subjects, int $user_id): array {
    $sec = sched_fetch_section($conn, $section_id, $sem);
    if (!$sec) return ['ok' => false, 'error' => 'Section not found.', 'row' => null];
    if (!in_array($sec['schedule_status'], ['unscheduled', 'rejected', 'approved'], true)) {
        return ['ok' => false, 'error' => 'This schedule is locked while it waits for coordinator review.', 'row' => null];
    }

    $current = [];
    foreach (sched_fetch_rows($conn, $section_id, $sem) as $r) $current[(int)$r['subject_id']] = $r;
    $insert_mode = !$current;

    // Which subjects may be saved: existing rows, or (first-time Sem 2) the Sem 1 subjects.
    if ($insert_mode) {
        if ($sem !== 2) return ['ok' => false, 'error' => 'This section has no subjects yet — the section must be approved first.', 'row' => null];
        $allowed = [];
        foreach (sched_fetch_rows($conn, $section_id, 1) as $r) $allowed[(int)$r['subject_id']] = $r['subject_name'];
    } else {
        $allowed = array_map(fn($r) => $r['subject_name'], $current);
    }

    $bounds = sched_time_bounds();
    $clean  = [];
    foreach (array_values($subjects) as $i => $s) {
        $sid  = (int)($s['subject_id'] ?? 0);
        $name = $allowed[$sid] ?? null;
        if ($name === null) return ['ok' => false, 'error' => 'A subject in this schedule doesn\'t belong to the section. Reload and try again.', 'row' => $i];
        if (isset($clean[$sid])) return ['ok' => false, 'error' => "\"$name\" appears twice.", 'row' => $i];
        $tid = ($s['teacher_id'] ?? '') !== '' && $s['teacher_id'] !== null ? (int)$s['teacher_id'] : null;

        $ms = []; $days = [];
        foreach ((array)($s['meetings'] ?? []) as $m) {
            $day = (string)($m['day'] ?? ''); $start = (string)($m['start_time'] ?? ''); $end = (string)($m['end_time'] ?? '');
            $room = trim((string)($m['room'] ?? ''));
            if ($day === '' || $start === '' || $end === '') return ['ok' => false, 'error' => "\"$name\": every class needs a day, start and end time — or remove it.", 'row' => $i];
            if (!in_array($day, SCHED_DAYS, true)) return ['ok' => false, 'error' => "\"$name\": invalid day.", 'row' => $i];
            if (!in_array($start, $bounds, true) || !in_array($end, $bounds, true)) return ['ok' => false, 'error' => "\"$name\": times must be between 7:00 AM and 5:00 PM, on the half hour.", 'row' => $i];
            if ($start >= $end) return ['ok' => false, 'error' => "\"$name\": a class must end after it starts.", 'row' => $i];
            if (isset($days[$day])) return ['ok' => false, 'error' => "\"$name\" already has a class on $day — one class per day.", 'row' => $i];
            if (mb_strlen($room) > 50) return ['ok' => false, 'error' => "\"$name\": room name is too long.", 'row' => $i];
            $days[$day] = true;
            $ms[] = ['day' => $day, 'start_time' => $start, 'end_time' => $end, 'room' => $room !== '' ? $room : null];
        }
        $clean[$sid] = ['i' => $i, 'name' => $name, 'teacher_id' => $tid, 'meetings' => sched_sort_meetings($ms)];
    }
    if (!$clean) return ['ok' => false, 'error' => 'Nothing to save.', 'row' => null];

    // Two of this section's own classes at once — students can't attend both.
    $own = sched_own_overlap(array_values($clean));
    if ($own) return ['ok' => false, 'error' => $own['text'], 'row' => array_values($clean)[$own['b']]['i']];

    // Nothing changed? Don't touch anything (and don't un-approve an approved schedule).
    if (!$insert_mode && count($clean) === count($current)) {
        $same = true;
        foreach ($clean as $sid => $c) {
            $o = $current[$sid] ?? null;
            $om = $o ? array_map(fn($m) => [$m['day'], $m['start_time'], $m['end_time'], $m['room']], sched_sort_meetings($o['meetings'])) : null;
            $cm = array_map(fn($m) => [$m['day'], $m['start_time'], $m['end_time'], $m['room']], $c['meetings']);
            if (!$o || (int)$o['teacher_id'] !== (int)$c['teacher_id'] || $om !== $cm) { $same = false; break; }
        }
        if ($same) return ['ok' => true, 'changed' => false, 'reverted' => false];
    }

    $conn->begin_transaction();
    try {
        if ($insert_mode) {
            $st = $conn->prepare("INSERT INTO section_subjects (section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, ?)");
            foreach ($clean as $sid => $c) {
                $st->bind_param('iiii', $section_id, $sid, $c['teacher_id'], $sem);
                $st->execute();
            }
            $st->close();
        }

        foreach ($clean as $sid => $c) {
            foreach ($c['meetings'] as $m) {
                $clash = sched_find_clash($conn, $section_id, $sem, $c['teacher_id'], $m['room'] ?? $sec['room'],
                                          $m['day'], $m['start_time'], $m['end_time'], true);
                if ($clash) {
                    $conn->rollback();
                    return ['ok' => false, 'error' => "\"{$c['name']}\" ({$m['day']} " . sched_time_label($m['start_time']) . "): {$clash['text']}", 'row' => $c['i']];
                }
            }
            $st = $conn->prepare("UPDATE section_subjects SET teacher_id = ? WHERE section_id = ? AND subject_id = ? AND semester = ?");
            $st->bind_param('iiii', $c['teacher_id'], $section_id, $sid, $sem);
            $st->execute();
            $st->close();

            sched_write_meetings($conn, $section_id, $sid, $sem, $c['meetings']);
            sched_sync_teacher_assignment($conn, $section_id, $sid, $sec['school_year'], $sem, $c['teacher_id'], $sec['section_name'], $c['name']);
        }

        // Editing an approved schedule sends it back for re-review.
        $reverted = $sec['schedule_status'] === 'approved';
        if ($reverted) {
            $c = fn($b) => sched_col($b, $sem);
            $st = $conn->prepare("UPDATE sections SET {$c('schedule_status')}='pending', {$c('schedule_submitted_by')}=?, {$c('schedule_submitted_at')}=NOW(),
                                  {$c('schedule_reviewed_by')}=NULL, {$c('schedule_reviewed_at')}=NULL, {$c('schedule_rejection_reason')}=NULL
                                  WHERE section_id=?");
            $st->bind_param('ii', $user_id, $section_id);
            $st->execute();
            $st->close();
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Could not save the schedule. Please try again.', 'row' => null];
    }

    if ($reverted) {
        notify_coordinators($conn, "Schedule for section \"{$sec['section_name']}\" (Semester $sem) was edited and needs re-approval.",
                            'coordinator/approvals?type=schedule' . ($sem === 2 ? '&sem=2' : ''));
    }
    return ['ok' => true, 'changed' => true, 'reverted' => $reverted];
}

/**
 * Submits a complete, clash-free schedule for coordinator approval. The
 * request ID is generated once and kept across reject/resubmit cycles.
 * Returns ['ok' => bool, 'error' => string].
 */
function sched_submit(mysqli $conn, int $section_id, int $sem, int $user_id): array {
    $sec = sched_fetch_section($conn, $section_id, $sem);
    if (!$sec) return ['ok' => false, 'error' => 'Section not found.'];
    if (!in_array($sec['schedule_status'], ['unscheduled', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'This schedule has already been submitted or approved.'];
    }
    $rows = sched_fetch_rows($conn, $section_id, $sem);
    if (!$rows) return ['ok' => false, 'error' => 'This section has no subjects to schedule yet.'];

    $incomplete = count(array_filter($rows, fn($r) => !$r['teacher_id'] || !$r['meetings']));
    if ($incomplete) {
        return ['ok' => false, 'error' => "$incomplete of " . count($rows) . ' subjects still need a teacher and at least one class before you can submit.'];
    }
    $own = sched_own_overlap(array_map(fn($r) => ['name' => $r['subject_name'], 'meetings' => $r['meetings']], $rows));
    if ($own) return ['ok' => false, 'error' => $own['text'] . ' Fix it before submitting.'];
    foreach ($rows as $r) {
        foreach ($r['meetings'] as $m) {
            $clash = sched_find_clash($conn, $section_id, $sem, (int)$r['teacher_id'], $m['room'] ?: $sec['room'], $m['day'], $m['start_time'], $m['end_time']);
            if ($clash) return ['ok' => false, 'error' => "\"{$r['subject_name']}\" ({$m['day']}): {$clash['text']} Fix it before submitting."];
        }
    }

    $c = fn($b) => sched_col($b, $sem);
    $request_id = $sec['schedule_request_id'];
    $conn->begin_transaction();
    if (!$request_id) {
        $prefix = ($sem === 2 ? 'SCH2-' : 'SCH-') . date('Y') . '-';
        $like   = $prefix . '%';
        $st = $conn->prepare("SELECT {$c('schedule_request_id')} AS rid FROM sections WHERE {$c('schedule_request_id')} LIKE ?
                              ORDER BY {$c('schedule_request_id')} DESC LIMIT 1 FOR UPDATE");
        $st->bind_param('s', $like);
        $st->execute();
        $last = $st->get_result()->fetch_assoc();
        $st->close();
        $parts = $last ? explode('-', $last['rid']) : [0];
        $request_id = $prefix . str_pad((string)(((int)end($parts)) + 1), 4, '0', STR_PAD_LEFT);
    }
    $st = $conn->prepare("UPDATE sections SET {$c('schedule_request_id')}=?, {$c('schedule_status')}='pending', {$c('schedule_submitted_by')}=?,
                          {$c('schedule_submitted_at')}=NOW(), {$c('schedule_rejection_reason')}=NULL
                          WHERE section_id=? AND {$c('schedule_status')} IN ('unscheduled','rejected')");
    $st->bind_param('sii', $request_id, $user_id, $section_id);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    if (!$ok) { $conn->rollback(); return ['ok' => false, 'error' => 'Could not submit — it may have just been submitted by someone else.']; }
    $conn->commit();

    notify_coordinators($conn, "Schedule for section \"{$sec['section_name']}\" (Semester $sem) awaiting approval.",
                        'coordinator/approvals?type=schedule' . ($sem === 2 ? '&sem=2' : ''));
    return ['ok' => true, 'error' => ''];
}

/**
 * Every OTHER section's meetings in a semester, for live clash hints in the
 * builder: [teacher_id|null, room|null, day, start_min, end_min, label].
 */
function sched_busy_map(mysqli $conn, int $sem, int $exclude_section_id): array {
    $st = $conn->prepare("
        SELECT ss.teacher_id, COALESCE(NULLIF(m.room, ''), sec.room) AS room, m.day, m.start_time, m.end_time,
               sec.section_name, st.strand_code, sub.subject_name
        FROM section_subject_meetings m
        JOIN section_subjects ss ON ss.section_id = m.section_id AND ss.subject_id = m.subject_id AND ss.semester = m.semester
        JOIN sections sec ON sec.section_id = m.section_id
        JOIN strands st   ON st.strand_id = sec.strand
        JOIN subjects sub ON sub.subject_id = m.subject_id
        WHERE m.semester = ? AND m.section_id <> ?");
    $st->bind_param('ii', $sem, $exclude_section_id);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[] = [$r['teacher_id'] ? (int)$r['teacher_id'] : null, $r['room'] ?: null, $r['day'],
                  sched_minutes($r['start_time']), sched_minutes($r['end_time']),
                  $r['subject_name'] . ' · ' . sched_section_label($r['section_name'], $r['strand_code'])];
    }
    $st->close();
    return $out;
}
