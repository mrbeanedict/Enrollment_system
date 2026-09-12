<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . APP_URL . "/login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: " . APP_URL . "/login"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$msg = '';

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
$search     = trim($_GET['q']   ?? '');
$filter_sex = $_GET['sex']      ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search) {
    $where[] = "(s.family_name LIKE ? OR s.given_name LIKE ? OR s.student_number LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($filter_sex) { $where[] = 's.sex = ?'; $params[] = $filter_sex; $types .= 's'; }

$where_sql = implode(' AND ', $where);

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
    WHERE $where_sql
    ORDER BY s.family_name ASC, s.given_name ASC
";

$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();

// ── Export CSV ───────────────────────────────────────────────────────────
// Honors whatever search/sex filter is already active (baked into $rows
// above); ?ids=1,2,3 further restricts it to just the checked rows.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_rows = $rows;
    if (!empty($_GET['ids'])) {
        $selected_ids = array_map('intval', explode(',', $_GET['ids']));
        $export_rows = array_values(array_filter($rows, function ($r) use ($selected_ids) {
            return in_array((int)$r['student_id'], $selected_ids, true);
        }));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Student Number', 'Sex', 'Birthdate', 'Last Grade', 'Last Strand']);
    foreach ($export_rows as $r) {
        fputcsv($out, [
            $r['full_name'],
            $r['student_number'] ?? '',
            $r['sex'],
            $r['date_of_birth'] ?? '',
            $r['last_grade'] ?? '',
            $r['last_strand'] ?? '',
        ]);
    }
    fclose($out);
    exit();
}
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
  <style>
    .filter-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.25rem; }
    .filter-bar input, .filter-bar select {
      height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#fff;
      padding:0 12px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; }
    .filter-bar input { min-width:220px; }
    .filter-bar select { padding-right:28px; appearance:none;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238A8A9A' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 10px center; }
    .filter-bar input:focus, .filter-bar select:focus { border-color:#386641; box-shadow:0 0 0 3px rgba(123,111,205,.14); }
    .btn-filter { height:36px; padding:0 16px; background:#386641; border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-filter:hover { background:#2F5636; }
    .btn-clear { height:36px; padding:0 14px; background:#fff; border:0.5px solid #D4D4E0;
      border-radius:8px; color:#5A5A72; font-size:13px; font-weight:500; cursor:pointer;
      font-family:inherit; text-decoration:none; display:inline-flex; align-items:center; }
    .btn-clear:hover { border-color:#386641; color:#5B4DB5; }
    .btn-sm { height:26px; padding:0 9px; font-size:11px; font-weight:500; border-radius:6px;
      cursor:pointer; font-family:inherit; border:0.5px solid #D4D4E0; background:#fff;
      color:#5A5A72; text-decoration:none; display:inline-flex; align-items:center; }
    .btn-sm:hover { border-color:#386641; color:#5B4DB5; }
    .btn-sm-danger { border-color:#F5C6C2; color:#C0392B; }
    .btn-sm-danger:hover { background:#FDF0EF; }
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error   { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }
  </style>
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Students</span></p>
        <h1 class="page-title">Students</h1>
      </div>

      <div class="staff-overlap">

      <?php if ($msg): ?>
        <div class="alert <?= str_contains($msg, 'Cannot') ? 'alert-error' : 'alert-success' ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <form method="GET" action="students">
        <div class="filter-bar">
          <input type="text" name="q" placeholder="Search name or Student Number…" value="<?= htmlspecialchars($search) ?>">
          <select name="sex">
            <option value="">All</option>
            <option value="Male"   <?= $filter_sex==='Male'   ? 'selected':'' ?>>Male</option>
            <option value="Female" <?= $filter_sex==='Female' ? 'selected':'' ?>>Female</option>
          </select>
          <button type="submit" class="btn-filter">Filter</button>
          <a href="students" class="btn-clear">Clear</a>
        </div>
      </form>

      <div class="card">
        <div class="card-header">
          <span class="card-title">Student Directory <span class="staff-record-count">(<?= count($rows) ?> records)</span></span>
          <button type="button" class="btn-export-csv" id="btnExportCsv">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            <span id="btnExportCsvLabel">Export CSV</span>
          </button>
        </div>

        <?php if (empty($rows)): ?>
          <p class="empty-state">No students found.</p>
        <?php else: ?>
          <table class="data-table" id="dataTable">
            <thead>
              <tr>
                <th class="staff-table-checkbox-col"><input type="checkbox" id="selectAllRows" aria-label="Select all"></th>
                <th>Name</th>
                <th>Student Number</th>
                <th>Sex</th>
                <th>Birthdate</th>
                <th>Last Enrollment</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$r['student_id'] ?>" aria-label="Select row"></td>
                  <td class="td-name">
                    <div class="td-name-cell">
                      <span class="staff-table-avatar"><?= htmlspecialchars(name_initials($r['full_name'])) ?></span>
                      <span><?= htmlspecialchars($r['full_name']) ?></span>
                    </div>
                  </td>
                  <td class="td-meta" style="font-family:monospace;"><?= htmlspecialchars($r['student_number'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($r['sex']) ?></td>
                  <td><?= htmlspecialchars($r['date_of_birth'] ?? '—') ?></td>
                  <td>
                    <?php if ($r['last_grade']): ?>
                      G<?= htmlspecialchars($r['last_grade']) ?> &middot; <?= htmlspecialchars($r['last_strand']) ?>
                    <?php else: ?>
                      <span class="td-meta">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="staff-table-pagination">
            <label class="staff-pagination-rows">
              <select id="rowsPerPage">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
              Results per page
            </label>

            <div class="staff-pagination-pages" id="paginationPages">
              <button type="button" id="paginationPrev" class="staff-page-btn staff-page-arrow" aria-label="Previous page">&lsaquo;</button>
              <span id="paginationNumbers" class="staff-pagination-numbers"></span>
              <button type="button" id="paginationNext" class="staff-page-btn staff-page-arrow" aria-label="Next page">&rsaquo;</button>
            </div>

            <span class="staff-pagination-summary"><span id="paginationFrom">1</span>-<span id="paginationTo">1</span> / <span id="paginationTotal"><?= count($rows) ?></span> results</span>
          </div>
        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var tableRows = Array.prototype.slice.call(document.querySelectorAll('#dataTable tbody tr'));
      if (!tableRows.length) return;

      var rowsPerPageSel  = document.getElementById('rowsPerPage');
      var paginationFrom  = document.getElementById('paginationFrom');
      var paginationTo    = document.getElementById('paginationTo');
      var paginationTotal = document.getElementById('paginationTotal');
      var paginationNumbers = document.getElementById('paginationNumbers');
      var prevBtn = document.getElementById('paginationPrev');
      var nextBtn = document.getElementById('paginationNext');
      var currentPage = 1;

      function buildPageList(current, total) {
        var boundary = 3, sibling = 1;
        var pages = {};
        for (var i = 1; i <= Math.min(boundary, total); i++) pages[i] = true;
        for (var i = Math.max(1, total - boundary + 1); i <= total; i++) pages[i] = true;
        for (var i = Math.max(1, current - sibling); i <= Math.min(total, current + sibling); i++) pages[i] = true;

        var sorted = Object.keys(pages).map(Number).sort(function (a, b) { return a - b; });
        var result = [];
        var prev = 0;
        sorted.forEach(function (p) {
          if (prev && p - prev > 1) result.push('ellipsis');
          result.push(p);
          prev = p;
        });
        return result;
      }

      function renderPageNumbers(current, total) {
        paginationNumbers.innerHTML = '';
        buildPageList(current, total).forEach(function (item) {
          if (item === 'ellipsis') {
            var span = document.createElement('span');
            span.className = 'staff-page-ellipsis';
            span.textContent = '…';
            paginationNumbers.appendChild(span);
            return;
          }
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'staff-page-btn' + (item === current ? ' active' : '');
          btn.textContent = item;
          btn.addEventListener('click', function () { currentPage = item; paginate(); });
          paginationNumbers.appendChild(btn);
        });
      }

      function paginate() {
        var rowsPerPage = parseInt(rowsPerPageSel.value, 10);
        var total = tableRows.length;
        var totalPages = Math.max(1, Math.ceil(total / rowsPerPage));
        if (currentPage > totalPages) currentPage = totalPages;

        var start = (currentPage - 1) * rowsPerPage;
        var end = start + rowsPerPage;
        tableRows.forEach(function (r, i) {
          r.classList.toggle('hidden', !(i >= start && i < end));
        });

        paginationFrom.textContent = total === 0 ? 0 : start + 1;
        paginationTo.textContent = Math.min(end, total);
        paginationTotal.textContent = total;
        renderPageNumbers(currentPage, totalPages);
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
      }

      rowsPerPageSel.addEventListener('change', function () { currentPage = 1; paginate(); });
      prevBtn.addEventListener('click', function () { if (currentPage > 1) { currentPage--; paginate(); } });
      nextBtn.addEventListener('click', function () { currentPage++; paginate(); });
      paginate();

      var selectAll = document.getElementById('selectAllRows');
      var exportBtn = document.getElementById('btnExportCsv');
      var exportBtnLabel = document.getElementById('btnExportCsvLabel');
      var rowCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.staff-row-checkbox'));

      function updateExportLabel() {
        var checkedCount = rowCheckboxes.filter(function (cb) { return cb.checked; }).length;
        exportBtnLabel.textContent = checkedCount > 0 ? 'Export Selected (' + checkedCount + ')' : 'Export CSV';
      }
      rowCheckboxes.forEach(function (cb) { cb.addEventListener('change', updateExportLabel); });

      if (selectAll) {
        selectAll.addEventListener('change', function () {
          document.querySelectorAll('#dataTable tbody tr:not(.hidden) .staff-row-checkbox').forEach(function (cb) {
            cb.checked = selectAll.checked;
          });
          updateExportLabel();
        });
      }

      exportBtn.addEventListener('click', function () {
        var ids = rowCheckboxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
        var url = new URL(window.location.href);
        url.searchParams.set('export', 'csv');
        if (ids.length) { url.searchParams.set('ids', ids.join(',')); } else { url.searchParams.delete('ids'); }
        window.location.href = url.toString();
      });
    });
  </script>

</body>
</html>
