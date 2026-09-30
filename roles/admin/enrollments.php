<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('admin');

// "Family, Given[ Middle...]" -> two-letter avatar initials, same technique
// as staff_manage.php's staff_initials() (first letter of each side of the
// comma), just fed a pre-concatenated name instead of separate columns.
function name_initials(string $commaName): string {
    $parts  = array_map('trim', explode(',', $commaName, 2));
    $family = $parts[0] ?? '';
    $given  = $parts[1] ?? '';
    $initials = mb_strtoupper(mb_substr($family, 0, 1) . mb_substr($given, 0, 1));
    return $initials !== '' ? $initials : '?';
}

// status => [label, .staff-state modifier]. Order is the order of the filter pills.
$status_map = [
    'enrolled'     => ['Enrolled',     'staff-state--active'],
    'pre_enrolled' => ['Pre-enrolled', 'staff-state--info'],
    'pending'      => ['Pending',      'staff-state--pending'],
    'expired'      => ['Expired',      'staff-state--muted'],
    'cancelled'    => ['Cancelled',    'staff-state--danger'],
];

// ── Fetch ──────────────────────────────────────────────────────────────────
// Every record is loaded once; search / status / grade / strand / sort and
// paging all happen in the browser (assets/js/admin_table.js), the same way
// staff_manage.php works. CSV export re-uses $rows below.
$sql = "
    SELECT e.enrollment_id,
           CONCAT(st.family_name, ', ', st.given_name) AS student_name,
           st.family_name, st.given_name,
           st.student_number, e.admission_grade_level AS grade_level, strd.strand_code AS strand, sec.section_name,
           e.school_year, e.enrollment_date, e.status
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
";
$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();

