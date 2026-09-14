<?php
require_once __DIR__ . '/../helpers/env.php';
load_env();

// Site-root URL prefix — lets shared includes and cross-folder redirects
// (e.g. shared/includes/*_sidebar.php, login/logout links) use an
// absolute path that works regardless of how deep the requesting page
// sits under roles/, instead of a relative '../../' chain that breaks
// every time a page moves during the directory reorganization.
if (!defined('APP_URL')) {
    define('APP_URL', '/Enrollment_system');
}

$servername = $_ENV['DB_HOST'] ?? 'localhost';
$username   = $_ENV['DB_USER'] ?? 'root';
$password   = $_ENV['DB_PASSWORD'] ?? '';
$database   = $_ENV['DB_NAME'] ?? 'enroll6_db';

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    error_log('Database connection failed: ' . mysqli_connect_error());
    die('Database connection failed. Please try again later.');
}

mysqli_set_charset($conn, 'utf8mb4');

// Auto-expire enrollments left unpaid for 3+ days — frees the section seat
// (every capacity/occupancy query already excludes non pending/enrolled rows).
// Gated to run at most once per real day (via a flag file) instead of on
// every single request that includes this file.
$expiryGateFile = dirname(__DIR__, 2) . '/storage/last_expiry_run.txt';
$today = date('Y-m-d');
if (!is_file($expiryGateFile) || trim((string) file_get_contents($expiryGateFile)) !== $today) {
    mysqli_query($conn, "
        UPDATE enrollments
        SET status = 'expired'
        WHERE status = 'pending'
          AND enrollment_date < (CURDATE() - INTERVAL 3 DAY)
    ");
    if (!is_dir(dirname($expiryGateFile))) {
        mkdir(dirname($expiryGateFile), 0755, true);
    }
    file_put_contents($expiryGateFile, $today);
}
