<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('admin');

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

// "Family, Given[ Middle]" -> two-letter avatar initials — same technique as
// enrollments.php's name_initials() / staff_manage.php's staff_initials().
function name_initials(string $commaName): string {
    $parts  = array_map('trim', explode(',', $commaName, 2));
    $family = $parts[0] ?? '';
    $given  = $parts[1] ?? '';
    $initials = mb_strtoupper(mb_substr($family, 0, 1) . mb_substr($given, 0, 1));
    return $initials !== '' ? $initials : '?';
}

// ── Fetch ──────────────────────────────────────────────────────────────────
// Every student is loaded once; search / filters / sort / paging all happen in
// the browser (assets/js/admin_table.js), the same way staff_manage.php works.
$sql = "
    SELECT s.student_id, s.student_number,
           s.family_name, s.given_name, s.middle_name,
           s.sex, s.date_of_birth, s.email, s.contact_number,
           CONCAT(s.family_name, ', ', s.given_name,
                  IFNULL(CONCAT(' ', s.middle_name), '')) AS full_name,
           (SELECT e.admission_grade_level FROM enrollments e
            WHERE e.student_id = s.student_id
            ORDER BY e.enrollment_date DESC LIMIT 1) AS last_grade,
           (SELECT strd.strand_code FROM enrollments e
            JOIN strands strd ON strd.strand_id = e.admission_strand
            WHERE e.student_id = s.student_id
            ORDER BY e.enrollment_date DESC LIMIT 1) AS last_strand,
           (SELECT COUNT(*) FROM enrollments e
            WHERE e.student_id = s.student_id AND e.status='enrolled') AS enrolled_count
    FROM students s
    ORDER BY s.family_name ASC, s.given_name ASC
";
$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();

