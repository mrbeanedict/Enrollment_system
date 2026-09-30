<?php
/* ============================================================
   curriculum.php — Subject Curriculum Management
   ------------------------------------------------------------
   Access model (mirrors sections.php):
     - role='admin'                         -> reviewer (approve/reject),
                                                same as coordinator, plus
                                                can always reach this page
     - staff, department='coordinator'       -> reviewer (approve/reject)
     - staff, department='scheduler'         -> proposer (add subjects,
                                                which land as 'for_review'
                                                unless a reviewer is the
                                                one submitting)
   Everyone who can reach this page sees the same list; reviewer-only
   controls (Approve/Reject) simply don't render for a scheduler.
   Toggle/Delete are NOT gated beyond page access, same precedent as
   sections.php's Deactivate/Reactivate/Generate Subjects.
   ============================================================ */

session_start();
require_once __DIR__ . '/../../../bootstrap.php';

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$is_admin     = ($role === 'admin');
$is_reviewer  = $is_admin || ($role === 'staff' && $department === 'coordinator');
$is_scheduler = ($role === 'staff' && $department === 'scheduler');

if (!$is_reviewer && !$is_scheduler) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

// ── AJAX: enrolled/pending students taking a subject ───────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'students' && isset($_GET['subject_id'])) {
    header('Content-Type: application/json');
    $subid = (int)$_GET['subject_id'];
    $res  = $conn->prepare("
        SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, st.strand_code AS strand,
               s.family_name, s.given_name, s.student_number, sec.section_name
        FROM enrollment_subjects es
        JOIN enrollments e ON e.enrollment_id = es.enrollment_id
        JOIN students s    ON s.student_id   = e.student_id
        JOIN strands st    ON st.strand_id   = e.admission_strand
        LEFT JOIN sections sec ON sec.section_id = es.section_id
        WHERE es.subject_id = ? AND e.status IN ('pending','enrolled')
        ORDER BY s.family_name, s.given_name
    ");
    $res->bind_param('i', $subid);
    $res->execute();
    $data = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($data);
    $conn->close();
    exit();
}

// Maps a strand code to one of the .strand-badge--* color modifiers defined
// in this page's <style> block.
function strand_badge_class(?string $code): string {
    $map = ['STEM' => 'stem', 'HUMSS' => 'humss', 'ABM' => 'abm', 'GAS' => 'gas', 'TVL-ICT' => 'tvl', 'TVL' => 'tvl', 'CORE' => 'core'];
    return $map[$code] ?? 'none';
}

// One subject row, shared by the List and Grouped views. The Grouped view
// drops the Grade / Strand cell since its group header already says it, and
// the Approval cell since it only lists approved subjects.
function render_subject_row(array $r, bool $is_reviewer, bool $grouped = false): void {
    $subid     = (int)$r['subject_id'];
    $modalArgs = $subid . ', ' . htmlspecialchars(json_encode($r['subject_name']));
    $reviewBadge = [
        'approved'   => ['badge-approved', 'Approved'],
        'for_review' => ['badge-pending', 'Pending'],
        'rejected'   => ['badge-rejected', 'Rejected'],
    ][$r['review_status']];
    $toggle_msg = ($r['is_active'] ? 'Deactivate' : 'Activate') . ' subject "' . $r['subject_name'] . '"?';
    $delete_msg = 'Delete subject "' . $r['subject_name'] . '"? Students who already completed or are taking it will be unaffected, but it will no longer be assignable to new sections.';
    ?>
                  <tr class="clickable" tabindex="0"
                      onclick="if(!event.target.closest('.td-actions'))openModal(<?= $modalArgs ?>)"
                      onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' '))openModal(<?= $modalArgs ?>)">
                    <td class="td-name">
                      <?= htmlspecialchars($r['subject_name']) ?>
                      <?php if ($r['review_status'] === 'rejected' && $r['rejection_reason']): ?>
                        <div class="rej-reason">Reason: <?= htmlspecialchars($r['rejection_reason']) ?></div>
                      <?php endif; ?>
                    </td>
                    <?php if (!$grouped): ?>
                    <td>G<?= htmlspecialchars($r['grade_level']) ?> &middot; <span class="strand-badge strand-badge--<?= strand_badge_class($r['strand']) ?>"><?= htmlspecialchars($r['strand']) ?></span></td>
                    <?php endif; ?>
                    <td><?= (int)$r['taking'] ?></td>
                    <?php if (!$grouped): ?>
                    <td><span class="badge <?= $reviewBadge[0] ?>"><?= $reviewBadge[1] ?></span></td>
                    <?php endif; ?>
                    <td>
                      <?php if ($r['is_active']): ?>
                        <span class="badge badge-active">Active</span>
                      <?php else: ?>
                        <span class="badge badge-inactive">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <?php if ($is_reviewer): ?>
                    <!-- Clicks here don't open the student list (the row's handler skips .td-actions);
                         no stopPropagation, so the ⋮ menu's document-level handler still hears them. -->
                    <td class="td-actions">
                      <?php if ($r['review_status'] === 'for_review'): ?>
                        <form method="POST" style="display:inline;"
                              data-confirm="Approve subject &quot;<?= htmlspecialchars($r['subject_name']) ?>&quot;?" data-icon="question">
                          <input type="hidden" name="subject_id" value="<?= $subid ?>">
                          <button type="submit" name="approve_subject" class="btn-toggle btn-approve">Approve</button>
                        </form>
                        <form method="POST" style="display:inline;" class="reject-form">
                          <input type="hidden" name="subject_id" value="<?= $subid ?>">
                          <input type="hidden" name="rejection_reason" value="">
                          <button type="button" class="btn-toggle btn-reject js-reject"
                                  data-name="<?= htmlspecialchars($r['subject_name'], ENT_QUOTES) ?>">Reject</button>
                        </form>
                      <?php else:
                        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES); ?>
                        <!-- ⋮ menu (sections.php's row menu): Edit (approved only),
                             Activate/Deactivate, then Delete. Details ride along as
                             data-* for the Edit modal. -->
                        <div class="row-menu"
                             data-id="<?= $subid ?>" data-name="<?= $h($r['subject_name']) ?>"
                             data-meta="Grade <?= $h($r['grade_level']) ?> · <?= $h($r['strand']) ?> · SY <?= $h($r['school_year']) ?>">
                          <button type="button" class="row-menu-btn" aria-label="More actions for <?= $h($r['subject_name']) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle>
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                          </button>
                          <div class="row-menu-dropdown" role="menu">
                            <?php if ($r['review_status'] === 'approved'): ?>
                              <button type="button" class="row-menu-item" role="menuitem" data-action="edit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Edit subject
                              </button>
                            <?php endif; ?>
                            <form method="POST" data-confirm="<?= htmlspecialchars($toggle_msg) ?>" data-icon="question">
                              <input type="hidden" name="subject_id" value="<?= $subid ?>">
                              <input type="hidden" name="is_active"  value="<?= (int)$r['is_active'] ?>">
                              <button type="submit" name="toggle_subject" class="row-menu-item" role="menuitem">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $r['is_active'] ? '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>' : '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>' ?></svg><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>
                              </button>
                            </form>
                            <div class="row-menu-divider"></div>
                            <form method="POST" data-confirm="<?= htmlspecialchars($delete_msg) ?>" data-icon="warning">
                              <input type="hidden" name="subject_id" value="<?= $subid ?>">
                              <button type="submit" name="delete_subject" class="row-menu-item row-menu-item--danger" role="menuitem">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>Delete
                              </button>
                            </form>
                          </div>
                        </div>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                  </tr>
    <?php
}

// ── Valid grade levels / strands ────────────────────────────────────────────
$valid_grade_levels = ['11', '12'];
$valid_strands      = ['CORE', 'STEM', 'HUMSS', 'ABM', 'GAS', 'TVL'];

