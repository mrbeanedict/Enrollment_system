<?php
// Summary + page-size + page-number footer shared by the admin list pages
// (enrollments, students, teacher_accounts). Wired up by assets/js/admin_table.js;
// $tbl_total is the unfiltered record count, used for the pre-JS summary text
// and $tbl_noun for its label. The page-size select is hidden by the script
// until there's more than one page of rows.
$tbl_total = $tbl_total ?? 0;
$tbl_noun  = $tbl_noun  ?? 'records';
?>
<div class="staff-table-footer">
  <span class="staff-footer-summary" id="tblSummary" aria-live="polite">Showing <?= (int)$tbl_total ?> of <?= (int)$tbl_total ?> <?= htmlspecialchars($tbl_noun) ?></span>
  <div class="staff-pager">
    <label class="staff-pagination-rows">
      <select id="tblSize" aria-label="Rows per page">
        <option value="10">10</option>
        <option value="25">25</option>
        <option value="50">50</option>
      </select>
      per page
    </label>
    <div class="staff-pagination-pages" id="tblPages">
      <button type="button" id="tblPrev" class="staff-page-btn staff-page-arrow" aria-label="Previous page">&lsaquo;</button>
      <span id="tblNumbers" class="staff-pagination-numbers"></span>
      <button type="button" id="tblNext" class="staff-page-btn staff-page-arrow" aria-label="Next page">&rsaquo;</button>
    </div>
  </div>
</div>
