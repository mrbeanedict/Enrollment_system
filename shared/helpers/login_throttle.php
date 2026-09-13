<?php
// ── Login brute-force throttling ─────────────────────────────────────────
// Shared by every login entry point (login.php, roles/teacher/teacher_login.php,
// roles/student/portal/student_login.php, roles/lms/lms_login.php) — tracks
// failed attempts per username+IP so repeated wrong-password guesses get
// locked out instead of being retryable instantly forever.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(100) NOT NULL,
        ip_address   VARCHAR(45) NOT NULL,
        succeeded    TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (username, ip_address, attempted_at)
    )
");

const LOGIN_THROTTLE_MAX_ATTEMPTS = 5;
const LOGIN_THROTTLE_WINDOW_MIN   = 15;

// Call before verifying a password. Returns a human-readable lockout
// message if this username+IP has too many recent failed attempts, or ''
// if the login attempt may proceed.
function login_throttle_check(mysqli $conn, string $username, string $ip): string
{
    $cutoff = date('Y-m-d H:i:s', time() - LOGIN_THROTTLE_WINDOW_MIN * 60);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS failures
        FROM login_attempts
        WHERE username = ? AND ip_address = ? AND succeeded = 0 AND attempted_at > ?
    ");
    $stmt->bind_param('sss', $username, $ip, $cutoff);
    $stmt->execute();
    $failures = (int) ($stmt->get_result()->fetch_assoc()['failures'] ?? 0);
    $stmt->close();

    return $failures >= LOGIN_THROTTLE_MAX_ATTEMPTS
        ? 'Too many failed login attempts. Please try again in ' . LOGIN_THROTTLE_WINDOW_MIN . ' minutes.'
        : '';
}

// Call after every login attempt (success or failure) to record it.
function login_throttle_record(mysqli $conn, string $username, string $ip, bool $succeeded): void
{
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, succeeded) VALUES (?, ?, ?)");
    $succ = $succeeded ? 1 : 0;
    $stmt->bind_param('ssi', $username, $ip, $succ);
    $stmt->execute();
    $stmt->close();
}
