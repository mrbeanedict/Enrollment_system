<?php
require_once __DIR__ . '/../helpers/env.php';
load_env();

// Defines APP_URL (site-root URL prefix) — see that file.
require_once __DIR__ . '/../helpers/app_url.php';

// One clock for PHP and MySQL. XAMPP's PHP defaults to Europe/Berlin while
// MySQL follows the machine (UTC+8 here), so date()/time() ran 6 hours
// behind NOW() — every "is it late / overdue / time's up" comparison of a
// PHP time against a stored DATETIME was off. APP_TIMEZONE in .env overrides.
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Manila');

$servername = $_ENV['DB_HOST'] ?? 'localhost';
$username   = $_ENV['DB_USER'] ?? 'root';
$password   = $_ENV['DB_PASSWORD'] ?? '';
$database   = $_ENV['DB_NAME'] ?? 'enroll_db';

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    error_log('Database connection failed: ' . mysqli_connect_error());
    die('Database connection failed. Please try again later.');
}

mysqli_set_charset($conn, 'utf8mb4');
// MySQL's NOW()/TIMESTAMP columns in the same zone as PHP, even on a host
// whose system clock is UTC.
mysqli_query($conn, "SET time_zone = '" . date('P') . "'");

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
