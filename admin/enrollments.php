<?php
session_start();
include_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login"); exit();
}

$msg = '';

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

// ── Filters ────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_grade  = $_GET['grade']  ?? '';
$filter_strand = $_GET['strand'] ?? '';
$search        = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_status) { $where[] = 'e.status = ?';                 $params[] = $filter_status; $types .= 's'; }
if ($filter_grade)  { $where[] = 'e.admission_grade_level = ?';  $params[] = $filter_grade;  $types .= 's'; }
if ($filter_strand) { $where[] = 'e.admission_strand = ?';       $params[] = strand_id($conn, $filter_strand); $types .= 'i'; }
if ($search) {
    $where[] = "(st.family_name LIKE ? OR st.given_name LIKE ? OR st.student_number LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$where_sql = implode(' AND ', $where);

$sql = "
    SELECT e.enrollment_id,
           CONCAT(st.family_name, ', ', st.given_name) AS student_name,
           st.student_number, e.admission_grade_level AS grade_level, strd.strand_code AS strand, sec.section_name,
           e.school_year, e.enrollment_date, e.status
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    WHERE $where_sql
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
";

$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$conn->close();

// ── Export CSV ───────────────────────────────────────────────────────────
// Honors whatever status/grade/strand/search filter is already active
// (baked into $rows above); ?ids=1,2,3 further restricts it to just the
// checked rows, same convention as staff_manage.php's export.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_rows = $rows;
    if (!empty($_GET['ids'])) {
        $selected_ids = array_map('intval', explode(',', $_GET['ids']));
        $export_rows = array_values(array_filter($rows, function ($r) use ($selected_ids) {
            return in_array((int)$r['enrollment_id'], $selected_ids, true);
        }));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="enrollments_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Enrollment ID', 'Student', 'Student Number', 'Grade', 'Strand', 'Section', 'Date', 'Status']);
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
  <title>Enrollments — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/css_admin.css?v=<?= filemtime(__DIR__ . '/../css/css_admin.css') ?>">
  <style>
    .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
    .filter-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.25rem; }
    .filter-bar input, .filter-bar select {
      height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#fff;
      padding:0 12px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; }
    .filter-bar input { min-width:200px; }
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
      cursor:pointer; font-family:inherit; border:0.5px solid #D4D4E0; background:#fff; color:#5A5A72; }
    .btn-sm:hover { border-color:#386641; color:#5B4DB5; }
    .btn-sm-danger { border-color:#F5C6C2; color:#C0392B; }
    .btn-sm-danger:hover { background:#FDF0EF; }
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .action-cell { display:flex; gap:5px; align-items:center; }
    select.inline-status { height:26px; font-size:11px; border:0.5px solid #D4D4E0;
      border-radius:6px; background:#fff; padding:0 6px; font-family:inherit; color:#1A1A2E; }
  </style>
</head>
<body>

  <?php include_once 'sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Enrollments</span></p>
        <h1 class="page-title">Enrollments</h1>
      </div>

      <div class="staff-overlap">

      <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <form method="GET" action="enrollments">
        <div class="filter-bar">
          <label for="f_q" class="sr-only">Search</label>
          <input id="f_q" type="text" name="q" placeholder="Search name or Student Number…" value="<?= htmlspecialchars($search) ?>">
          <label for="f_status" class="sr-only">Status</label>
          <select id="f_status" name="status">
            <option value="">All statuses</option>
            <option value="enrolled"     <?= $filter_status==='enrolled'     ? 'selected':'' ?>>Enrolled</option>
            <option value="pre_enrolled" <?= $filter_status==='pre_enrolled' ? 'selected':'' ?>>Pre-Enrolled</option>
            <option value="pending"      <?= $filter_status==='pending'      ? 'selected':'' ?>>Pending</option>
            <option value="expired"      <?= $filter_status==='expired'      ? 'selected':'' ?>>Expired</option>
            <option value="cancelled"    <?= $filter_status==='cancelled'    ? 'selected':'' ?>>Cancelled</option>
          </select>
          <label for="f_grade" class="sr-only">Grade</label>
          <select id="f_grade" name="grade">
            <option value="">All grades</option>
            <option value="11" <?= $filter_grade==='11' ? 'selected':'' ?>>Grade 11</option>
            <option value="12" <?= $filter_grade==='12' ? 'selected':'' ?>>Grade 12</option>
          </select>
          <label for="f_strand" class="sr-only">Strand</label>
          <select id="f_strand" name="strand">
            <option value="">All strands</option>
            <option value="STEM"  <?= $filter_strand==='STEM'  ? 'selected':'' ?>>STEM</option>
            <option value="HUMSS" <?= $filter_strand==='HUMSS' ? 'selected':'' ?>>HUMSS</option>
            <option value="ABM"   <?= $filter_strand==='ABM'   ? 'selected':'' ?>>ABM</option>
            <option value="GAS"   <?= $filter_strand==='GAS'   ? 'selected':'' ?>>GAS</option>
            <option value="TVL"   <?= $filter_strand==='TVL'   ? 'selected':'' ?>>TVL</option>
          </select>
          <button type="submit" class="btn-filter">Filter</button>
          <a href="enrollments" class="btn-clear">Clear</a>
        </div>
      </form>

      <div class="card">
        <div class="card-header">
          <span class="card-title">Enrollment Records <span class="staff-record-count">(<?= count($rows) ?> records)</span></span>
          <button type="button" class="btn-export-csv" id="btnExportCsv">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            <span id="btnExportCsvLabel">Export CSV</span>
          </button>
        </div>

        <?php if (empty($rows)): ?>
          <p class="empty-state">No enrollments match the current filter.</p>
        <?php else: ?>
          <table class="data-table" id="dataTable">
            <thead>
              <tr>
                <th class="staff-table-checkbox-col"><input type="checkbox" id="selectAllRows" aria-label="Select all"></th>
                <th>#</th>
                <th>Student</th>
                <th>Grade / Strand</th>
                <th>Section</th>
                <th>Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $s = strtolower($r['status'] ?? 'pending');
                $b = match($s) {
                    'enrolled'  => 'badge-enrolled',
                    'cancelled' => 'badge-cancelled',
                    'expired'   => 'badge-expired',
                    default     => 'badge-pending',
                };
              ?>
                <tr>
                  <td class="staff-table-checkbox-col"><input type="checkbox" class="staff-row-checkbox" value="<?= (int)$r['enrollment_id'] ?>" aria-label="Select row"></td>
                  <td class="td-meta"><?= $r['enrollment_id'] ?></td>
                  <td>
                    <div class="td-name-cell">
                      <span class="staff-table-avatar"><?= htmlspecialchars(name_initials($r['student_name'])) ?></span>
                      <div>
                        <div class="td-name"><?= htmlspecialchars($r['student_name']) ?></div>
                        <div class="td-meta"><?= htmlspecialchars($r['student_number'] ?? '—') ?></div>
                      </div>
                    </div>
                  </td>
                  <td>G<?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                  <td><?= htmlspecialchars($r['section_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($r['enrollment_date'] ?? '—') ?></td>
                  <td><span class="badge <?= $b ?>"><?= htmlspecialchars($s) ?></span></td>
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
    // Client-side pagination + checkbox-scoped CSV export over the already
    // server-filtered $rows — same pattern as staff_manage.php's table view,
    // minus the client-side filtering (this page's status/grade/strand/search
    // filters are server-side GET params, not toggled in-place).
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

      // ── Select-all + checkboxes drive the Export CSV button ─────────────
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
