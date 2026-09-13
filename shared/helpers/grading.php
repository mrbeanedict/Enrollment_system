<?php
// Semester 1 = Quarters 1+2. Subjects are whole-year in this system
// (not semester-split — see roles/staff/registrar/enrollment.php), so this checks
// every subject the section offers. Only subjects where BOTH quarters
// already have a real Grade Management total are evaluated — a subject
// with no grades yet has nothing to average, so it's silently skipped
// rather than treated as failing. 75 is DepEd's passing grade. Uses
// grade_management_grade() (Quiz/Seatwork/Exam) rather than the old
// Assessment-based gradebook_quarterly_grade(), since Assessment no
// longer collects per-student scores itself — Grade Management is the
// only place real grades get entered now.
function semester1_failing_subjects(mysqli $conn, int $studentId, int $sectionId, string $schoolYear): array
{
    $passingGrade = 75.0;

    $stmt = $conn->prepare("
        SELECT DISTINCT sub.subject_id, sub.subject_name
        FROM section_subjects ss
        JOIN subjects sub ON sub.subject_id = ss.subject_id
        WHERE ss.section_id = ?
        ORDER BY sub.subject_name
    ");
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $failing = [];
    foreach ($subjects as $s) {
        $subjectId = (int) $s['subject_id'];
        $q1 = grade_management_grade($conn, $studentId, $subjectId, $sectionId, $schoolYear, '1')['total'];
        $q2 = grade_management_grade($conn, $studentId, $subjectId, $sectionId, $schoolYear, '2')['total'];
        if ($q1 === null || $q2 === null) continue;

        $average = round(($q1 + $q2) / 2, 2);
        if ($average < $passingGrade) {
            $failing[] = ['subject_name' => $s['subject_name'], 'average' => $average];
        }
    }
    return $failing;
}

// Grade Management's Total — Attendance 10% / Quizzes 10% / Seatwork
// 10% / Assignment 10% / Performance Task 20% / Exam 40%, independent
// of the DepEd Written Work/Performance Task/Quarterly Assessment
// weights used elsewhere in this file.
//
// The 5 item-based groups are real gradebook_items (the exact same
// items/scores Assessment manages), classified as:
//   is_quiz=1                                        -> Quizzes
//   category='quarterly_assessment' (non-quiz)        -> Exam
//   category='performance_task' (non-quiz)            -> Performance Task
//   category='written_work', accepts_submission=1     -> Assignment
//   category='written_work', accepts_submission=0     -> Seatwork
// Each group's percent is sum-of-points-earned / sum-of-points-possible
// across that group's scored items — so a perfect group always caps at
// exactly its own weight, never more (no additive-per-item overflow).
//
// Attendance has no quarter of its own (gradebook_attendance only
// carries a raw session_date — see quarter_calendar_range() in
// functions.php) — its percent is the average of that quarter's P/L/A
// entries, scored Present=100, Late=95 (a flat 5-point deduction),
// Absent=0.
//
// Shared by teacher_grade_management.php, ajax/teacher_save_score.php
// (so a save recomputes the identical Total/Remarks), and
// student_grades.php. Returns remarks='no_record' only when
// absolutely nothing has been entered anywhere for this student yet.
function grade_management_grade(mysqli $conn, int $studentId, int $subjectId, int $sectionId, string $schoolYear, string $quarter): array
{
    $stmt = $conn->prepare("
        SELECT
          CASE WHEN gi.is_quiz = 1 THEN 'quiz'
               WHEN gi.category = 'quarterly_assessment' THEN 'exam'
               WHEN gi.category = 'performance_task' THEN 'performance_task'
               WHEN gi.accepts_submission = 1 THEN 'assignment'
               ELSE 'seatwork' END AS grp,
          SUM(gs.raw_score) AS earned,
          SUM(gi.max_score) AS possible
        FROM gradebook_items gi
        JOIN gradebook_scores gs ON gs.item_id = gi.item_id AND gs.student_id = ?
        WHERE gi.subject_id = ? AND gi.section_id = ? AND gi.school_year = ? AND gi.quarter = ?
          AND gs.raw_score IS NOT NULL
        GROUP BY grp
    ");
    $stmt->bind_param('iiiss', $studentId, $subjectId, $sectionId, $schoolYear, $quarter);
    $stmt->execute();
    $byGroup = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $possible = (float) $row['possible'];
        $byGroup[$row['grp']] = $possible > 0 ? ((float) $row['earned'] / $possible) * 100 : null;
    }
    $stmt->close();

    $quizPct   = $byGroup['quiz']              ?? null;
    $seatPct   = $byGroup['seatwork']           ?? null;
    $assignPct = $byGroup['assignment']         ?? null;
    $ptPct     = $byGroup['performance_task']   ?? null;
    $examPct   = $byGroup['exam']               ?? null;

    // Whether the CLASS has any items in each of the 5 buckets at all
    // (student-independent) — a category with items that simply
    // haven't been scored yet for this one student still counts as 0
    // below; only a category with zero items for the whole class gets
    // excluded and has its weight redistributed to the others.
    $exists_stmt = $conn->prepare("
        SELECT DISTINCT
          CASE WHEN gi.is_quiz = 1 THEN 'quiz'
               WHEN gi.category = 'quarterly_assessment' THEN 'exam'
               WHEN gi.category = 'performance_task' THEN 'performance_task'
               WHEN gi.accepts_submission = 1 THEN 'assignment'
               ELSE 'seatwork' END AS grp
        FROM gradebook_items gi
        WHERE gi.subject_id = ? AND gi.section_id = ? AND gi.school_year = ? AND gi.quarter = ?
    ");
    $exists_stmt->bind_param('iiss', $subjectId, $sectionId, $schoolYear, $quarter);
    $exists_stmt->execute();
    $classHas = array_column($exists_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'grp');
    $exists_stmt->close();

    [$rangeStart, $rangeEnd] = quarter_calendar_range($schoolYear, $quarter);
    $attPct = null;
    if ($rangeStart !== null) {
        $att_stmt = $conn->prepare("
            SELECT value, COUNT(*) AS c FROM gradebook_attendance
            WHERE subject_id=? AND section_id=? AND school_year=? AND student_id=?
              AND session_date BETWEEN ? AND ?
            GROUP BY value
        ");
        $att_stmt->bind_param('iisiss', $subjectId, $sectionId, $schoolYear, $studentId, $rangeStart, $rangeEnd);
        $att_stmt->execute();
        $attRows = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $att_stmt->close();

        $valueScore = ['P' => 100.0, 'L' => 95.0, 'A' => 0.0];
        $totalCount = 0;
        $totalScore = 0.0;
        foreach ($attRows as $row) {
            $score = $valueScore[$row['value']] ?? null;
            if ($score === null) continue;
            $c = (int) $row['c'];
            $totalScore += $score * $c;
            $totalCount += $c;
        }
        if ($totalCount > 0) {
            $attPct = $totalScore / $totalCount;
        }
    }

    // Sub-percentages are exposed too (not just total/remarks) so
    // teacher_grade_management.php can display each component (e.g. the
    // Attendance column) without re-running these queries itself.
    $parts = [
        'attendance'        => $attPct,
        'quiz'              => $quizPct,
        'seatwork'          => $seatPct,
        'assignment'        => $assignPct,
        'performance_task'  => $ptPct,
        'exam'              => $examPct,
    ];

    if ($quizPct === null && $seatPct === null && $assignPct === null && $ptPct === null && $examPct === null && $attPct === null) {
        return ['total' => null, 'remarks' => 'no_record'] + $parts;
    }

    // Dynamic weight redistribution: a category the teacher never uses
    // at all (zero items for the whole class) is excluded entirely
    // rather than silently scored as 0 — its weight is redistributed
    // proportionally among the categories that do exist, so a student
    // who aces everything actually assigned gets a total near 100, not
    // dragged down by categories the teacher simply never assigned.
    // Attendance "exists" per-student (see $attPct above) since it's
    // recorded per session for the whole class already.
    $weights = [
        'quiz'             => 0.10,
        'seatwork'         => 0.10,
        'assignment'       => 0.10,
        'performance_task' => 0.20,
        'exam'             => 0.40,
    ];
    $pcts = [
        'quiz'             => $quizPct,
        'seatwork'         => $seatPct,
        'assignment'       => $assignPct,
        'performance_task' => $ptPct,
        'exam'             => $examPct,
    ];

    $activeWeight = $attPct !== null ? 0.10 : 0.0;
    foreach ($weights as $grp => $w) {
        if (in_array($grp, $classHas, true)) {
            $activeWeight += $w;
        }
    }
    if ($activeWeight <= 0.0) {
        return ['total' => null, 'remarks' => 'no_record'] + $parts;
    }

    $weightedSum = ($attPct !== null ? $attPct * 0.10 : 0.0);
    foreach ($weights as $grp => $w) {
        if (in_array($grp, $classHas, true)) {
            $weightedSum += ($pcts[$grp] ?? 0.0) * $w;
        }
    }

    $total = round(
        $weightedSum / $activeWeight,
        2
    );
    return ['total' => $total, 'remarks' => $total >= 75 ? 'passed' : 'failed'] + $parts;
}
