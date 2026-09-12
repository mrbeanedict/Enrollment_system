<?php
// Same distinct cookie name as student_login.php — keeps this flow on the
// student session namespace so it doesn't collide with an admin/staff tab.
session_name('STUDENT_SESSID');
session_start();
include('../config.php');
require_once('../config/mail.php');

// Already logged in — nothing to reset, send them onward.
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header("Location: " . APP_URL . "/roles/admin/dashboard");
    } elseif (($_SESSION['role'] ?? '') === 'student') {
        header("Location: student_dashboard");
    } else {
        header("Location: ../staff/staff_dashboard");
    }
    exit();
}

$error_message = "";
$submitted      = false;

if (isset($_POST['reset_btn'])) {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // A malformed email can never match a real account, so rejecting it
        // here doesn't leak anything about which usernames/emails exist —
        // it's a plain format check, not an existence check.
        $error_message = 'Please enter a valid email address.';
    } else {

    $submitted = true;

    $stmt = $conn->prepare("
        SELECT us.user_student_id, us.student_id, s.email, s.given_name, s.family_name
        FROM users_student us
        JOIN students s ON s.student_id = us.student_id
        WHERE us.username = ?
          AND s.email = ?
          AND us.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // A match only ever changes what we DO (issue + email a token) — never
    // what the visitor SEES. Showing a different message for "no such
    // account" vs "email sent" lets an attacker fish for valid usernames,
    // so both paths render the exact same confirmation screen.
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        // Raw token goes in the emailed link; only its hash is stored, so a
        // database leak alone can't be used to forge a reset link (the same
        // principle as never storing plaintext passwords).
        $raw_token   = bin2hex(random_bytes(32));
        $token_hash  = hash('sha256', $raw_token);
        $expires_at  = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

        $upd = $conn->prepare("UPDATE users_student SET reset_token_hash = ?, reset_token_expires_at = ? WHERE user_student_id = ?");
        $upd->bind_param("ssi", $token_hash, $expires_at, $row['user_student_id']);
        $upd->execute();
        $upd->close();

        $logStmt = $conn->prepare("
            INSERT INTO student_activity_log (student_id, activity_type, ip_address)
            VALUES (?, 'password_reset_requested', ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $logStmt->bind_param("is", $row['student_id'], $ip);
        $logStmt->execute();
        $logStmt->close();

        try {
            $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            $resetUrl = "$scheme://$host$basePath/student_reset_password?token=$raw_token";

            $mail = getMailer();
            $bodyHtml = '<p>Hi ' . htmlspecialchars($row['given_name']) . ', we received a request to reset your Student Portal password. Click the button below to choose a new one — this link expires in 30 minutes and can only be used once.</p>'
                . '<div style="text-align:center;margin:22px 0;">'
                . '<a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;background:#1E4D3B;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:13px 26px;border-radius:8px;">Reset My Password</a>'
                . '</div>'
                . '<p style="font-size:12px;color:#8A8A9A;">If the button doesn\'t work, copy and paste this link into your browser:<br>' . htmlspecialchars($resetUrl) . '</p>'
                . '<p style="margin-top:16px;">If you didn\'t request this, you can safely ignore this email — your password won\'t change.</p>';
            $altBody = "Reset your Student Portal password (expires in 30 minutes):\n$resetUrl\n\nIf you didn't request this, ignore this email.";

            send_branded_email(
                $mail,
                $row['email'],
                trim($row['given_name'] . ' ' . $row['family_name']),
                'Reset Your Student Portal Password',
                'Password Reset Request',
                $bodyHtml,
                $altBody
            );
        } catch (\Throwable $e) {
            // Swallow — the confirmation screen never reveals send failures
            // either, for the same enumeration-resistance reason above.
        }
    }

    $stmt->close();

    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — Student Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_student.css') ?>">
</head>
<body class="forgot-page">

<div class="auth-backdrop" aria-hidden="true">
  <span class="backdrop-blob backdrop-blob-cream"></span>
  <span class="backdrop-blob backdrop-blob-info"></span>
  <span class="backdrop-blob backdrop-blob-primary"></span>
  <span class="backdrop-blob backdrop-blob-dark"></span>
  <span class="backdrop-blob backdrop-blob-glow"></span>
</div>

<div class="card">

  <?php if ($submitted): ?>

    <div class="sent-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </div>
    <h1>Check your email</h1>
    <p class="lead">If that username and email match an account, we've sent a secure link to reset the password. The link expires in <strong>30 minutes</strong>.</p>

    <div class="sent-note">Didn't get it? Check your spam folder, or make sure the email matches the one on file with the Records office.</div>

    <div class="back-link">Back to <a href="student_login">Log In</a></div>

  <?php else: ?>

    <div class="step-track">
      <span class="step-dot active"></span>
      <span class="step-dot"></span>
      <span class="step-dot"></span>
    </div>

    <div class="icon-badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
    </div>

    <h1>Forgot your password?</h1>
    <p class="lead">Enter your username and the email on file. We'll send a secure link to set a new password.</p>

    <form method="POST" action="student_forgotpassword" data-confirm="Send a password reset link to this email?" data-icon="question">
      <?php if ($error_message): ?>
        <div class="alert"><?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <div>
        <label for="username">Username</label>
        <div class="field-icon-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>
          <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required>
        </div>
      </div>

      <div>
        <label for="email">Email Address</label>
        <div class="field-icon-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18v12H3V6zm0 0 9 7 9-7"/></svg>
          <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required>
        </div>
      </div>

      <button type="submit" name="reset_btn" class="btn-reset">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
        Send Reset Link
      </button>
    </form>

    <div class="back-link">Remembered it? <a href="student_login">Log in here</a></div>

  <?php endif; ?>

</div>

<script src="../assets/js/sweetalert2.all.min.js"></script>
<script>
  // Global SweetAlert for data-confirm forms (falls back to native confirm())
  // — same pattern as studentportal/student_sidebar.php's, copied here since
  // this pre-login page doesn't include that sidebar.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      var lastSubmitter = null;
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.addEventListener('click', function () { lastSubmitter = btn; });
      });

      form.addEventListener('submit', function (e) {
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
          title: 'Confirm',
          text: msg,
          icon: icon,
          showCancelButton: true,
          confirmButtonColor: '#386641',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) doSubmit();
        });
      });
    });
  });
</script>

</body>
</html>
