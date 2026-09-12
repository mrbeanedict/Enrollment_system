<?php
// Same distinct cookie name as teacher_login.php — keeps this flow on the
// teacher session namespace so it doesn't collide with an admin/staff tab.
session_name('TEACHER_SESSID');
session_start();
include('../config.php');
require_once('../config/mail.php');

// Already logged in — nothing to reset, send them onward.
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: teacher_dashboard");
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
        SELECT u.user_id, t.teacher_id, t.email, t.given_name, t.family_name
        FROM users u
        JOIN teachers t ON t.user_id = u.user_id
        WHERE u.username = ?
          AND t.email = ?
          AND u.role = 'teacher'
          AND u.is_active = 1
          AND t.is_active = 1
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

        $upd = $conn->prepare("UPDATE users SET reset_token_hash = ?, reset_token_expires_at = ? WHERE user_id = ?");
        $upd->bind_param("ssi", $token_hash, $expires_at, $row['user_id']);
        $upd->execute();
        $upd->close();

        try {
            $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            $resetUrl = "$scheme://$host$basePath/teacher_reset_password?token=$raw_token";

            $mail = getMailer();
            $bodyHtml = '<p>Hi ' . htmlspecialchars($row['given_name']) . ', we received a request to reset your Teacher Portal password. Click the button below to choose a new one — this link expires in 30 minutes and can only be used once.</p>'
                . '<div style="text-align:center;margin:22px 0;">'
                . '<a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;background:#1E4D3B;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:13px 26px;border-radius:8px;">Reset My Password</a>'
                . '</div>'
                . '<p style="font-size:12px;color:#8A8A9A;">If the button doesn\'t work, copy and paste this link into your browser:<br>' . htmlspecialchars($resetUrl) . '</p>'
                . '<p style="margin-top:16px;">If you didn\'t request this, you can safely ignore this email — your password won\'t change.</p>';
            $altBody = "Reset your Teacher Portal password (expires in 30 minutes):\n$resetUrl\n\nIf you didn't request this, ignore this email.";

            send_branded_email(
                $mail,
                $row['email'],
                trim($row['given_name'] . ' ' . $row['family_name']),
                'Reset Your Teacher Portal Password',
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
<title>Forgot Password — Teacher Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<style>
  :root{
    --brand-primary:#1E4D3B;
    --brand-primary-hover:#163829;
    --brand-dark:#12201A;
    --brand-mist:#E7F1EC;
  }
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  html,body{height:100%;}
  body{
    font-family:'Inter',sans-serif;
    min-height:100vh;
    display:flex;align-items:center;justify-content:center;
    padding:32px 20px;
    background:
      radial-gradient(720px 480px at 14% -6%, rgba(30,77,59,0.14), transparent 60%),
      radial-gradient(640px 460px at 108% 8%, rgba(30,77,59,0.10), transparent 55%),
      #F6F8F6;
    color:#1A241F;
  }

  .card{
    width:100%;max-width:420px;
    background:#ffffff;
    border:1px solid #E6ECE8;
    border-radius:20px;
    box-shadow:0 24px 60px rgba(18,32,26,0.10), 0 2px 8px rgba(18,32,26,0.05);
    padding:2.5rem 2.25rem 2.25rem;
  }

  .step-track{
    display:flex;align-items:center;justify-content:center;gap:6px;
    margin-bottom:1.6rem;
  }
  .step-dot{width:7px;height:7px;border-radius:50%;background:#D8E3DD;}
  .step-dot.active{background:var(--brand-primary);width:18px;border-radius:4px;}

  .icon-badge{
    width:52px;height:52px;border-radius:14px;
    background:var(--brand-mist);color:var(--brand-primary);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 1.1rem;
  }
  .icon-badge svg{width:24px;height:24px;}

  h1{
    font-family:'Fraunces',serif;font-weight:600;font-size:1.5rem;
    text-align:center;color:#141C17;line-height:1.25;margin-bottom:0.5rem;
  }
  .lead{
    text-align:center;font-size:0.87rem;color:#5E6E67;line-height:1.55;
    max-width:32ch;margin:0 auto 1.75rem;
  }
  .lead strong{color:#1A241F;}

  form{display:flex;flex-direction:column;gap:16px;}

  label{
    display:block;font-size:0.68rem;font-weight:700;letter-spacing:0.07em;
    text-transform:uppercase;color:#5E6E67;margin-bottom:7px;
  }
  input[type="text"],input[type="email"]{
    width:100%;height:44px;border:1px solid #DCE6E1;border-radius:10px;
    background:#F8FAF9;padding:0 14px;font-family:inherit;font-size:0.9rem;
    color:#1A241F;outline:none;transition:border-color .15s,box-shadow .15s,background .15s;
  }
  input::placeholder{color:#9AA8A2;}
  input:focus{border-color:var(--brand-primary);background:#fff;box-shadow:0 0 0 3px rgba(30,77,59,0.12);}

  .alert{
    font-size:0.82rem;line-height:1.5;border-radius:10px;padding:11px 14px;
    background:#FDF0EF;border:1px solid #F5C6C2;color:#B3261E;
  }

  .btn-login{
    margin-top:4px;width:100%;background:#1C2628;color:#fff;border:none;border-radius:0px;
    padding:14px 16px;font-size:0.95rem;font-weight:700;cursor:pointer;
    box-shadow:0 10px 24px rgba(28,38,40,0.32), 0 2px 6px rgba(28,38,40,0.22);
    transition:background .15s ease, box-shadow .15s ease, transform .1s ease;
    font-family:'Inter',sans-serif;
  }
  .btn-login:hover{background:#2A3638;box-shadow:0 14px 30px rgba(28,38,40,0.38), 0 3px 8px rgba(28,38,40,0.26);}
  .btn-login:active{transform:translateY(1px);}

  .back-link{
    display:block;text-align:center;margin-top:1.4rem;font-size:0.83rem;color:#5E6E67;
  }
  .back-link a{color:var(--brand-primary);font-weight:700;text-decoration:none;}
  .back-link a:hover{text-decoration:underline;}

  /* ── Sent-confirmation state ─────────────────────────────────────────── */
  .sent-icon{
    width:60px;height:60px;border-radius:50%;
    background:var(--brand-mist);color:var(--brand-primary);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 1.2rem;
  }
  .sent-icon svg{width:26px;height:26px;}
  .sent-note{
    background:#F8FAF9;border:1px solid #E6ECE8;border-radius:10px;
    padding:12px 14px;font-size:0.78rem;color:#5E6E67;line-height:1.55;
    margin-top:1.5rem;
  }
</style>
</head>
<body>

<div class="card">

  <?php if ($submitted): ?>

    <div class="sent-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </div>
    <h1>Check your email</h1>
    <p class="lead">If that username and email match an account, we've sent a secure link to reset the password. The link expires in <strong>30 minutes</strong>.</p>

    <div class="sent-note">Didn't get it? Check your spam folder, or make sure the email matches the one on file with the Records office.</div>

    <div class="back-link">Back to <a href="teacher_login">Log In</a></div>

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

    <form method="POST" action="teacher_forgotpassword" data-confirm="Send a password reset link to this email?" data-icon="question">
      <?php if ($error_message): ?>
        <div class="alert"><?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <div>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required>
      </div>

      <div>
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required>
      </div>

      <button type="submit" name="reset_btn" class="btn-login">Send Reset Link</button>
    </form>

    <div class="back-link">Remembered it? <a href="teacher_login">Log in here</a></div>

  <?php endif; ?>

</div>

<script src="../assets/js/sweetalert2.all.min.js"></script>
<script>
  // Global SweetAlert for data-confirm forms (falls back to native confirm())
  // — same pattern as teacherportal/teacher_sidebar.php's, copied here since
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