// ── School years — every distinct year already used by a subject, plus
// whichever year is currently open, so a brand-new year still shows up in
// the picker even before any subject has been tagged with it yet. ─────────
$all_years = [];
$sy_res = mysqli_query($conn, "
    SELECT school_year FROM subjects
    UNION
    SELECT school_year FROM school_year_settings
    ORDER BY school_year DESC
");
if ($sy_res) { while ($r = mysqli_fetch_row($sy_res)) { $all_years[] = $r[0]; } }

$default_year_res = mysqli_query($conn, "
    SELECT school_year FROM school_year_settings
    WHERE is_admission_open = 1 OR is_enrollment_open = 1
    ORDER BY school_year DESC LIMIT 1
");
$default_year = $default_year_res && ($r = mysqli_fetch_row($default_year_res)) ? $r[0] : ($all_years[0] ?? date('Y') . '-' . (date('Y') + 1));

// Preserve current filters/page across every redirect (PRG pattern, same as
// pending_applications.php's $return_qs).
$f_grade  = in_array($_GET['grade_level'] ?? '', $valid_grade_levels, true) ? $_GET['grade_level'] : '';
$f_strand = in_array($_GET['strand'] ?? '', $valid_strands, true) ? $_GET['strand'] : '';
$f_review = $_GET['review_status'] ?? '';
if (!in_array($f_review, ['approved', 'for_review', 'rejected'], true)) $f_review = '';
$f_year   = isset($_GET['school_year']) && in_array($_GET['school_year'], $all_years, true) ? $_GET['school_year'] : $default_year;
$page     = max(1, (int)($_GET['page'] ?? 1));
$f_source_year = isset($_GET['source_year']) && in_array($_GET['source_year'], $all_years, true) ? $_GET['source_year'] : '';
$f_q      = mb_substr(trim($_GET['q'] ?? ''), 0, 100);

// Builds a list URL from the current filters with some overridden
// ('' drops a key). Page resets to 1 unless a page is given.
$list_state = [
    'q'             => $f_q,
    'school_year'   => $f_year !== $default_year ? $f_year : '',
    'grade_level'   => $f_grade,
    'strand'        => $f_strand,
    'review_status' => $f_review,
];
$list_url = function (array $over = []) use ($list_state): string {
    $qs = http_build_query(array_filter(array_merge($list_state, $over), fn($v) => $v !== '' && $v !== null && $v !== 1));
    return '?' . $qs;
};

$qs_params = array_filter([
    'q'             => $f_q,
    'grade_level'   => $f_grade,
    'strand'        => $f_strand,
    'review_status' => $f_review,
    'school_year'   => $f_year !== $default_year ? $f_year : null,
    'source_year'   => $f_source_year,
    'page'          => $page > 1 ? $page : null,
]);
$return_qs = http_build_query($qs_params);

// ── Propose / Create Subject POST ───────────────────────────────────────────
// A reviewer (coordinator/admin) creating a subject here auto-approves it,
// same as sections.php's admin-created sections. A scheduler's submission
// always lands as 'for_review' and waits on a reviewer.
//
// NOTE: no "units" field — that's a college-registrar concept, not part of
// the DepEd SHS curriculum model this system follows. No semester either —
// SHS students enroll once per school year, so a subject is offered for the
// whole year, not split across terms.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $grade_level  = trim($_POST['grade_level']  ?? '');
    $strand       = trim($_POST['strand']        ?? '');

    if (!$subject_name || !$grade_level || !$strand) {
        $error = 'Subject name, grade level, and strand are required.';
    } elseif (!in_array($grade_level, $valid_grade_levels, true)) {
        $error = 'Please select a valid grade level.';
    } elseif (!in_array($strand, $valid_strands, true)) {
        $error = 'Please select a valid strand.';
    } else {
        $review_status = $is_reviewer ? 'approved' : 'for_review';
        $proposed_by   = $is_reviewer ? null : $user_id;
        $approved_by   = $is_reviewer ? $user_id : null;
        $approved_at   = $is_reviewer ? date('Y-m-d H:i:s') : null;
        $strandId      = strand_id($conn, $strand);

        $stmt = $conn->prepare(
            "INSERT INTO subjects (subject_name, grade_level, strand, is_active, review_status, proposed_by, approved_by, approved_at, school_year)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssisiiss', $subject_name, $grade_level, $strandId, $review_status, $proposed_by, $approved_by, $approved_at, $f_year);
        if ($stmt->execute()) {
            $success = $is_reviewer
                ? "Subject \"$subject_name\" created and approved."
                : "Subject \"$subject_name\" submitted for coordinator review.";
            if (!$is_reviewer) {
                notify_coordinators($conn, "New subject \"$subject_name\" awaiting review.", 'coordinator/approvals?type=curriculum');
            }
        } elseif ($conn->errno === 1062) {
            $error = 'A subject with this name, grade level, and strand already exists.';
        } else {
            $error = 'Could not submit subject. Please try again.';
        }
        $stmt->close();
    }
}

// ── Approve a pending subject (REVIEWER-ONLY: coordinator or admin) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can approve a proposed subject.';
    } else {
        $subid = (int)($_POST['subject_id'] ?? 0);
        $info_stmt = $conn->prepare("SELECT subject_name, proposed_by FROM subjects WHERE subject_id = ?");
        $info_stmt->bind_param('i', $subid);
        $info_stmt->execute();
        $subj_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare(
            "UPDATE subjects SET review_status='approved', approved_by=?, approved_at=NOW(), rejection_reason=NULL
             WHERE subject_id=? AND review_status='for_review'"
        );
        $stmt->bind_param('ii', $user_id, $subid);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok && $affected > 0) {
            if ($subj_row && $subj_row['proposed_by']) {
                notify_users($conn, [$subj_row['proposed_by']], "Subject \"{$subj_row['subject_name']}\" was approved.", 'scheduler/curriculum', 'schedule');
            }
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = 'Could not approve — it may have already been reviewed.';
    }
}

// ── Reject a pending subject (REVIEWER-ONLY: coordinator or admin) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can reject a proposed subject.';
    } else {
        $subid  = (int)($_POST['subject_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$reason) {
            $error = 'Please provide a reason for rejecting this subject.';
        } else {
            $info_stmt = $conn->prepare("SELECT subject_name, proposed_by FROM subjects WHERE subject_id = ?");
            $info_stmt->bind_param('i', $subid);
            $info_stmt->execute();
            $subj_row = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();

            $stmt = $conn->prepare(
                "UPDATE subjects SET review_status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=?
                 WHERE subject_id=? AND review_status='for_review'"
            );
            $stmt->bind_param('isi', $user_id, $reason, $subid);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($ok && $affected > 0) {
                if ($subj_row && $subj_row['proposed_by']) {
                    notify_users($conn, [$subj_row['proposed_by']], "Subject \"{$subj_row['subject_name']}\" was rejected.", 'scheduler/curriculum', 'schedule');
                }
                header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
            }
            $error = 'Could not reject — it may have already been reviewed.';
        }
    }
}

// ── Toggle active status — coordinator/admin only ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can change a subject\'s active status.';
    } else {
        $subid = (int)($_POST['subject_id'] ?? 0);
        $cur   = (int)($_POST['is_active']  ?? 1);
        $new   = $cur ? 0 : 1;
        $stmt  = $conn->prepare("UPDATE subjects SET is_active=? WHERE subject_id=?");
        $stmt->bind_param('ii', $new, $subid);
        if ($stmt->execute()) {
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = 'Could not update subject status. Please try again.';
        $stmt->close();
    }
}

// ── Delete (soft) subject — coordinator/admin only ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can delete a subject.';
    } else {
        $subid = (int)($_POST['subject_id'] ?? 0);
        $stmt  = $conn->prepare("UPDATE subjects SET is_active = 0 WHERE subject_id = ?");
        $stmt->bind_param('i', $subid);
        if ($stmt->execute()) {
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = 'Could not delete subject. Please try again.';
        $stmt->close();
    }
}

// ── Edit an approved subject's name — coordinator/admin only ────────────────
// Grade, strand and school year stay fixed: sections' subject lists were
// built from them.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_subject'])) {
    $subid        = (int)($_POST['subject_id'] ?? 0);
    $subject_name = trim($_POST['subject_name'] ?? '');
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can edit a subject.';
    } elseif ($subject_name === '') {
        $error = 'Subject name is required.';
    } else {
        $stmt = $conn->prepare("UPDATE subjects SET subject_name = ? WHERE subject_id = ? AND review_status = 'approved'");
        $stmt->bind_param('si', $subject_name, $subid);
        try {
            $ok = $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            $ok = false;
        }
        if ($ok) {
            $_SESSION['curriculum_flash'] = "Subject \"$subject_name\" updated.";
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = $conn->errno === 1062
            ? 'A subject with this name, grade level, and strand already exists.'
            : 'Could not update subject. Please try again.';
        $stmt->close();
    }
}

