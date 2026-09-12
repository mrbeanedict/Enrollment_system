<?php
// Requires: $conn open, session active
//
// This partial lives in staff/ — one folder below the project root — and is
// included both from staff/staff_dashboard.php (same folder) and from the
// department folders (coordinator/, records/, registrar/, scheduler/,
// treasury/, also one folder below root). $rootPrefix is computed first,
// before anything else, so it can be reused by the early auth-redirects
// below as well as by every root-relative asset/nav link further down.
$staffPartialRoot = realpath(dirname(__DIR__));
$staffCallerDir    = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
$base = ($staffCallerDir !== false && $staffCallerDir === $staffPartialRoot) ? '' : '../';

// Auth guard — required in case this partial is ever requested directly.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: {$base}login");
    exit();
}
if (!isset($conn)) {
    include_once __DIR__ . '/../config.php';
}

$current = basename($_SERVER['PHP_SELF']);

// ---------------------------------------------------------------
// Resolve department
// Prefer the session value set at login. Fall back to a live lookup
// so existing sessions (started before this change shipped) still work.
// ---------------------------------------------------------------
$department = $_SESSION['department'] ?? null;

if ($department === null && ($_SESSION['role'] ?? '') === 'staff') {
    $stmt = mysqli_prepare($conn, "
        SELECT d.department_name
        FROM staff s
        JOIN departments d ON d.department_id = s.department_id
        WHERE s.user_id = ? AND s.is_active = 1
    ");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $deptResult);
    if (mysqli_stmt_fetch($stmt)) {
        $department = $deptResult;
        $_SESSION['department'] = $department; // cache for next page load
    }
    mysqli_stmt_close($stmt);
}

// Admins (or a staff account with no active staff row) don't use this
// partial — it's the staff-only sidebar. Bail out safely rather than
// guessing which department nav to render.
if (($_SESSION['role'] ?? '') !== 'staff' || $department === null) {
    header("Location: {$base}login");
    exit();
}

function staffNavLink($href, $label, $icon, $current, $match, $badge = 0) {
    $active = ($current === $match) ? ' active' : '';
    $badgeHtml = $badge > 0 ? '<span class="staff-nav-badge">' . $badge . '</span>' : '';
    echo '<a href="' . $href . '" class="staff-nav-link' . $active . '">' . $icon . $label . $badgeHtml . '</a>';
}

// ---------------------------------------------------------------
// Icons
// ---------------------------------------------------------------
$ico_dashboard = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13h4v7H3zm7-9h4v16h-4zm7 5h4v11h-4z"/></svg>';
$ico_admission = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7-8h.01M5 6a2 2 0 012-2h10a2 2 0 012 2v14l-3-2-3 2-3-2-3 2V6z"/></svg>';
$ico_pending   = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-5 9l2 2 4-4"/></svg>';
$ico_enroll    = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>';
$ico_invoice   = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M7 3h10a2 2 0 012 2v16l-3-2-2 2-2-2-2 2-2-2-3 2V5a2 2 0 012-2z"/></svg>';
$ico_payment   = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
$ico_sections  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16M8 4v16M16 4v16"/></svg>';
$ico_curriculum = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.25C10.5 5 8.5 4.25 6 4.25 4.5 4.25 3 4.6 3 5.5v13c0-.9 1.5-1.25 3-1.25 2.5 0 4.5.75 6 2m0-13.25c1.5-1.25 3.5-2 6-2 1.5 0 3 .35 3 1.25v13c0-.9-1.5-1.25-3-1.25-2.5 0-4.5.75-6 2m0-13.25v13.25"/></svg>';
$ico_schedule  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm3 8h2m4 0h2m-8 4h2m4 0h2"/></svg>';
$ico_approvals = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
$ico_docreview = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-6 4h4M7 3h10a2 2 0 012 2v14l-3-2-2 2-2-2-2 2-2-2-3 2V5a2 2 0 012-2z"/></svg>';
$ico_accountabilities = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-5 9l2 2 4-4"/></svg>';
$ico_teachers  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0121 15.5V17a2 2 0 01-2 2H5a2 2 0 01-2-2v-1.5c0-1.487.44-2.887 1.34-4.078L12 14zm0 0v7"/></svg>';
$ico_logout    = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>';

// ---------------------------------------------------------------
// Department-scoped nav + status panel data
// Only the queries relevant to the signed-in department run.
//
// NOTE: these query-result loop variables are prefixed `$sb_` (sidebar)
// on purpose — this file is include_once'd into other pages and shares
// their variable scope. A generic name like `$q` or `$r` here will
// silently clobber a same-named variable in the including page (e.g. a
// page's own search-query string `$q`), producing hard-to-trace bugs
// where a filter/search variable becomes a mysqli_result object partway
// through the page. Never reuse short generic names in an include that
// isn't a fully scoped function.
// ---------------------------------------------------------------
$roleLabel = ucfirst($department);
$navItems  = [];   // ['href (root-relative)','label','icon','match (basename)','badge']
$statusRows = [];  // ['label','value','warn' => bool]

// $base was already computed at the top of this file (before the auth
// guards, so it could be reused there too). Every href below is written
// root-relative (no leading "../") and gets $base prepended at render
// time, so the exact same nav item works regardless of which directory
// the including page happens to sit in.

if ($department === 'registrar') {

    // Admissions now belong entirely to Records (see below) — Registrar only
    // deals with enrollment once a student already has a Student Number.
    $pendingEnrollments = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM enrollments WHERE status = 'pending'");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $pendingEnrollments = (int)$sb_r[0]; }

    $enrolledCount = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM enrollments WHERE status = 'enrolled'");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $enrolledCount = (int)$sb_r[0]; }

    // Semester 2 continuation is registrar-initiated straight through the
    // same enrollment wizard (registrar/enrollment.php's Step 1 directory
    // shows a Semester column and a Select button for eligible students) —
    // there's no student-submitted request to track anymore, so this is a
    // live count of enrolled Grade 11/12 students not yet approved for Sem 2.
    $awaitingSemester2 = 0;
    $sb_q = mysqli_query($conn, "
        SELECT COUNT(*) FROM enrollments
        WHERE status = 'enrolled' AND admission_grade_level IN ('11', '12')
          AND (semester2_status IS NULL OR semester2_status != 'approved')
    ");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $awaitingSemester2 = (int)$sb_r[0]; }

    $navItems = [
        ['staff/staff_dashboard', 'Dashboard',  $ico_dashboard, 'staff_dashboard.php', 0],
        ['registrar/enrollment', 'Enrollment', $ico_enroll,    'enrollment.php',      $pendingEnrollments],
    ];

    $statusRows = [
        ['label' => 'Enrolled',      'value' => $enrolledCount,      'warn' => false],
        ['label' => 'Pending enrollments',  'value' => $pendingEnrollments, 'warn' => $pendingEnrollments > 0],
        ['label' => 'Awaiting Sem 2 processing', 'value' => $awaitingSemester2, 'warn' => $awaitingSemester2 > 0],
    ];

} elseif ($department === 'records') {

    // Client-submitted applications still awaiting document review/admission
    // (records/document_review.php is now the single admission-review
    // screen — see that file's header comment for why the old two-stage
    // pending_applications.php + requirement.php pipeline was retired).
    $awaitingReview = 0;
    $sb_q = mysqli_query($conn, "
        SELECT COUNT(*) FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.status = 'pending' AND s.admission_status = 'pending_requirements'
    ");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $awaitingReview = (int)$sb_r[0]; }

    // Documents students uploaded from Accountabilities (or attached at
    // public admission), awaiting a status decision.
    $pendingDocReviews = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM enrollment_requirements WHERE status = 'pending_review'");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $pendingDocReviews = (int)$sb_r[0]; }

    $admittedTodayCount = 0;
    $sb_q = mysqli_query($conn, "
        SELECT COUNT(*) FROM enrollment_requirements
        WHERE status = 'submitted' AND DATE(reviewed_at) = CURDATE()
    ");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $admittedTodayCount = (int)$sb_r[0]; }

    // Already-enrolled students who still owe a document — same
    // applicable_reqs > submitted_count logic as records/accountabilities.php.
    $accountabilitiesCount = 0;
    $sb_q = mysqli_query($conn, "
        SELECT COUNT(*) FROM (
            SELECT e.enrollment_id,
                   (SELECT COUNT(*) FROM requirement_types rt
                      WHERE rt.is_active = 1 AND (rt.applicable_to = 'all' OR se.is_public = 1)
                   ) AS applicable_reqs,
                   (SELECT COUNT(*) FROM enrollment_requirements er
                      JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                     WHERE er.enrollment_id = e.enrollment_id AND er.status = 'submitted' AND rt.is_active = 1
                   ) AS submitted_count
            FROM enrollments e
            JOIN students s ON s.student_id = e.student_id
            LEFT JOIN student_education se ON se.student_id = s.student_id
            WHERE e.status = 'enrolled'
            HAVING submitted_count < applicable_reqs
        ) t
    ");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $accountabilitiesCount = (int)$sb_r[0]; }

    $navItems = [
        ['staff/staff_dashboard',        'Dashboard',              $ico_dashboard,        'staff_dashboard.php', 0],
        ['records/document_review',      'Document Review',       $ico_docreview,        'document_review.php', $awaitingReview],
        ['records/accountabilities',     'Accountabilities',      $ico_accountabilities, 'accountabilities.php', $accountabilitiesCount],
    ];

    $statusRows = [
        ['label' => 'Awaiting review',    'value' => $awaitingReview,         'warn' => $awaitingReview > 0],
        ['label' => 'Documents pending',  'value' => $pendingDocReviews,      'warn' => $pendingDocReviews > 0],
        ['label' => 'Accountabilities',   'value' => $accountabilitiesCount,  'warn' => $accountabilitiesCount > 0],
        ['label' => 'Reviewed today',     'value' => $admittedTodayCount,     'warn' => false],
    ];

} elseif ($department === 'treasury') {

    // Awaiting an initial payment/invoice
    $pendingInvoices = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM enrollments WHERE status = 'pending'");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $pendingInvoices = (int)$sb_r[0]; }

    $enrolledCount = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM enrollments WHERE status = 'enrolled'");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $enrolledCount = (int)$sb_r[0]; }

    $paymentsTodayCount = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM payments WHERE DATE(paid_at) = CURDATE()");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $paymentsTodayCount = (int)$sb_r[0]; }

    $onlinePaymentsPendingCount = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM online_payment_submissions WHERE status = 'pending'");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $onlinePaymentsPendingCount = (int)$sb_r[0]; }

    $navItems = [
        ['staff/staff_dashboard',      'Dashboard',        $ico_dashboard, 'staff_dashboard.php',   0],
        ['treasury/enrollments_staff', 'All Payments',     $ico_payment,   'enrollments_staff.php', 0],
        ['treasury/online_payments',   'Online Payments',  $ico_payment,   'online_payments.php',   $onlinePaymentsPendingCount],
    ];

    $statusRows = [
        ['label' => 'Enrolled (paid)',   'value' => $enrolledCount,      'warn' => false],
        ['label' => 'Awaiting invoice',  'value' => $pendingInvoices,    'warn' => $pendingInvoices > 0],
        ['label' => 'Payments today',    'value' => $paymentsTodayCount, 'warn' => false],
        ['label' => 'Online, unverified','value' => $onlinePaymentsPendingCount, 'warn' => $onlinePaymentsPendingCount > 0],
    ];

} elseif ($department === 'coordinator' || $department === 'scheduler') {

    // Active sections with no subjects scheduled yet
    $unscheduledSections = 0;
    $sb_q = mysqli_query($conn, "
        SELECT COUNT(*) FROM sections sec
        WHERE sec.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM section_subjects ss WHERE ss.section_id = sec.section_id)
    ");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $unscheduledSections = (int)$sb_r[0]; }

    $activeSubjects = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM subjects WHERE is_active = 1");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $activeSubjects = (int)$sb_r[0]; }

    $activeSections = 0;
    $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM sections WHERE is_active = 1");
    if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $activeSections = (int)$sb_r[0]; }

    if ($department === 'coordinator') {
        // Approvals is a coordinator-only reviewer screen — schedulers submit
        // requests but don't review their own.
        $pendingSections = 0;
        $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM sections WHERE approval_status = 'pending'");
        if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $pendingSections = (int)$sb_r[0]; }

        $pendingSubjects = 0;
        $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM subjects WHERE review_status = 'for_review'");
        if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $pendingSubjects = (int)$sb_r[0]; }

        $pendingSchedules = 0;
        $sb_q = mysqli_query($conn, "SELECT COUNT(*) FROM sections WHERE schedule_status = 'pending'");
        if ($sb_q) { $sb_r = mysqli_fetch_row($sb_q); $pendingSchedules = (int)$sb_r[0]; }

        $pendingApprovals = $pendingSections + $pendingSubjects + $pendingSchedules;

        $navItems = [
            ['staff/staff_dashboard',     'Dashboard',         $ico_dashboard,  'staff_dashboard.php', 0],
            ['scheduler/sections',        'Sections',          $ico_sections,   'sections.php',        0],
            ['scheduler/curriculum',      'Curriculum',        $ico_curriculum, 'curriculum.php',      0],
            ['scheduler/scheduling',      'Class Scheduling',  $ico_schedule,   'scheduling.php',      $unscheduledSections],
            ['coordinator/approvals',     'Approvals',         $ico_approvals,  'approvals.php',       $pendingApprovals],
            ['coordinator/teacher_manage', 'Teachers',         $ico_teachers,   'teacher_manage.php',  0],
        ];

        $statusRows = [
            ['label' => 'Pending approvals',    'value' => $pendingApprovals,    'warn' => $pendingApprovals > 0],
            ['label' => 'Active sections',      'value' => $activeSections,      'warn' => false],
            ['label' => 'Unscheduled sections', 'value' => $unscheduledSections, 'warn' => $unscheduledSections > 0],
        ];
    } else {
        $navItems = [
            ['staff/staff_dashboard', 'Dashboard',       $ico_dashboard,  'staff_dashboard.php', 0],
            ['scheduler/sections',   'Sections',         $ico_sections,   'sections.php',        0],
            ['scheduler/curriculum', 'Curriculum',       $ico_curriculum, 'curriculum.php',      0],
            ['scheduler/scheduling', 'Class Scheduling', $ico_schedule,   'scheduling.php',      $unscheduledSections],
            ['scheduler/create_schedule', 'Create Schedule', $ico_schedule, 'create_schedule.php', 0],
        ];

        $statusRows = [
            ['label' => 'Active sections',      'value' => $activeSections,      'warn' => false],
            ['label' => 'Unscheduled sections', 'value' => $unscheduledSections, 'warn' => $unscheduledSections > 0],
            ['label' => 'Active subjects',      'value' => $activeSubjects,      'warn' => false],
        ];
    }
}
?>

<!-- SweetAlert2 -->
<script src="<?= $base ?>js/sweetalert2.all.min.js"></script>
<script src="<?= $base ?>js/tab_guard.js?v=<?= filemtime(__DIR__ . '/../assets/js/tab_guard.js') ?>" data-token="<?= htmlspecialchars($_SESSION['sg_tab_token'] ?? '', ENT_QUOTES) ?>" data-logout-url="<?= $base ?>logout"></script>

<!-- Sidebar overlay -->
<div class="staff-sidebar-overlay" id="staffSidebarOverlay"></div>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<aside class="staff-sidebar" id="staffSidebar">

  <div class="staff-sidebar-brand">
    <div class="sidebar-logo-crop">
      <img src="<?= $base ?>images/logo.png" alt="Logo">
    </div>
    <div>
      <div class="staff-sidebar-brand-name">Greenfield Senior High School</div>
      <div class="staff-sidebar-brand-role"><?= htmlspecialchars($roleLabel) ?> Portal</div>
    </div>
  </div>

  <nav class="staff-sidebar-nav">

    <span class="staff-nav-label"><?= htmlspecialchars($roleLabel) ?></span>
    <?php foreach ($navItems as $item): ?>
        <?php staffNavLink($base . $item[0], $item[1], $item[2], $current, $item[3], $item[4]); ?>
    <?php endforeach; ?>

    <?php if (!empty($statusRows)): ?>
    <span class="staff-nav-label">Status</span>
    <div class="staff-sidebar-status">
      <?php foreach ($statusRows as $sb_status_row): ?>
      <div class="staff-status-row">
        <span class="staff-status-label"><?= htmlspecialchars($sb_status_row['label']) ?></span>
        <span class="staff-status-val <?= $sb_status_row['warn'] ? 'warn' : 'ok' ?>"><?= $sb_status_row['value'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </nav>

  <div class="staff-sidebar-photo">
    <img src="<?= $base ?>images/background/ui.png" alt="">
  </div>

  <div class="staff-sidebar-footer">
    <div class="staff-sidebar-user">
      <div class="staff-sidebar-avatar">
        <?= strtoupper(substr($_SESSION['username'] ?? 'S', 0, 1)) ?>
      </div>
      <span class="staff-sidebar-username"><?= htmlspecialchars($_SESSION['username'] ?? 'Staff') ?></span>
    </div>
    <a href="<?= $base ?>logout" class="staff-btn-logout" id="staffLogoutBtn">
      <?= $ico_logout ?> Log out
    </a>
  </div>

</aside>

<script>
(function () {
  const staffSidebar  = document.getElementById('staffSidebar');
  const staffOverlay  = document.getElementById('staffSidebarOverlay');
  const STAFF_SIDEBAR_KEY = 'staffSidebarOpen';

  function openStaffSidebar()  { staffSidebar.classList.add('open');  staffOverlay.classList.add('open');  localStorage.setItem(STAFF_SIDEBAR_KEY, '1'); }
  function closeStaffSidebar() { staffSidebar.classList.remove('open'); staffOverlay.classList.remove('open'); localStorage.setItem(STAFF_SIDEBAR_KEY, '0'); }
  function toggleStaffSidebar() { staffSidebar.classList.contains('open') ? closeStaffSidebar() : openStaffSidebar(); }

  // Restore open/closed state from the previous page (this is a multi-page
  // app — every navigation is a full reload, so state has to persist here).
  if (localStorage.getItem(STAFF_SIDEBAR_KEY) === '1') { openStaffSidebar(); }

  document.addEventListener('DOMContentLoaded', function () {

    // Inject hamburger button into .staff-topbar-left (already exists on
    // every staff page — no wrapping needed, unlike admin/sidebar.php).
    var staffTopbarLeft = document.querySelector('.staff-topbar-left');
    if (staffTopbarLeft && !staffTopbarLeft.querySelector('.btn-staff-sidebar-toggle')) {
      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'btn-staff-sidebar-toggle';
      toggleBtn.setAttribute('aria-label', 'Toggle menu');
      toggleBtn.innerHTML = '<span></span><span></span><span></span>';
      toggleBtn.addEventListener('click', toggleStaffSidebar);
      staffTopbarLeft.prepend(toggleBtn);
    }

    staffOverlay.addEventListener('click', closeStaffSidebar);

    // Logout — SweetAlert (falls back to native confirm() if Swal failed to load)
    var logoutBtn = document.getElementById('staffLogoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (typeof Swal === 'undefined') {
          if (window.confirm('Log out? You will be returned to the login page.')) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= $base ?>logout';
          }
          return;
        }
        Swal.fire({
          title: 'Log out?',
          text: 'You will be returned to the login page.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, log out',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= $base ?>logout';
          }
        });
      });
    }

    // Global SweetAlert for data-confirm forms and links (falls back to native confirm())
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      var lastSubmitter = null;
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.addEventListener('click', function () { lastSubmitter = btn; });
      });

      form.addEventListener('submit', function (e) {
        // Resubmitting via requestSubmit() re-fires this listener — let it through once.
        if (form.dataset.confirmed === '1') {
          form.dataset.confirmed = '';
          return;
        }
        e.preventDefault();
        var msg  = this.dataset.confirm || 'Are you sure?';
        var icon = this.dataset.icon   || 'warning';
        var self = this;

        function doSubmit() {
          self.dataset.confirmed = '1';
          if (lastSubmitter && typeof self.requestSubmit === 'function') {
            self.requestSubmit(lastSubmitter);
          } else {
            HTMLFormElement.prototype.submit.call(self);
          }
        }

        if (typeof Swal === 'undefined') {
          if (window.confirm(msg)) doSubmit();
          return;
        }
        Swal.fire({
          title: 'Confirm Action',
          text: msg,
          icon: icon,
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) doSubmit();
        });
      });
    });

    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var href = this.href;
        var msg  = this.dataset.confirm || 'Are you sure?';
        if (typeof Swal === 'undefined') {
          if (window.confirm(msg)) window.location.href = href;
          return;
        }
        Swal.fire({
          title: 'Confirm',
          text: msg,
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) window.location.href = href;
        });
      });
    });
  });
})();
</script>