// ── Export CSV ───────────────────────────────────────────────────────────
// POSTed by the page (ids can run to thousands — too long for a URL); a GET
// ?export=csv still works. `ids` (comma list) limits it to those students —
// the checked rows, or whatever the filters currently show; none = everyone.
$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if (($req['export'] ?? '') === 'csv') {
    $export_rows = $rows;
    if (!empty($req['ids'])) {
        $selected_ids = array_map('intval', explode(',', (string)$req['ids']));
        $export_rows = array_values(array_filter($rows, function ($r) use ($selected_ids) {
            return in_array((int)$r['student_id'], $selected_ids, true);
        }));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Student Number', 'Sex', 'Birthdate', 'Email', 'Contact Number', 'Latest Grade', 'Latest Strand', 'Currently Enrolled']);
    foreach ($export_rows as $r) {
        fputcsv($out, [
            $r['full_name'],
            $r['student_number'] ?? '',
            $r['sex'] ?? '',
            $r['date_of_birth'] ?? '',
            $r['email'] ?? '',
            $r['contact_number'] ?? '',
            $r['last_grade'] ?? '',
            $r['last_strand'] ?? '',
            (int)$r['enrolled_count'] > 0 ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit();
}

$sexes  = array_values(array_unique(array_filter(array_column($rows, 'sex'))));
$grades = array_values(array_unique(array_filter(array_column($rows, 'last_grade'))));
sort($sexes);
sort($grades);
$count_enrolled = count(array_filter($rows, fn($r) => (int)$r['enrolled_count'] > 0));

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$svg_mail  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
$svg_phone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Students — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero staff-hero--row">
        <div class="staff-hero-text">
          <p class="page-eyebrow">Admin Portal</p>
          <h1 class="page-title">Students</h1>
          <p class="staff-hero-desc">Look up any student and see their contact details and most recent enrollment.</p>
        </div>
        <?php if ($rows): ?>
          <div class="staff-hero-actions">
            <button type="button" class="btn-export-csv" id="tblExport">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
              <span id="tblExportLabel">Export CSV</span>
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="staff-overlap">

      <div class="card staff-panel">
        <?php if (empty($rows)): ?>
          <p class="empty-state">No students yet.</p>
        <?php else: ?>

        <div class="staff-toolbar">
          <div class="staff-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <label for="tblSearch" class="sr-only">Search students</label>
            <input type="search" id="tblSearch" placeholder="Search by name, student number or email" autocomplete="off">
          </div>
          <select class="staff-filter-select" name="sex" data-filter-select data-attr="sex" aria-label="Filter by sex">
            <option value="">All sexes</option>
            <?php foreach ($sexes as $sx): ?>
              <option value="<?= $h($sx) ?>"><?= $h($sx) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="staff-filter-select" name="grade" data-filter-select data-attr="grade" aria-label="Filter by latest grade">
            <option value="">All grades</option>
            <?php foreach ($grades as $g): ?>
              <option value="<?= $h($g) ?>">Grade <?= $h($g) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="staff-filter-select" id="tblSort" aria-label="Sort students">
            <option value="name:asc">Sort: Name (A to Z)</option>
            <option value="name:desc">Sort: Name (Z to A)</option>
            <option value="number:asc">Sort: Student number</option>
            <option value="id:desc" data-num>Sort: Recently added</option>
          </select>
          <button type="button" class="staff-toolbar-reset hidden" id="tblReset">Reset filters</button>
        </div>

        <div class="staff-pills" role="group" aria-label="Filter by enrollment" data-pills data-attr="enrollment" data-param="enrollment">
          <button type="button" class="staff-pill active" data-value="" aria-pressed="true">All <span data-count><?= count($rows) ?></span></button>
          <button type="button" class="staff-pill" data-value="enrolled" aria-pressed="false">Enrolled <span data-count><?= $count_enrolled ?></span></button>
          <button type="button" class="staff-pill" data-value="none" aria-pressed="false">Not enrolled <span data-count><?= count($rows) - $count_enrolled ?></span></button>
        </div>

        <div class="staff-bulkbar hidden" id="tblBulkBar" role="region" aria-label="Bulk actions">
          <strong class="staff-bulk-count" id="tblBulkCount" aria-live="polite">0 selected</strong>
          <button type="button" class="staff-bulk-btn" data-bulk="export">Export selected</button>
          <button type="button" class="staff-bulk-clear" data-bulk="clear">Clear selection</button>
        </div>

        <table class="data-table staff-table" id="dataTable">
          <thead>
            <tr>
              <th class="staff-table-checkbox-col"><input type="checkbox" id="tblSelectAll" aria-label="Select all on this page"></th>
              <th>Student</th>
              <th>Contact</th>
              <th>Sex</th>
              <th>Birthdate</th>
              <th>Latest enrollment</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $is_enrolled = (int)$r['enrolled_count'] > 0;
              $dob         = $r['date_of_birth'] ? strtotime($r['date_of_birth']) : 0;
              $search      = mb_strtolower(implode(' ', [
                  $r['family_name'], $r['given_name'], $r['middle_name'] ?? '',
                  $r['given_name'] . ' ' . $r['family_name'],
                  $r['student_number'] ?? '', $r['email'] ?? '',
              ]));
            ?>
              <tr class="<?= $is_enrolled ? '' : 'is-dim' ?>"
                  data-search="<?= $h($search) ?>"
                  data-enrollment="<?= $is_enrolled ? 'enrolled' : 'none' ?>"
                  data-sex="<?= $h($r['sex']) ?>"
                  data-grade="<?= $h($r['last_grade'] ?? '') ?>"
                  data-sort-name="<?= $h(mb_strtolower($r['full_name'])) ?>"
                  data-sort-number="<?= $h($r['student_number'] ?? '') ?>"
                  data-sort-id="<?= (int)$r['student_id'] ?>">
                <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$r['student_id'] ?>" aria-label="Select <?= $h($r['full_name']) ?>"></td>
                <td class="td-name">
                  <div class="td-name-cell">
                    <span class="staff-table-avatar"><?= $h(name_initials($r['full_name'])) ?></span>
                    <span class="staff-name-stack">
                      <span class="staff-name-primary"><?= $h($r['full_name']) ?></span>
                      <span class="staff-name-secondary"><?= $h($r['student_number'] ?? 'No student number') ?></span>
                    </span>
                  </div>
                </td>
                <td>
                  <?php if (!empty($r['email'])): ?>
                    <div class="staff-contact-line"><?= $svg_mail ?><span><?= $h($r['email']) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($r['contact_number'])): ?>
                    <div class="staff-contact-line"><?= $svg_phone ?><span><?= $h($r['contact_number']) ?></span></div>
                  <?php endif; ?>
                  <?php if (empty($r['email']) && empty($r['contact_number'])): ?><span class="td-muted">—</span><?php endif; ?>
                </td>
                <td><?= $h($r['sex'] ?: '—') ?></td>
                <td>
                  <?php if ($dob): ?>
                    <span class="staff-joined"><?= $h(date('M j, Y', $dob)) ?></span>
                  <?php else: ?><span class="td-muted">—</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($r['last_grade']): ?>
                    <span class="staff-role-tag">Grade <?= $h($r['last_grade']) ?> &middot; <?= $h($r['last_strand']) ?></span>
                  <?php else: ?>
                    <span class="td-muted">None yet</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="staff-state <?= $is_enrolled ? 'staff-state--active' : 'staff-state--muted' ?>"><i></i><?= $is_enrolled ? 'Enrolled' : 'Not enrolled' ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="empty-state hidden" data-no-match>
          <strong>No students match these filters</strong>
          Try a different name, student number or email.
          <div><button type="button" class="staff-btn staff-btn--ghost" data-clear-filters>Clear filters</button></div>
        </div>

        <?php $tbl_total = count($rows); $tbl_noun = 'students'; include BASE_PATH . '/shared/includes/admin_table_footer.php'; ?>

        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <?php if ($rows): ?>
    <script src="<?= APP_URL ?>/assets/js/admin_table.js?v=<?= filemtime(__DIR__ . '/../../assets/js/admin_table.js') ?>"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        AdminTable.init({ noun: 'students' });
      });
    </script>
  <?php endif; ?>

</body>
</html>
