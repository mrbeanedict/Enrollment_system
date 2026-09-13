<?php
session_start();
$was_student = ($_SESSION['role'] ?? '') === 'student';
session_destroy();
// Hardcoded prefix rather than pulling in bootstrap.php (DB connection
// and all) just for a redirect — same site-root prefix as APP_URL in
// shared/config/config.php.
header("Location: " . ($was_student ? '/Enrollment_system/roles/student/portal/student_login' : 'login'));
exit();
