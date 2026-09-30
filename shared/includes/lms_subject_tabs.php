<?php
// Second-tier tab strip — Stream / Lessons / Discussions / Assignments /
// Quizzes / People, scoped to one subject. Stream is the subject's home
// (class announcements); People lists its teacher(s) and classmates.
// Included by every subject-scoped academics page right below
// the main topbar, but only renders when a subject_id is present (i.e. the
// student arrived here from a My Courses card rather than the main nav's
// Academics dropdown, which still browses every subject grouped together).
//
// Expects in scope: $subject_id (int), $selected_sy (string), and
// optionally $selected_sem (string) and $subject_name (string|null).
if (!empty($subject_id)) {
    $lst_qs_base = array_filter([
        'subject_id' => $subject_id,
        'sy'         => $selected_sy ?? '',
    ]);
    $lst_qs_with_sem = $lst_qs_base;
    if (!empty($selected_sem)) {
        $lst_qs_with_sem['sem'] = $selected_sem;
    }

    $lst_current = basename($_SERVER['PHP_SELF']);
    $lst_tabs = [
        'student_stream.php'      => ['Stream',      'student_stream',      $lst_qs_base],
        'student_lessons.php'     => ['Lessons',     'student_lessons',     $lst_qs_with_sem],
        'student_discussions.php' => ['Discussions', 'student_discussions', $lst_qs_base],
        'student_assignments.php' => ['Assignments', 'student_assignments', $lst_qs_with_sem],
        'student_quizzes.php'     => ['Quizzes',      'student_quizzes',    $lst_qs_with_sem],
        'student_people.php'      => ['People',      'student_people',      $lst_qs_base],
    ];
    ?>
    <div class="subj-tabstrip">
      <?php if (!empty($subject_name)): ?>
        <span class="subj-tabstrip-name"><?= htmlspecialchars($subject_name) ?></span>
      <?php endif; ?>
      <?php foreach ($lst_tabs as $lst_file => [$lst_label, $lst_href, $lst_qs]): ?>
        <a href="<?= APP_URL ?>/roles/lms/<?= $lst_href ?>?<?= http_build_query($lst_qs) ?>"
           class="<?= $lst_current === $lst_file ? 'active' : '' ?>"><?= $lst_label ?></a>
      <?php endforeach; ?>
    </div>
    <?php
}