// ── Carry selected subjects into the currently-viewed year ─────────────────
// Unlike the old all-or-nothing copy, this only inserts the specific
// subject_ids the user checked off (re-validated server-side against
// source_year/is_active/review_status — never trust the posted IDs blindly).
// Reviewer submissions auto-approve, same as add_subject above; a scheduler's
// carried-over subjects still land as 'for_review' just like a manual
// proposal. INSERT IGNORE against the (subject_name, grade_level, strand,
// school_year) unique key makes re-submitting harmless.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_copy_subjects'])) {
    $source_year = trim($_POST['source_year'] ?? '');
    $target_year = trim($_POST['target_year'] ?? '');
    $picked_ids  = array_filter(array_map('intval', $_POST['subject_id'] ?? []));

    if (!in_array($source_year, $all_years, true) || !in_array($target_year, $all_years, true)) {
        $error = 'Please choose valid school years.';
    } elseif ($source_year === $target_year) {
        $error = 'Choose two different school years to copy between.';
    } elseif (empty($picked_ids)) {
        $error = 'Please select at least one subject to carry over.';
    } else {
        $ph = implode(',', array_fill(0, count($picked_ids), '?'));
        $verify_stmt = $conn->prepare("
            SELECT subject_id FROM subjects
            WHERE subject_id IN ($ph) AND school_year = ? AND is_active = 1 AND review_status = 'approved'
        ");
        $verify_stmt->bind_param(str_repeat('i', count($picked_ids)) . 's', ...[...$picked_ids, $source_year]);
        $verify_stmt->execute();
        $confirmed_ids = array_map(fn($r) => (int)$r['subject_id'], $verify_stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $verify_stmt->close();

        if (empty($confirmed_ids)) {
            $error = 'None of the selected subjects could be found in that school year.';
        } else {
            $review_status = $is_reviewer ? 'approved' : 'for_review';
            $proposed_by   = $is_reviewer ? null : $user_id;
            $approved_by   = $is_reviewer ? $user_id : null;
            $approved_at   = $is_reviewer ? date('Y-m-d H:i:s') : null;

            $ph2 = implode(',', array_fill(0, count($confirmed_ids), '?'));
            $copy_stmt = $conn->prepare("
                INSERT IGNORE INTO subjects
                    (subject_name, grade_level, strand, is_active, review_status, proposed_by, approved_by, approved_at, school_year)
                SELECT subject_name, grade_level, strand, 1, ?, ?, ?, ?, ?
                FROM subjects
                WHERE subject_id IN ($ph2)
            ");
            $copy_stmt->bind_param('siiss' . str_repeat('i', count($confirmed_ids)), $review_status, $proposed_by, $approved_by, $approved_at, $target_year, ...$confirmed_ids);
            if ($copy_stmt->execute()) {
                $copied = $copy_stmt->affected_rows;
                $copy_stmt->close();
                $success = $copied > 0
                    ? ($is_reviewer
                        ? "$copied subject(s) added to SY $target_year."
                        : "$copied subject(s) submitted for coordinator review in SY $target_year.")
                    : "Those subjects are already part of SY $target_year.";
                if (!$is_reviewer && $copied > 0) {
                    notify_coordinators($conn, "$copied subject(s) carried into SY $target_year awaiting review.", 'coordinator/approvals?type=curriculum');
                }
            } else {
                $error = 'Could not carry over subjects. Please try again.';
                $copy_stmt->close();
            }
        }
    }
}

// The Propose Subject / Carry Over modals reopen themselves with their own
// error inside when a submission fails (admin/staff_manage.php pattern),
// instead of showing it at the top of the page with the form hidden.
$add_error = $carry_error = $edit_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    if (isset($_POST['add_subject']))        { $add_error   = $error; $error = ''; }
    if (isset($_POST['bulk_copy_subjects'])) { $carry_error = $error; $error = ''; }
    if (isset($_POST['edit_subject']) && $is_reviewer) { $edit_error = $error; $error = ''; }
}
// Success message from the Edit handler's redirect.
if (!empty($_SESSION['curriculum_flash'])) {
    $success = $_SESSION['curriculum_flash'];
    unset($_SESSION['curriculum_flash']);
}

// ── Filters (WHERE) ──────────────────────────────────────────────────────────
$where  = ['1=1'];
$types  = '';
$params = [];

if ($f_q !== '')      { $where[] = 's.subject_name LIKE ?'; $types .= 's'; $params[] = '%' . $f_q . '%'; }
$where[] = 's.school_year = ?'; $types .= 's'; $params[] = $f_year;
// Grade / strand / approval are applied after the fetch (below): the List
// view uses them, the Grouped view deliberately doesn't, and the status
// switch needs a count for every status.
$where_sql = implode(' AND ', $where);

// ── Fetch subjects (the viewed year, matching the search) ─────────────────
$sql = "
    SELECT s.subject_id, s.subject_name, s.grade_level, st.strand_code AS strand,
           s.is_active, s.review_status, s.rejection_reason, s.school_year,
           COUNT(DISTINCT CASE WHEN e.status IN ('pending','enrolled') THEN e.enrollment_id END) AS taking
    FROM subjects s
    JOIN strands st ON st.strand_id = s.strand
    LEFT JOIN enrollment_subjects es ON es.subject_id = s.subject_id
    LEFT JOIN enrollments e ON e.enrollment_id = es.enrollment_id
    WHERE $where_sql
    GROUP BY s.subject_id
    ORDER BY (s.review_status = 'for_review') DESC, s.grade_level ASC, s.strand ASC, s.subject_name ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$year_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// List view: grade + strand, then the approval status.
$list_rows = array_values(array_filter($year_rows, fn($r) =>
    ($f_grade === '' || $r['grade_level'] === $f_grade) && ($f_strand === '' || $r['strand'] === $f_strand)));
$status_counts = ['' => count($list_rows), 'approved' => 0, 'for_review' => 0, 'rejected' => 0];
foreach ($list_rows as $r) { $status_counts[$r['review_status']] = ($status_counts[$r['review_status']] ?? 0) + 1; }
$all_rows = $f_review === '' ? $list_rows
    : array_values(array_filter($list_rows, fn($r) => $r['review_status'] === $f_review));

// List view: one page of 10.
$per_page    = 10;
$total_count = count($all_rows);
$total_pages = max(1, (int)ceil($total_count / $per_page));
if ($page > $total_pages) $page = $total_pages;
$rows = array_slice($all_rows, ($page - 1) * $per_page, $per_page);

// Grouped view: the year's whole curriculum as it stands — approved subjects
// only (pending and rejected ones live in the List view), ignoring the
// grade/strand/approval filters. Grade → Strand, subjects A–Z inside.
$grouped_rows = array_values(array_filter($year_rows, fn($r) => $r['review_status'] === 'approved'));
$grouped_count = count($grouped_rows);
$groups = [];
foreach ($grouped_rows as $r) {
    $groups[$r['grade_level'] . '|' . $r['strand']][] = $r;
}
$strand_rank = array_flip($valid_strands);
uksort($groups, function ($a, $b) use ($strand_rank) {
    [$ga, $sa] = explode('|', $a);
    [$gb, $sb] = explode('|', $b);
    return [$ga, $strand_rank[$sa] ?? 99, $sa] <=> [$gb, $strand_rank[$sb] ?? 99, $sb];
});
foreach ($groups as &$g) {
    usort($g, fn($a, $b) => strcasecmp($a['subject_name'], $b['subject_name']));
}
unset($g);

// Pending count — shown to reviewers as a heads-up banner (mirrors
// sections.php's $pending_count, which is admin-only there too).
$pending_count = 0;
if ($is_reviewer) {
    $pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM subjects WHERE review_status = 'for_review'");
    $pending_count = $pc ? (int)mysqli_fetch_assoc($pc)['c'] : 0;
}

$filter_labels = [
    ''           => 'All',
    'approved'   => 'Approved',
    'for_review' => 'Pending',
    'rejected'   => 'Rejected',
];

// ── Carry Over modal data ───────────────────────────────────────────────────
// Everything the modal needs for every school year, so switching the
// "Copy from" / "into" years, searching and strand-filtering all happen in
// the browser without a reload. Per subject: [id, name, grade, strand, copyable]
// — copyable = active + approved (only those can be carried from a year);
// every row, copyable or not, counts as "already in" a target year, matching
// the (subject_name, grade_level, strand, school_year) unique key.
$other_years = array_values(array_diff($all_years, [$f_year]));
$is_carry_post = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_copy_subjects']);
$carry_source_year = $is_carry_post && in_array($_POST['source_year'] ?? '', $all_years, true)
    ? $_POST['source_year']
    : ($f_source_year !== '' ? $f_source_year : ($other_years[0] ?? ''));
$carry_target_year = $is_carry_post && in_array($_POST['target_year'] ?? '', $all_years, true)
    ? $_POST['target_year']
    : $f_year;
$carry_picked = $is_carry_post && $carry_error !== '' ? array_map('intval', $_POST['subject_id'] ?? []) : [];

$carry_data = array_fill_keys($all_years, []);
$carry_res = mysqli_query($conn, "
    SELECT s.subject_id, s.subject_name, s.grade_level, st.strand_code AS strand, s.school_year,
           (s.is_active = 1 AND s.review_status = 'approved') AS copyable
    FROM subjects s
    JOIN strands st ON st.strand_id = s.strand
    ORDER BY s.grade_level ASC, s.subject_name ASC
");
if ($carry_res) {
    while ($c = mysqli_fetch_assoc($carry_res)) {
        $carry_data[$c['school_year']][] = [(int)$c['subject_id'], $c['subject_name'], $c['grade_level'], $c['strand'], (int)$c['copyable']];
    }
}
// With no explicit source, default to copying FROM the year with the most
// copyable subjects (not just the newest, which may be empty) INTO the year
// being viewed — or, if that's the same year, into the newest other year.
if (!$is_carry_post && $f_source_year === '' && $all_years) {
    $copyable_count = fn($y) => count(array_filter($carry_data[$y] ?? [], fn($r) => $r[4] === 1));
    $best = $all_years;
    usort($best, fn($a, $b) => $copyable_count($b) <=> $copyable_count($a));
    $carry_source_year = $best[0];
    if ($carry_source_year === $carry_target_year) {
        $carry_target_year = array_values(array_diff($all_years, [$carry_source_year]))[0] ?? $carry_target_year;
    }
}
// Strand chips, in the page's usual strand order, then any others present.
$carry_strands = array_values(array_unique(array_merge(
    array_intersect($valid_strands, array_column(array_merge(...array_values($carry_data) ?: [[]]), 3)),
    array_column(array_merge(...array_values($carry_data) ?: [[]]), 3)
)));

// NOTE: do NOT close $conn here — staff_sidebar.php (included below, in the
// HTML body) runs its own queries for nav badges/status and needs the
// connection still open. Let PHP close it automatically at script end.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Curriculum — <?= $is_reviewer ? 'Coordinator' : 'Scheduler' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    /* Supplemental styles — same conventions as sections.php, so the two
       pages feel like one dashboard rather than two different apps. */
    /* Underlined filter tabs (not filled pills) — matches the treasury
       enrollments / coordinator approvals pages: active tab gets a bottom
       border instead of a solid fill. */
    /* ── Card + toolbar (coordinator/approvals.php's .ap-card / .ap-bar) ── */
    .cu-card { background:#fff; border:1px solid #E4E4EC; border-radius:16px; overflow:hidden;
      box-shadow:0 1px 2px rgba(16,24,40,.04), 0 1px 8px rgba(16,24,40,.03); }
    .cu-bar { display:flex; flex-direction:column; gap:10px; padding:14px 16px 12px; margin:0; }
    .cu-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cu-row--filters { justify-content:space-between; gap:12px; }
    .cu-row-l { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cu-row .view-toggle { margin-left:auto; }
    .cu-field { position:relative; }
    .cu-field--grow { flex:1 1 260px; }
    .cu-card .cu-field--grow .cu-input { width:100%; }
    .cu-field svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#54615A; pointer-events:none; }
    /* .cu-card prefix: css_staff.css sets width:100% / height:40px on bare inputs + selects */
    .cu-card .cu-input, .cu-card .cu-select { height:36px; width:auto; margin:0; border:1px solid #C4CFC8; border-radius:8px; background-color:#fff;
      padding:0 10px; font-size:13px; font-family:inherit; color:#17211C; outline:none; }
    .cu-card .cu-input { padding-left:32px; width:220px; }
    .cu-card .cu-input::placeholder { color:#54615A; }
    .cu-card .cu-input:focus, .cu-card .cu-select:focus { border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); }
    .cu-card .cu-select { min-width:120px; padding-right:30px; appearance:none; -webkit-appearance:none; cursor:pointer;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2354615A' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 10px center; }
    .cu-clear { font-size:13px; color:#54615A; text-decoration:underline; text-underline-offset:2px; white-space:nowrap; }
    .cu-clear:hover { color:#A5221B; }

    .cu-seg { display:inline-flex; padding:2px; background:#F5F8F6; border:1px solid #E1E7E3; border-radius:8px; }
    .cu-seg a { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:6px; font-size:13px; font-weight:500; color:#54615A; text-decoration:none; white-space:nowrap; }
    .cu-seg a:hover { color:#17211C; }
    .cu-seg a[aria-current="true"] { background:#fff; color:#17211C; font-weight:600; box-shadow:0 1px 2px rgba(20,40,30,.14); }
    .cu-seg .ct { font-size:12px; font-weight:500; color:#54615A; }

    .cu-scroll { overflow-x:auto; }
    .cu-pager { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:12px 16px; border-top:1px solid #E1E7E3; font-size:13px; color:#54615A; }
    .cu-pages { display:flex; gap:4px; }
    .cu-pages a, .cu-pages span { display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:30px; padding:0 6px; border-radius:6px; text-decoration:none; color:#17211C; font-size:13px; }
    .cu-pages a:hover { background:#F5F8F6; }
    .cu-pages span.cur { background:var(--brand-primary); color:#fff; font-weight:600; }
    .cu-empty { padding:48px 16px 52px; text-align:center; border-top:1px solid #EDEDF2; }
    .cu-empty h2 { margin:0 0 4px; font-size:16px; font-weight:600; color:#17211C; }
    .cu-empty p { margin:0; color:#54615A; font-size:14px; }
    @media (max-width: 860px) {
      .cu-field--grow { flex:1 1 100%; max-width:none; }
      .cu-row-l { width:100%; }
      .cu-card .cu-select { flex:1; }
      .cu-row .view-toggle { margin-left:0; }
      .cu-seg { width:100%; overflow-x:auto; }
    }

    /* Color-coded, higher-contrast strand badges instead of plain text. */
    .strand-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:6px;
      font-size:11px; font-weight:700; letter-spacing:.01em; white-space:nowrap; }
    .strand-badge--stem  { background:#E1ECEA; color:#1F5E57; }
    .strand-badge--humss { background:#E6ECF2; color:#34526E; }
    .strand-badge--abm   { background:#EFEEE2; color:#5E5A2A; }
    .strand-badge--gas   { background:#E4F0E8; color:#2D6547; }
    .strand-badge--tvl   { background:#F1EBE3; color:#6B4E2E; }
    .strand-badge--core  { background:#E9ECEB; color:#44524B; }
    .strand-badge--none  { background:#EDEDF0; color:#4A5560; }

    /* Table — approvals.php's .ap-table: uppercase headers on a faint band,
       quiet row borders, row-hover wash. */
    .data-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:auto; min-width:680px; }
    .data-table th {
      text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
      color:#8A8A9A; padding:10px 14px; background:#FAFAFC; border-top:1px solid #EDEDF2; border-bottom:1px solid #EDEDF2; white-space:nowrap;
    }
    .data-table td { padding:11px 14px; border-bottom:1px solid #F5F5F7; vertical-align:middle; color:#1A1A2E; }
    .data-table th:first-child, .data-table td:first-child { padding-left:16px; }
    .data-table th:last-child,  .data-table td:last-child  { padding-right:16px; }
    .data-table tr:last-child td { border-bottom:none; }
    .td-name { font-weight:500; color:#1A1A2E; }
    .td-meta { font-size:11px; color:#8A8A9A; margin-top:2px; }

    .badge { display:inline-block; font-size:10px; font-weight:600; text-transform:uppercase;
      letter-spacing:.03em; border-radius:20px; padding:2px 9px; margin:1px 4px 1px 0; white-space:nowrap; }
    /* Approval = soft pill; Status = dot + label. One muted family throughout. */
    .badge-approved  { background:#E4F0E8; color:#2D6547; }
    .badge-pending   { background:#F5EEDC; color:#76581A; }
    .badge-rejected  { background:#F5E6E3; color:#94382C; }
    .badge-active, .badge-inactive { background:none; padding-left:0; text-transform:none; letter-spacing:0; font-size:12px; font-weight:600; }
    .badge-active::before, .badge-inactive::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:6px; vertical-align:1px; }
    .badge-active    { color:#2D6547; }
    .badge-active::before   { background:#3E8A62; }
    .badge-inactive  { color:#7A8580; }
    .badge-inactive::before { background:#B7C0BB; }

    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72; margin:2px 4px 2px 0;
    }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .btn-toggle.btn-approve { background:#1A7A5E; border-color:#1A7A5E; color:#fff; font-weight:600; }
    .btn-toggle.btn-approve:hover { background:#14624B; border-color:#14624B; color:#fff; }

    /* ── Row ⋮ menu (same as sections.php). The dropdown is position:fixed
       (placed by JS) so the table's scroll wrapper can't clip it. ── */
    .row-menu { position:relative; display:inline-block; }
    .row-menu-btn { width:30px; height:30px; border:none; border-radius:8px; background:transparent; color:#5A5A72; cursor:pointer;
      display:flex; align-items:center; justify-content:center; transition:background .12s, color .12s; }
    .row-menu-btn svg { width:16px; height:16px; }
    .row-menu-btn:hover, .row-menu.open .row-menu-btn { background:#E9EFEB; color:#1A1A2E; }
    .row-menu-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:1px; }
    .row-menu-dropdown { position:fixed; min-width:180px; padding:6px; background:#fff; border:1px solid #E1E8E4; border-radius:10px;
      box-shadow:0 12px 28px rgba(18,32,26,.16); opacity:0; visibility:hidden; transform:translateY(-4px);
      transition:opacity .12s ease, transform .12s ease, visibility .12s; z-index:60; }
    .row-menu.open .row-menu-dropdown { opacity:1; visibility:visible; transform:translateY(0); }
    .row-menu-dropdown form { margin:0; }
    .row-menu-item { display:flex; align-items:center; gap:10px; width:100%; padding:8px 10px; border:none; background:none;
      font-size:13px; font-weight:500; font-family:inherit; color:#1A1A2E; text-align:left; cursor:pointer; border-radius:7px; }
    .row-menu-item svg { width:15px; height:15px; flex-shrink:0; color:#5F6B74; }
    .row-menu-item:hover, .row-menu-item:focus-visible { background:#F0F5F2; outline:none; }
    .row-menu-item--danger, .row-menu-item--danger svg { color:#B3261E; }
    .row-menu-item--danger:hover { background:#FDF0EF; }
    .row-menu-divider { height:1px; margin:5px 4px; background:#E8EEEA; }

    /* ── Pending-approval card (same as sections.php) ── */
    .pend-card { display:flex; align-items:center; gap:14px; margin:0 0 1rem; padding:14px 16px; background:#FDF3E1;
      border:1px solid #F1D9A8; border-radius:12px; }
    .pend-count { flex:0 0 auto; width:34px; height:34px; border-radius:50%; background:#F6DFAE; color:#7A5410;
      display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; }
    .pend-text { flex:1 1 auto; min-width:0; }
    .pend-title { margin:0 0 2px; font-size:14px; font-weight:600; color:#17211C; }
    .pend-sub { margin:0; font-size:12.5px; color:#5A5A48; }
    .pend-btn { flex:0 0 auto; display:inline-flex; align-items:center; height:36px; padding:0 16px; border-radius:8px;
      background:var(--brand-primary); color:#fff; font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap; }
    .pend-btn:hover { background:var(--brand-primary-hover, #163A2C); color:#fff; }
    .pend-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:2px; }
    @media (max-width: 560px) {
      .pend-card { flex-wrap:wrap; }
      .pend-btn { width:100%; justify-content:center; }
    }
    .btn-reject  { color:#C0392B; border-color:#F5C6C2; }

    .rej-reason { font-size:11px; color:#C0392B; margin-top:2px; }

    .form-group { margin-bottom:.85rem; }
    .form-group label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem; }
    .form-group input, .form-group select { width:100%; height:38px; border:0.5px solid #D4D4E0;
      border-radius:8px; background:#FAFAFC; padding:0 12px; font-size:13px;
      font-family:inherit; color:#1A1A2E; outline:none; }
    .form-group input:focus, .form-group select:focus {
      border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); background:#fff; }
    .btn-add { width:100%; height:38px; background:var(--brand-primary); border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:.2rem; }
    .btn-add:hover { background:var(--brand-primary-hover); }

    .two-col { display:grid; grid-template-columns:1fr 320px; gap:14px; align-items:start; }
    .full-col { display:block; }
    .full-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
    /* css_staff.css's .panel is built for centered single-column public pages
       (max-width:760px; margin:auto). Inside this wider two-column dashboard
       grid that centering just eats space on both sides — neutralize it so
       each panel fills its grid column instead. Same fix as sections.php. */
    .two-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    .card-title { font-size:13px; font-weight:600; color:#1A1A2E; }
    .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.9rem; }
    .empty-state { color:#8A8A9A; font-size:13px; padding:1rem 0; }
    tr.clickable { cursor:pointer; }
    tr.clickable:hover td { background:#FAFAFC; }

    /* Modal (same pattern used across the app) */
    dialog#subjectModal { border:none; border-radius:12px; padding:1.5rem;
      width:min(560px, 92vw); max-height:80vh; overflow-y:auto;
      box-shadow:0 8px 40px rgba(0,0,0,.22);
      position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); margin:0; }
    dialog#subjectModal::backdrop { background:rgba(0,0,0,.45); }
    .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .modal-title { font-size:15px; font-weight:600; color:#1A1A2E; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer;
      color:#8A8A9A; line-height:1; padding:0 4px; }
    .modal-close:hover { color:#1A1A2E; }
    .modal-meta { font-size:12px; color:#8A8A9A; margin-bottom:1rem; }
    .student-list { border-collapse:collapse; width:100%; font-size:13px; }
    .student-list th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:5px 8px 5px 0; border-bottom:0.5px solid #EBEBF0; }
    .student-list td { padding:8px 8px 8px 0; border-bottom:0.5px solid #F5F5F7; }
    .student-list tr:last-child td { border-bottom:none; }
    .badge-enrolled  { background:#E4F0E8; color:#2D6547; }
    .badge-pending2  { background:#F5EEDC; color:#76581A; }
    /* ── Carry Over modal ─────────────────────────────────────────────────
       Fixed head (years, search, strand chips) and foot (count + actions);
       only the grouped list in between scrolls. */
    .co-modal { max-width:600px; padding:0; display:flex; flex-direction:column; overflow:hidden; height:min(640px, calc(100vh - 40px)); }
    .co-head { padding:1.3rem 1.5rem 0; }
    .co-head .notice { margin:0 0 .75rem; }
    .co-header { border-bottom:0; padding-bottom:0; margin-bottom:.9rem; }
    .co-header h2 { font-size:1.15rem; }
    .co-form { display:flex; flex-direction:column; flex:1; min-height:0; }
    .co-controls { padding:0 1.5rem .9rem; border-bottom:1px solid #E8EEEA; display:flex; flex-direction:column; gap:.7rem; }
    .co-years { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:13px; color:#3A4A42; }
    .co-years label { margin:0; font-size:13px; font-weight:500; text-transform:none; letter-spacing:0; color:#3A4A42; }
    .co-years select { width:auto; height:34px; padding:0 10px; border:1px solid #D4D9D6; border-radius:8px; background:#fff; font-size:13px; font-family:inherit; color:#1A1A2E; }
    .co-search-row { display:flex; align-items:center; gap:4px; }
    .co-search { position:relative; flex:1; min-width:0; }
    .co-search svg { position:absolute; left:11px; top:50%; width:15px; height:15px; transform:translateY(-50%); color:#8A8A9A; pointer-events:none; }
    .co-search input { width:100%; height:36px; padding:0 12px 0 33px; border:1px solid #D4D9D6; border-radius:8px; background:#fff; font-size:13px; font-family:inherit; outline:none; }
    .co-search input:focus { border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); }
    .co-link { background:none; border:0; padding:6px; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--brand-primary); cursor:pointer; white-space:nowrap; border-radius:6px; }
    .co-link:hover { background:var(--brand-tint); }
    .co-link--muted { color:#5A5A72; }
    .co-chips { display:flex; flex-wrap:wrap; gap:6px; }
    .co-chip { height:28px; padding:0 12px; border:1px solid #D4D9D6; border-radius:20px; background:#fff; font-family:inherit; font-size:12px; font-weight:500; color:#3A4A42; cursor:pointer; }
    .co-chip:hover { border-color:var(--brand-accent); }
    .co-chip.is-on { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }

    .co-list { flex:1; min-height:0; overflow-y:auto; padding:.35rem 1.5rem .75rem; }
    .co-empty { color:#8A8A9A; font-size:13px; text-align:center; padding:2.5rem 0; margin:0; }
    .co-check { display:flex; align-items:center; gap:12px; min-width:0; flex:1; margin:0; cursor:pointer;
      font-size:13px; font-weight:400; text-transform:none; letter-spacing:0; color:#1A1A2E; }
    .co-check input { width:16px; height:16px; margin:0; flex-shrink:0; accent-color:var(--brand-primary); cursor:pointer; }
    .co-check input:disabled { cursor:default; }
    .co-group-head { display:flex; align-items:center; gap:8px; padding:12px 0 10px; border-bottom:1px solid #E8EEEA; }
    .co-group-head .co-check { flex:0 1 auto; }
    .co-group-title { font-weight:600; font-size:13.5px; }
    .co-tag { display:inline-block; padding:1px 7px; border-radius:5px; background:#E6ECE8; color:#3A4A42; font-size:10.5px; font-weight:700; letter-spacing:.02em; }
    .co-tag--row { margin-left:auto; flex-shrink:0; }
    .co-toggle { margin-left:auto; display:inline-flex; align-items:center; gap:4px; background:none; border:0; padding:4px 6px; border-radius:6px;
      font-family:inherit; font-size:11.5px; color:#8A6A2A; cursor:pointer; white-space:nowrap; }
    .co-toggle:hover { background:#F5F5F7; }
    .co-toggle svg { width:13px; height:13px; transition:transform .15s; }
    .co-group.is-collapsed .co-toggle svg { transform:rotate(-90deg); }
    .co-group.is-collapsed .co-rows { display:none; }
    .co-rows { list-style:none; margin:0 0 .4rem; padding:0; }
    .co-row { display:flex; align-items:center; gap:10px; padding:10px 0 10px 22px; border-bottom:1px solid #F0F2F1; }
    .co-row:last-child { border-bottom:0; }
    .co-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .co-row.is-already .co-name { color:#9A9AAA; }
    .co-row.is-already .co-check { cursor:default; }
    .co-already { margin-left:auto; flex-shrink:0; font-size:11px; color:#9A9AAA; }
    .co-row .co-tag--row + .co-already { margin-left:8px; }

    .co-foot { display:flex; align-items:center; gap:8px; padding:.9rem 1.5rem; border-top:1px solid #E8EEEA; background:#fff; }
    .co-count { margin-right:auto; font-size:13px; color:#5A5A72; }
    @media (max-width: 520px) {
      .co-head, .co-controls, .co-list, .co-foot { padding-left:1rem; padding-right:1rem; }
      .co-search-row { flex-wrap:wrap; }
    }
    tr.clickable { user-select:none; -webkit-user-select:none; }

    /* ── List / Grouped toggle (admin/staff_manage.php's .view-toggle) ──── */
    [hidden] { display:none !important; }
    .view-toggle { display:flex; border:1px solid #D4D9D6; border-radius:8px; overflow:hidden; background:#fff; }
    .view-toggle-btn { height:34px; padding:0 12px; display:flex; align-items:center; justify-content:center; gap:6px;
      background:#fff; border:none; color:#4A5560; cursor:pointer; font-size:13px; font-weight:600; font-family:inherit;
      transition:background .15s, color .15s; }
    .view-toggle-btn + .view-toggle-btn { border-left:1px solid #D4D9D6; }
    .view-toggle-btn svg { width:15px; height:15px; }
    .view-toggle-btn:hover { background:var(--brand-tint); color:var(--brand-primary); }
    .view-toggle-btn.active { background:var(--brand-primary-active); color:#fff; }
    .view-toggle-btn:active { transform:scale(.97); }

    /* ── Grouped view ─────────────────────────────────────────────────── */
    .grp-tools { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:4px 10px 4px 16px;
      border-top:1px solid #EDEDF2; font-size:13px; color:#54615A; }
    .grp-actions { display:inline-flex; gap:6px; padding:6px 0; }
    .grp-tool-btn { display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 12px; border:1px solid #C4CFC8; border-radius:999px;
      background:#fff; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--brand-primary); cursor:pointer;
      transition:background .12s, border-color .12s; }
    .grp-tool-btn svg { width:14px; height:14px; }
    .grp-tool-btn:hover { background:var(--brand-tint); border-color:var(--brand-accent); }
    .grp-tool-btn:active { transform:scale(.97); }
    .grp-tool-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:2px; }
    .grp-table { table-layout:fixed; }
    .grp-c-num   { width:90px; }
    .grp-c-badge { width:120px; }
    .grp-c-act   { width:190px; }
    .grp-table td.td-name { overflow-wrap:anywhere; }
    .grp-table tr.grp-head td { padding:0; border-bottom:0.5px solid #EBEBF0; background:#F7F9F8; }
    .grp-rows + .grp tr.grp-head td { border-top:0.5px solid #E2E6E4; }
    .grp.is-collapsed tr.grp-head td { border-bottom-color:transparent; }
    .grp-btn { display:flex; align-items:center; gap:8px; width:100%; padding:11px 16px; border:0; background:none;
      font-family:inherit; font-size:13px; color:#1A1A2E; text-align:left; cursor:pointer; }
    .grp-btn:hover { background:#F0F4F2; }
    .grp-btn:focus-visible { outline:2px solid var(--brand-accent); outline-offset:-2px; }
    .grp-title { font-weight:600; }
    .grp-count { font-size:12px; color:#8A8A9A; }
    .grp-btn .badge { margin:0; }
    .grp-chev { width:15px; height:15px; margin-left:auto; color:#8A8A9A; transition:transform .15s; }
    .grp.is-collapsed .grp-chev { transform:rotate(-90deg); }
    .grp-rows.is-collapsed { display:none; }
    .grp-table .grp-rows tr:last-child td { border-bottom:1px solid #F5F5F7; }
    .reject-form { display:flex; gap:6px; align-items:center; margin-top:4px; }
    .reject-form input[type=text] { height:26px; font-size:11px; border:0.5px solid #D4D4E0;
      border-radius:6px; padding:0 8px; font-family:inherit; }
  </style>
</head>
<body class="staff-layout">

  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>

  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left"></div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <div class="sch-page-head">
        <div>
          <p class="page-eyebrow"><?= $role === 'admin' ? 'Admin Portal' : ($is_reviewer ? 'Coordinator Portal' : 'Scheduler Portal') ?></p>
          <h1 class="page-title">Curriculum</h1>
          <p class="page-sub"><?= $is_reviewer ? 'Review proposed subjects and manage the active curriculum.' : 'Propose subjects, track coordinator approval, and manage the active curriculum.' ?></p>
        </div>
        <div class="sch-page-actions">
          <?php if (count($all_years) > 1): ?>
            <button type="button" class="sch-btn-add sch-btn-add--ghost" id="btnOpenCarry">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h13M13 7l5 5-5 5"/></svg>
              Carry over subjects
            </button>
          <?php endif; ?>
          <?php if (!$is_reviewer): ?>
            <button type="button" class="sch-btn-add" id="btnOpenAddSubject">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              Propose subject
            </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($success): ?><div class="notice notice-success" data-ui-toast><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error" data-ui-toast><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($is_reviewer && $pending_count > 0 && $f_review !== 'for_review'): ?>
        <div class="pend-card" role="status">
          <span class="pend-count"><?= $pending_count ?></span>
          <div class="pend-text">
            <p class="pend-title">Subject<?= $pending_count === 1 ? '' : 's' ?> awaiting your approval</p>
            <p class="pend-sub">Proposed subjects need your review before they're added to the curriculum.</p>
          </div>
          <a class="pend-btn" href="?review_status=for_review">Review now</a>
        </div>
      <?php endif; ?>

      <!-- Subjects — card/toolbar layout from coordinator/approvals.php, in two
           rows so nothing crowds: search, school year and the List / Grouped
           toggle on top; grade, strand and approval filters below (List view
           only — the Grouped view always shows the whole curriculum). -->
      <section class="cu-card" aria-label="Subjects">
        <form method="GET" class="cu-bar">
          <?php if ($f_review !== ''): ?><input type="hidden" name="review_status" value="<?= htmlspecialchars($f_review) ?>"><?php endif; ?>
          <div class="cu-row">
            <div class="cu-field cu-field--grow">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
              <input class="cu-input" type="search" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Search subject" aria-label="Search subjects">
            </div>
            <select class="cu-select" name="school_year" aria-label="School year" onchange="this.form.submit()">
              <?php foreach ($all_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $f_year === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?><?= $y === $default_year ? ' (current)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="view-toggle" role="group" aria-label="Switch view">
              <button type="button" class="view-toggle-btn active" data-view="list" aria-pressed="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>List
              </button>
              <button type="button" class="view-toggle-btn" data-view="grouped" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18M3 12h18M3 19h18"/><path d="M7 8.5h10M7 15.5h10" opacity=".45"/></svg>Grouped
              </button>
            </div>
          </div>

          <div class="cu-row cu-row--filters" data-view-pane="list">
            <div class="cu-row-l">
              <select class="cu-select" name="grade_level" aria-label="Grade level" onchange="this.form.submit()">
                <option value="">All grades</option>
                <?php foreach ($valid_grade_levels as $g): ?>
                  <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                <?php endforeach; ?>
              </select>
              <select class="cu-select" name="strand" aria-label="Strand" onchange="this.form.submit()">
                <option value="">All strands</option>
                <?php foreach ($valid_strands as $st): ?>
                  <option value="<?= $st ?>" <?= $f_strand === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($f_q !== '' || $f_grade !== '' || $f_strand !== ''): ?>
                <a class="cu-clear" href="<?= htmlspecialchars($list_url(['q' => '', 'grade_level' => '', 'strand' => ''])) ?>">Clear</a>
              <?php endif; ?>
            </div>
            <div class="cu-seg" role="group" aria-label="Filter by approval">
              <?php foreach ($filter_labels as $key => $label): ?>
                <a href="<?= htmlspecialchars($list_url(['review_status' => $key])) ?>" <?= $f_review === $key ? 'aria-current="true"' : '' ?>>
                  <?= $label ?><span class="ct"><?= $status_counts[$key] ?? 0 ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </form>

          <div id="viewList" data-view-pane="list">
          <?php if (empty($rows)): ?>
            <div class="cu-empty">
              <h2>No subjects found</h2>
              <p><?= ($f_q !== '' || $f_grade !== '' || $f_strand !== '' || $f_review !== '') ? 'Nothing matches these filters.' : 'SY ' . htmlspecialchars($f_year) . ' has no subjects yet.' ?></p>
            </div>
          <?php else: ?>
            <div class="cu-scroll">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Subject</th>
                    <th>Grade / Strand</th>
                    <th>Taking</th>
                    <th>Approval</th>
                    <th>Status</th>
                    <?php if ($is_reviewer): ?><th>Action</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r) render_subject_row($r, $is_reviewer); ?>
                </tbody>
              </table>
            </div>

            <div class="cu-pager">
              <span>Showing <?= ($page - 1) * $per_page + 1 ?>&ndash;<?= min($page * $per_page, $total_count) ?> of <?= $total_count ?></span>
              <?php if ($total_pages > 1): ?>
                <nav class="cu-pages" aria-label="Pages">
                  <?php if ($page > 1): ?><a href="<?= htmlspecialchars($list_url(['page' => $page - 1])) ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
                  <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <?php if ($p === $page): ?>
                      <span class="cur" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                      <a href="<?= htmlspecialchars($list_url(['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?><a href="<?= htmlspecialchars($list_url(['page' => $page + 1])) ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          </div>

            <!-- Grouped view: the year's approved subjects (no filters, no
                 paging), one collapsible section per Grade + Strand. A
                 single table keeps the columns lined up. -->
            <div id="viewGrouped" data-view-pane="grouped" hidden>
            <?php if (empty($groups)): ?>
              <div class="cu-empty">
                <h2>No subjects found</h2>
                <p><?= $f_q !== '' ? 'No approved subject matches your search.' : 'SY ' . htmlspecialchars($f_year) . ' has no approved subjects yet.' ?></p>
              </div>
            <?php else: ?>
              <div class="grp-tools">
                <span><?= $grouped_count ?> subject<?= $grouped_count === 1 ? '' : 's' ?> in <?= count($groups) ?> group<?= count($groups) === 1 ? '' : 's' ?></span>
                <span class="grp-actions">
                  <button type="button" class="grp-tool-btn" id="grpExpandAll">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></svg>Expand all
                  </button>
                  <button type="button" class="grp-tool-btn" id="grpCollapseAll">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 20 5-5 5 5M7 4l5 5 5-5"/></svg>Collapse all
                  </button>
                </span>
              </div>
              <table class="data-table grp-table">
                <colgroup>
                  <col class="grp-c-name"><col class="grp-c-num"><col class="grp-c-badge">
                  <?php if ($is_reviewer): ?><col class="grp-c-act"><?php endif; ?>
                </colgroup>
                <thead>
                  <tr>
                    <th>Subject</th>
                    <th>Taking</th>
                    <th>Status</th>
                    <?php if ($is_reviewer): ?><th>Action</th><?php endif; ?>
                  </tr>
                </thead>
                <?php foreach ($groups as $gkey => $g_rows):
                  [$g_grade, $g_strand] = explode('|', $gkey);
                  $g_id = 'grp-' . $g_grade . '-' . preg_replace('/[^A-Za-z0-9]/', '', $g_strand);
                ?>
                  <tbody class="grp" data-group="<?= htmlspecialchars($gkey) ?>">
                    <tr class="grp-head">
                      <td colspan="<?= $is_reviewer ? 4 : 3 ?>">
                        <button type="button" class="grp-btn" aria-expanded="true" aria-controls="<?= $g_id ?>">
                          <span class="grp-title">Grade <?= htmlspecialchars($g_grade) ?></span>
                          <span class="strand-badge strand-badge--<?= strand_badge_class($g_strand) ?>"><?= htmlspecialchars($g_strand) ?></span>
                          <span class="grp-count"><?= count($g_rows) ?> subject<?= count($g_rows) === 1 ? '' : 's' ?></span>
                          <svg class="grp-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                  <tbody class="grp-rows" id="<?= $g_id ?>" data-group="<?= htmlspecialchars($gkey) ?>">
                    <?php foreach ($g_rows as $r) render_subject_row($r, $is_reviewer, true); ?>
                  </tbody>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>
            </div>
      </section>

    </div>
  </div>

  <?php if (!$is_reviewer): ?>
  <!-- Propose Subject modal — opened from the header button; reopens itself
       with the error inside when a submission fails. Schedulers only. -->
  <div class="sch-overlay<?= $add_error ? '' : ' hidden' ?>" id="addSubjectOverlay" role="dialog" aria-modal="true" aria-labelledby="addSubjectTitle">
    <div class="sch-modal">
      <div class="sch-modal-header">
        <div>
          <h2 id="addSubjectTitle">Propose subject</h2>
          <p class="sch-modal-sub">Adds to SY <?= htmlspecialchars($f_year) ?>. Needs coordinator approval before it can be assigned to sections.</p>
        </div>
        <button type="button" class="sch-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($add_error): ?>
        <div class="notice notice-error"><?= htmlspecialchars($add_error) ?></div>
      <?php endif; ?>

      <form method="POST" data-confirm="Submit this subject for coordinator approval?" data-icon="question">
        <div class="sch-form-grid">
          <div class="form-group form-group--wide">
            <label for="f_subject_name">Subject name <span class="req">*</span></label>
            <input id="f_subject_name" type="text" name="subject_name" placeholder="e.g. Empowerment Technologies" maxlength="100"
                   value="<?= htmlspecialchars($_POST['subject_name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label for="f_grade_level">Grade level <span class="req">*</span></label>
            <select id="f_grade_level" name="grade_level" required>
              <option value="">Select</option>
              <?php foreach ($valid_grade_levels as $g): ?>
                <option value="<?= $g ?>" <?= ($_POST['grade_level'] ?? '') === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="f_strand">Strand <span class="req">*</span></label>
            <select id="f_strand" name="strand" required>
              <option value="">Select</option>
              <?php foreach ($valid_strands as $s): ?>
                <option value="<?= $s ?>" <?= ($_POST['strand'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="sch-modal-actions">
          <button type="button" class="sch-btn sch-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="add_subject" class="sch-btn sch-btn--primary">Submit for approval</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_reviewer): ?>
  <!-- Edit Subject modal — filled from the row ⋮ menu's data-*; reopens with
       the error and what was typed when a save fails. -->
  <div class="sch-overlay<?= $edit_error ? '' : ' hidden' ?>" id="editSubjectOverlay" role="dialog" aria-modal="true" aria-labelledby="editSubjectTitle">
    <div class="sch-modal">
      <div class="sch-modal-header">
        <div>
          <h2 id="editSubjectTitle">Edit subject</h2>
          <p class="sch-modal-sub" id="es_meta"><?= htmlspecialchars($_POST['meta'] ?? '') ?></p>
        </div>
        <button type="button" class="sch-modal-close" data-close-modal aria-label="Close">&times;</button>
      </div>

      <?php if ($edit_error): ?>
        <div class="notice notice-error"><?= htmlspecialchars($edit_error) ?></div>
      <?php endif; ?>

      <form method="POST" data-confirm="Save changes to this subject?" data-icon="question">
        <input type="hidden" name="subject_id" id="es_id" value="<?= (int)($_POST['subject_id'] ?? 0) ?>">
        <input type="hidden" name="meta" id="es_meta_in" value="<?= htmlspecialchars($_POST['meta'] ?? '') ?>">
        <div class="form-group">
          <label for="es_name">Subject name <span class="req">*</span></label>
          <input id="es_name" type="text" name="subject_name" maxlength="100" required
                 value="<?= htmlspecialchars($_POST['subject_name'] ?? '') ?>">
        </div>
        <div class="sch-modal-actions">
          <button type="button" class="sch-btn sch-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="edit_subject" class="sch-btn sch-btn--primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (count($all_years) > 1): ?>
  <!-- Carry Over modal — the subject list is rendered by the script below
       from $carry_data, so changing years/search/strand never reloads the
       page. Reopens itself with the error (and the picks) after a failed submit. -->
  <div class="sch-overlay<?= ($carry_error || $f_source_year !== '') ? '' : ' hidden' ?>" id="carryOverlay" role="dialog" aria-modal="true" aria-labelledby="carryTitle">
    <div class="sch-modal co-modal">
      <div class="co-head">
        <div class="sch-modal-header co-header">
          <div>
            <h2 id="carryTitle">Carry over subjects</h2>
            <p class="sch-modal-sub">Pick subjects to copy into another school year. <?= $is_reviewer ? 'They\'re added directly as approved.' : 'Each one still needs coordinator approval.' ?></p>
          </div>
          <button type="button" class="sch-modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>

        <?php if ($carry_error): ?>
          <div class="notice notice-error"><?= htmlspecialchars($carry_error) ?></div>
        <?php endif; ?>
      </div>

      <form method="POST" id="carryForm" class="co-form" data-confirm="Carry the selected subjects over?" data-icon="question">
        <div class="co-controls">
          <div class="co-years">
            <label for="carrySource">Copy from</label>
            <select id="carrySource" name="source_year">
              <?php foreach ($all_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $carry_source_year === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="carryTarget">into</label>
            <select id="carryTarget" name="target_year">
              <?php foreach ($all_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $carry_target_year === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="co-search-row">
            <div class="co-search">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
              <input type="search" id="carrySearch" placeholder="Search subjects" aria-label="Search subjects" autocomplete="off">
            </div>
            <button type="button" class="co-link" id="carrySelectShown">Select all shown</button>
            <button type="button" class="co-link co-link--muted" id="carryClear">Clear</button>
          </div>

          <div class="co-chips" role="group" aria-label="Filter by strand">
            <button type="button" class="co-chip is-on" data-strand="" aria-pressed="true">All strands</button>
            <?php foreach ($carry_strands as $cs): ?>
              <button type="button" class="co-chip" data-strand="<?= htmlspecialchars($cs) ?>" aria-pressed="false"><?= htmlspecialchars($cs) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="co-list" id="carryList" aria-live="polite"></div>
        <div id="carryPicked" hidden></div>

        <div class="co-foot">
          <span class="co-count" id="carryCount">0 subjects selected</span>
          <button type="button" class="sch-btn sch-btn--ghost" data-close-modal>Cancel</button>
          <button type="submit" name="bulk_copy_subjects" value="1" class="sch-btn sch-btn--primary" id="carrySubmit" disabled>Carry over</button>
        </div>
      </form>
    </div>
  </div>
  <script type="application/json" id="carryData"><?= json_encode([
      'years'  => $carry_data,
      'picked' => $carry_picked,
  ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>

  <!-- Drill-down Modal: students currently taking this subject -->
  <dialog id="subjectModal" aria-labelledby="modalTitle">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Subject Enrollees</span>
      <button class="modal-close" aria-label="Close"
              onclick="document.getElementById('subjectModal').close()">&times;</button>
    </div>
    <div class="modal-meta" id="modalMeta"></div>
    <div id="modalBody">
      <p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>
    </div>
  </dialog>

  <script>
    // Header-button modals (staff_manage.php pattern): Propose subject, Carry over
    [['btnOpenAddSubject', 'addSubjectOverlay', 'f_subject_name'],
     ['btnOpenCarry',      'carryOverlay',      'carrySource']].forEach(([btnId, ovId, focusId]) => {
      const btn = document.getElementById(btnId), ov = document.getElementById(ovId);
      if (!btn || !ov) return;
      btn.addEventListener('click', () => {
        ov.classList.remove('hidden');
        document.getElementById(focusId)?.focus();
      });
      ov.addEventListener('click', e => {
        if (e.target === ov || e.target.closest('[data-close-modal]')) ov.classList.add('hidden');
      });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') ov.classList.add('hidden'); });
    });

    // ── Row ⋮ menu + Edit Subject modal (same as sections.php) ───────────────
    (function () {
      const editOv = document.getElementById('editSubjectOverlay');
      if (!editOv) return;
      const closeMenus = () => document.querySelectorAll('.row-menu.open').forEach(m => {
        m.classList.remove('open');
        m.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', 'false');
      });
      // The dropdown is position:fixed — place it under the button, or above
      // it when it wouldn't fit below.
      const place = (menu, btn) => {
        const dd = menu.querySelector('.row-menu-dropdown');
        const r  = btn.getBoundingClientRect();
        const below = window.innerHeight - r.bottom >= dd.offsetHeight + 12;
        dd.style.top  = (below ? r.bottom + 4 : r.top - dd.offsetHeight - 4) + 'px';
        dd.style.left = Math.max(8, r.right - dd.offsetWidth) + 'px';
      };
      const openEdit = d => {
        document.getElementById('es_id').value      = d.id;
        document.getElementById('es_name').value    = d.name;
        document.getElementById('es_meta').textContent = d.meta;
        document.getElementById('es_meta_in').value = d.meta;
        editOv.querySelector('.notice-error')?.remove();
        editOv.classList.remove('hidden');
        document.getElementById('es_name').focus();
      };

      document.addEventListener('click', e => {
        const toggle = e.target.closest('[data-menu-toggle]');
        if (toggle) {
          const menu = toggle.closest('.row-menu');
          const wasOpen = menu.classList.contains('open');
          closeMenus();
          if (!wasOpen) {
            place(menu, toggle);
            menu.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
          }
          return;
        }
        const act = e.target.closest('.row-menu [data-action="edit"]');
        if (act) { const d = act.closest('.row-menu').dataset; closeMenus(); openEdit(d); return; }
        if (!e.target.closest('.row-menu')) closeMenus();
      });
      // A fixed dropdown would drift from its button on scroll — just close it.
      window.addEventListener('scroll', closeMenus, true);
      window.addEventListener('resize', closeMenus);

      editOv.addEventListener('click', e => {
        if (e.target === editOv || e.target.closest('[data-close-modal]')) editOv.classList.add('hidden');
      });
      document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        closeMenus();
        editOv.classList.add('hidden');
      });
    })();

    // ── List / Grouped view toggle ───────────────────────────────────────────
    // Both views are rendered server-side; this only swaps which one shows.
    // The chosen view and which groups are collapsed are remembered per
    // browser, so paging, filtering or approving keeps you where you were.
    (function () {
      const btns = [...document.querySelectorAll('.view-toggle-btn')];
      const panes = [...document.querySelectorAll('[data-view-pane]')];
      if (!btns.length) return;
      const store = {
        get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
      };
      const VIEW_KEY = 'curriculum.view', COL_KEY = 'curriculum.collapsed';

      function show(view) {
        btns.forEach(b => { const on = b.dataset.view === view; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on); });
        panes.forEach(p => { p.hidden = p.dataset.viewPane !== view; });
      }
      btns.forEach(b => b.addEventListener('click', () => { show(b.dataset.view); store.set(VIEW_KEY, b.dataset.view); }));
      show(store.get(VIEW_KEY) === 'grouped' ? 'grouped' : 'list');

      let collapsed;
      try { collapsed = new Set(JSON.parse(store.get(COL_KEY) || '[]')); } catch (e) { collapsed = new Set(); }
      const heads = [...document.querySelectorAll('tbody.grp')];
      function setGroup(head, isCol) {
        const rows = head.nextElementSibling;
        head.classList.toggle('is-collapsed', isCol);
        rows.classList.toggle('is-collapsed', isCol);
        head.querySelector('.grp-btn').setAttribute('aria-expanded', !isCol);
        isCol ? collapsed.add(head.dataset.group) : collapsed.delete(head.dataset.group);
      }
      const save = () => store.set(COL_KEY, JSON.stringify([...collapsed]));
      heads.forEach(h => {
        setGroup(h, collapsed.has(h.dataset.group));
        h.querySelector('.grp-btn').addEventListener('click', () => { setGroup(h, !h.classList.contains('is-collapsed')); save(); });
      });
      document.getElementById('grpExpandAll')?.addEventListener('click', () => { heads.forEach(h => setGroup(h, false)); save(); });
      document.getElementById('grpCollapseAll')?.addEventListener('click', () => { heads.forEach(h => setGroup(h, true)); save(); });
    })();

    // ── Carry Over modal ─────────────────────────────────────────────────────
    // Renders the grouped, filterable subject list from #carryData. Picks are
    // kept in a Set (not in the visible checkboxes), so searching or switching
    // strand never drops a subject you already ticked; they're posted through
    // hidden inputs in #carryPicked.
    (function () {
      const dataEl = document.getElementById('carryData');
      if (!dataEl) return;
      const DATA = JSON.parse(dataEl.textContent);
      const $id = id => document.getElementById(id);
      const srcEl = $id('carrySource'), tgtEl = $id('carryTarget'), searchEl = $id('carrySearch');
      const listEl = $id('carryList'), pickedEl = $id('carryPicked'), countEl = $id('carryCount');
      const submitEl = $id('carrySubmit'), formEl = $id('carryForm');
      const chips = [...document.querySelectorAll('.co-chip')];

      const picked = new Set((DATA.picked || []).map(Number));
      const collapsed = new Set();
      let strand = '';

      const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const key = r => r[1].trim().toLowerCase() + '|' + r[2] + '|' + r[3];
      const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');

      // Current view: copyable subjects of the source year, each flagged
      // "already" when the target year has the same name/grade/strand.
      function view() {
        const src = srcEl.value, tgt = tgtEl.value;
        const inTarget = new Set((DATA.years[tgt] || []).map(key));
        const q = searchEl.value.trim().toLowerCase();
        return (DATA.years[src] || [])
          .filter(r => r[4] === 1)
          .filter(r => !strand || r[3] === strand)
          .filter(r => !q || r[1].toLowerCase().includes(q))
          .map(r => ({ id: r[0], name: r[1], grade: r[2], strand: r[3], already: inTarget.has(key(r)) }));
      }

      // Drop picks that aren't carryable any more (year changed underneath them).
      function prunePicked() {
        const tgt = tgtEl.value;
        const inTarget = new Set((DATA.years[tgt] || []).map(key));
        const ok = new Set((DATA.years[srcEl.value] || []).filter(r => r[4] === 1 && !inTarget.has(key(r))).map(r => r[0]));
        [...picked].forEach(id => { if (!ok.has(id)) picked.delete(id); });
      }

      function render() {
        const same = srcEl.value === tgtEl.value;
        const rows = same ? [] : view();
        let html = '';
        if (same) {
          html = '<p class="co-empty">Pick two different school years to copy between.</p>';
        } else if (!rows.length) {
          html = '<p class="co-empty">' + ((DATA.years[srcEl.value] || []).some(r => r[4] === 1)
            ? 'No subjects match your search.' : 'SY ' + esc(srcEl.value) + ' has no approved subjects to copy.') + '</p>';
        } else {
          const grades = [...new Set(rows.map(r => r.grade))];
          grades.forEach(g => {
            const gRows = rows.filter(r => r.grade === g);
            const elig  = gRows.filter(r => !r.already);
            const nSel  = elig.filter(r => picked.has(r.id)).length;
            const isCol = collapsed.has(g);
            html += '<div class="co-group' + (isCol ? ' is-collapsed' : '') + '" data-grade="' + esc(g) + '">'
              + '<div class="co-group-head">'
              +   '<label class="co-check"><input type="checkbox" class="co-group-box"' + (elig.length ? '' : ' disabled')
              +     (elig.length && nSel === elig.length ? ' checked' : '') + ' data-partial="' + (nSel > 0 && nSel < elig.length ? 1 : 0) + '">'
              +     '<span class="co-group-title">Grade ' + esc(g) + '</span></label>'
              +   (strand ? '<span class="co-tag">' + esc(strand) + '</span>' : '')
              +   '<button type="button" class="co-toggle" aria-expanded="' + !isCol + '">'
              +     nSel + ' of ' + elig.length + ' selected'
              +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
              +   '</button>'
              + '</div><ul class="co-rows">';
            gRows.forEach(r => {
              html += '<li class="co-row' + (r.already ? ' is-already' : '') + '">'
                + '<label class="co-check"><input type="checkbox" class="co-box" value="' + r.id + '"'
                +   (r.already ? ' disabled' : (picked.has(r.id) ? ' checked' : '')) + '>'
                +   '<span class="co-name" title="' + esc(r.name) + '">' + esc(r.name) + '</span></label>'
                + (!strand ? '<span class="co-tag co-tag--row">' + esc(r.strand) + '</span>' : '')
                + (r.already ? '<span class="co-already">Already in SY ' + esc(tgtEl.value) + '</span>' : '')
                + '</li>';
            });
            html += '</ul></div>';
          });
        }
        listEl.innerHTML = html;
        listEl.querySelectorAll('.co-group-box[data-partial="1"]').forEach(b => { b.indeterminate = true; });
        syncFoot();
      }

      function syncFoot() {
        const n = picked.size;
        countEl.textContent = plural(n, 'subject') + ' selected';
        submitEl.disabled = n === 0 || srcEl.value === tgtEl.value;
        submitEl.textContent = n ? 'Carry over ' + plural(n, 'subject') : 'Carry over';
        formEl.dataset.confirm = 'Carry ' + plural(n, 'subject') + ' from SY ' + srcEl.value + ' into SY ' + tgtEl.value + '?';
        pickedEl.innerHTML = [...picked].map(id => '<input type="hidden" name="subject_id[]" value="' + id + '">').join('');
      }

      listEl.addEventListener('change', e => {
        const box = e.target;
        if (box.classList.contains('co-box')) {
          box.checked ? picked.add(+box.value) : picked.delete(+box.value);
        } else if (box.classList.contains('co-group-box')) {
          const grade = box.closest('.co-group').dataset.grade;
          view().filter(r => r.grade === grade && !r.already).forEach(r => box.checked ? picked.add(r.id) : picked.delete(r.id));
        } else return;
        render();
      });
      listEl.addEventListener('click', e => {
        const t = e.target.closest('.co-toggle');
        if (!t) return;
        const g = t.closest('.co-group').dataset.grade;
        collapsed.has(g) ? collapsed.delete(g) : collapsed.add(g);
        render();
      });
      chips.forEach(c => c.addEventListener('click', () => {
        strand = c.dataset.strand;
        chips.forEach(x => { const on = x === c; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on); });
        render();
      }));
      searchEl.addEventListener('input', render);
      srcEl.addEventListener('change', () => { picked.clear(); render(); });
      tgtEl.addEventListener('change', () => { prunePicked(); render(); });
      $id('carrySelectShown').addEventListener('click', () => { view().forEach(r => { if (!r.already) picked.add(r.id); }); render(); });
      $id('carryClear').addEventListener('click', () => { picked.clear(); render(); });

      prunePicked();
      render();
    })();

    // Reject-with-reason: SWAL textarea (required), then submit
    document.querySelectorAll('.js-reject').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('.reject-form');
        const name = btn.dataset.name;
        Swal.fire({
          title: 'Reject "' + name + '"?',
          input: 'textarea',
          inputPlaceholder: 'Reason (required)',
          inputAttributes: { style: 'resize:vertical;max-width:100%;box-sizing:border-box;' },
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#C0392B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Reject',
          cancelButtonText: 'Cancel',
          inputValidator: (value) => {
            if (!value || !value.trim()) return 'A reason is required.';
          }
        }).then((result) => {
          if (!result.isConfirmed) return;
          form.querySelector('input[name="rejection_reason"]').value = result.value.trim();
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'reject_subject';
          hidden.value = '1';
          form.appendChild(hidden);
          form.submit();
        });
      });
    });

    const dlg = document.getElementById('subjectModal');
    let dlgOpenedAt = 0;

    dlg.addEventListener('click', function(e) {
      // Second click of a double-click lands on the backdrop right after
      // opening — don't let it close the modal straight away.
      if (Date.now() - dlgOpenedAt < 400) return;
      const rect = dlg.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right ||
          e.clientY < rect.top  || e.clientY > rect.bottom) {
        dlg.close();
      }
    });

    function openModal(subjectId, subjectName) {
      if (dlg.open) return;
      dlgOpenedAt = Date.now();
      document.getElementById('modalTitle').textContent = subjectName;
      document.getElementById('modalMeta').textContent  = 'Students currently enrolled in or taking this subject';
      document.getElementById('modalBody').innerHTML    = '<p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>';
      dlg.showModal();

      fetch('curriculum?action=students&subject_id=' + subjectId)
        .then(r => r.json())
        .then(data => {
          if (!data.length) {
            document.getElementById('modalBody').innerHTML = '<p style="color:#8A8A9A;font-size:13px;text-align:center;padding:1.5rem 0;">No students currently taking this subject.</p>';
            return;
          }
          let html = '<table class="student-list"><thead><tr><th>#</th><th>Name</th><th>Student Number</th><th>Section</th><th>Status</th></tr></thead><tbody>';
          data.forEach((s, i) => {
            const badgeCls = s.status === 'enrolled' ? 'badge-enrolled' : 'badge-pending2';
            html += '<tr>'
              + '<td style="color:#8A8A9A;font-size:12px;">' + (i+1) + '</td>'
              + '<td style="font-weight:500">' + esc(s.family_name) + ', ' + esc(s.given_name) + '</td>'
              + '<td style="font-family:monospace;font-size:12px;color:var(--brand-primary)">' + (s.student_number || '—') + '</td>'
              + '<td style="font-size:12px;color:#5A5A72">' + esc(s.section_name || '—') + '</td>'
              + '<td><span class="badge ' + badgeCls + '">' + s.status + '</span></td>'
              + '</tr>';
          });
          html += '</tbody></table>';
          document.getElementById('modalBody').innerHTML = html;
        })
        .catch(() => {
          document.getElementById('modalBody').innerHTML = '<p style="color:#C0392B;font-size:13px;">Failed to load students.</p>';
        });
    }

    function esc(str) {
      return String(str || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
  </script>

</body>
</html>