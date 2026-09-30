<?php
session_start();
$was_student = ($_SESSION['role'] ?? '') === 'student';
session_destroy();
// Only the APP_URL helper rather than all of bootstrap.php (DB connection
// and all) just for a redirect.
require_once __DIR__ . '/shared/helpers/app_url.php';
header("Location: " . APP_URL . ($was_student ? '/roles/student/portal/student_login' : '/login'));
exit();