// ── Export CSV ───────────────────────────────────────────────────────────
// POSTed by the page (ids can run to thousands — too long for a URL); a GET
// ?export=csv still works. `ids` (comma list) limits it to those enrollments —
// the checked rows, or whatever the filters currently show; none = everything.
$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if (($req['export'] ?? '') === 'csv') {
    $export_rows = $rows;
    if (!empty($req['ids'])) {
        $selected_ids = array_map('intval', explode(',', (string)$req['ids']));
        $export_rows = array_values(array_filter($rows, function ($r) use ($selected_ids) {
            return in_array((int)$r['enrollment_id'], $selected_ids, true);
        }));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="enrollments_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Enrollment ID', 'Student', 'Student Number', 'Grade', 'Strand', 'Section', 'Date', 'Status', 'School Year']);
    foreach ($export_rows as $r) {
        fputcsv($out, [
            $r['enrollment_id'],
            $r['student_name'],
            $r['student_number'] ?? '',
            $r['grade_level'],
            $r['strand'],
            $r['section_name'] ?? '',
            $r['enrollment_date'] ?? '',
            $r['status'],
            $r['school_year'] ?? '',
        ]);
    }
    fclose($out);
    exit();
}

// Filter options and pill counts come from the data itself, so a strand or
// grade nobody is enrolled in never shows up as an empty choice.
$grades  = array_values(array_unique(array_column($rows, 'grade_level')));
$strands = array_values(array_unique(array_column($rows, 'strand')));
sort($grades);
sort($strands);
$status_counts = array_count_values(array_map(fn($r) => strtolower((string)$r['status']), $rows));

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Enrollments — Admin</title>
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
          <h1 class="page-title">Enrollments</h1>
          <p class="staff-hero-desc">Review every enrollment record by status, grade level and strand.</p>
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
          <p class="empty-state">No enrollment records yet.</p>
        <?php else: ?>

        <div class="staff-toolbar">
          <div class="staff-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <label for="tblSearch" class="sr-only">Search enrollments</label>
            <input type="search" id="tblSearch" class="tbl-search" placeholder="Search by name, student number or section" autocomplete="off">
          </div>
          <select class="staff-filter-select" name="grade" data-filter-select data-attr="grade" aria-label="Filter by grade">
            <option value="">All grades</option>
            <?php foreach ($grades as $g): ?>
              <option value="<?= $h($g) ?>">Grade <?= $h($g) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="staff-filter-select" name="strand" data-filter-select data-attr="strand" aria-label="Filter by strand">
            <option value="">All strands</option>
            <?php foreach ($strands as $s): ?>
              <option value="<?= $h($s) ?>"><?= $h($s) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="staff-filter-select" id="tblSort" aria-label="Sort enrollments">
            <option value="date:desc" data-num>Sort: Newest first</option>
            <option value="date:asc" data-num>Sort: Oldest first</option>
            <option value="name:asc">Sort: Name (A to Z)</option>
            <option value="name:desc">Sort: Name (Z to A)</option>
          </select>
          <button type="button" class="staff-toolbar-reset hidden" id="tblReset">Reset filters</button>
        </div>

        <div class="staff-pills" role="group" aria-label="Filter by status" data-pills data-attr="status" data-param="status">
          <button type="button" class="staff-pill active" data-value="" aria-pressed="true">All <span data-count><?= count($rows) ?></span></button>
          <?php foreach ($status_map as $key => [$label]): ?>
            <button type="button" class="staff-pill" data-value="<?= $h($key) ?>" aria-pressed="false"><?= $h($label) ?> <span data-count><?= (int)($status_counts[$key] ?? 0) ?></span></button>
          <?php endforeach; ?>
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
              <th>Grade &amp; strand</th>
              <th>Section</th>
              <th>Enrolled on</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $s = strtolower((string)($r['status'] ?? 'pending'));
              [$s_label, $s_class] = $status_map[$s] ?? [ucfirst(str_replace('_', ' ', $s)), 'staff-state--pending'];
              $date_ts = $r['enrollment_date'] ? strtotime($r['enrollment_date']) : 0;
              $search  = mb_strtolower(implode(' ', [
                  $r['family_name'], $r['given_name'], $r['given_name'] . ' ' . $r['family_name'],
                  $r['student_number'] ?? '', $r['section_name'] ?? '',
              ]));
            ?>
              <tr class="<?= in_array($s, ['expired', 'cancelled'], true) ? 'is-dim' : '' ?>"
                  data-search="<?= $h($search) ?>"
                  data-status="<?= $h($s) ?>"
                  data-grade="<?= $h($r['grade_level']) ?>"
                  data-strand="<?= $h($r['strand']) ?>"
                  data-sort-name="<?= $h(mb_strtolower($r['student_name'])) ?>"
                  data-sort-date="<?= (int)$date_ts ?>">
                <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$r['enrollment_id'] ?>" aria-label="Select <?= $h($r['student_name']) ?>"></td>
                <td class="td-name">
                  <div class="td-name-cell">
                    <span class="staff-table-avatar"><?= $h(name_initials($r['student_name'])) ?></span>
                    <span class="staff-name-stack">
                      <span class="staff-name-primary"><?= $h($r['student_name']) ?></span>
                      <span class="staff-name-secondary"><?= $h($r['student_number'] ?? 'No student number') ?></span>
                    </span>
                  </div>
                </td>
                <td><span class="staff-role-tag">Grade <?= $h($r['grade_level']) ?> &middot; <?= $h($r['strand']) ?></span></td>
                <td><?php if ($r['section_name']): ?><?= $h($r['section_name']) ?><?php else: ?><span class="td-muted">Unassigned</span><?php endif; ?></td>
                <td>
                  <span class="staff-name-stack">
                    <span class="staff-joined"><?= $r['enrollment_date'] ? $h(date('M j, Y', $date_ts)) : '—' ?></span>
                    <?php if (!empty($r['school_year'])): ?><span class="staff-name-secondary">S.Y. <?= $h($r['school_year']) ?></span><?php endif; ?>
                  </span>
                </td>
                <td><span class="staff-state <?= $s_class ?>"><i></i><?= $h($s_label) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="empty-state hidden" data-no-match>
          <strong>No enrollments match these filters</strong>
          Try a different search or status.
          <div><button type="button" class="staff-btn staff-btn--ghost" data-clear-filters>Clear filters</button></div>
        </div>

        <?php $tbl_total = count($rows); $tbl_noun = 'enrollments'; include BASE_PATH . '/shared/includes/admin_table_footer.php'; ?>

        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <?php if ($rows): ?>
    <script src="<?= APP_URL ?>/assets/js/admin_table.js?v=<?= filemtime(__DIR__ . '/../../assets/js/admin_table.js') ?>"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        AdminTable.init({ noun: 'enrollments' });
      });
    </script>
  <?php endif; ?>

</body>
</html>
