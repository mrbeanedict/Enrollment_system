<?php
// Site-root URL prefix — lets shared includes and cross-folder redirects
// (e.g. shared/includes/*_sidebar.php, login/logout links) use an
// absolute path that works regardless of how deep the requesting page
// sits under roles/, instead of a relative '../../' chain that breaks
// every time a page moves during the directory reorganization.
if (!defined('APP_URL')) {
    define('APP_URL', '/Enrollment_system');
}

$servername = "localhost";
$username   = "root";
$password   = "";
$database   = "enroll6_db";

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

// Auto-expire enrollments left unpaid for 3+ days — frees the section seat
// (every capacity/occupancy query already excludes non pending/enrolled rows).
mysqli_query($conn, "
    UPDATE enrollments
    SET status = 'expired'
    WHERE status = 'pending'
      AND enrollment_date < (CURDATE() - INTERVAL 3 DAY)
");
