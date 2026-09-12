<?php
session_start();
$was_student = ($_SESSION['role'] ?? '') === 'student';
session_destroy();
header("Location: " . ($was_student ? 'studentportal/student_login' : 'login'));
exit();
