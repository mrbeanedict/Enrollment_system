<?php
session_name('STUDENT_SESSID');
session_start();
include('../config.php');

$raw_token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$token_valid    = false;
$token_error    = "";
$form_error     = "";
$done           = false;
$user_student_id = null;
$student_id       = null;

if ($raw_token === '') {
    $token_error = "This reset link is missing its token.";
} else {
    $token_hash = hash('sha256', $raw_token);

    $stmt = $conn->prepare("
        SELECT user_student_id, student_id, reset_token_expires_at
        FROM users_student
        WHERE reset_token_hash = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if ($row['reset_token_expires_at'] !== null && strtotime($row['reset_token_expires_at']) >= time()) {
            $token_valid      = true;
            $user_student_id  = (int) $row['user_student_id'];
            $student_id       = (int) $row['student_id'];
        } else {
            $token_error = "This reset link has expired. Request a new one to continue.";
        }
    } else {
        $token_error = "This reset link is invalid or has already been used.";
    }
    $stmt->close();
}

if ($token_valid && isset($_POST['set_password_btn'])) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 8) {
        $form_error = "Password must be at least 8 characters.";
    } elseif ($new_password !== $confirm) {
        $form_error = "Passwords do not match.";
    } else {
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);

        // Token is single-use — clearing it here means a replayed link
        // (browser back button, a second click) can never succeed again.
        $upd = $conn->prepare("
            UPDATE users_student
            SET password_hash = ?, reset_token_hash = NULL, reset_token_expires_at = NULL
            WHERE user_student_id = ?
        ");
        $upd->bind_param("si", $new_hash, $user_student_id);
        $upd->execute();
        $upd->close();

        $logStmt = $conn->prepare("
            INSERT INTO student_activity_log (student_id, activity_type, ip_address)
            VALUES (?, 'password_reset', ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $logStmt->bind_param("is", $student_id, $ip);
        $logStmt->execute();
        $logStmt->close();

        $done = true;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set New Password — Student Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<style>
  :root{
    --brand-primary:#1E4D3B;
    --brand-primary-hover:#163829;
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

  .icon-badge{
    width:52px;height:52px;border-radius:14px;
    background:var(--brand-mist);color:var(--brand-primary);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 1.1rem;
  }
  .icon-badge.bad{background:#FDF0EF;color:#C0392B;}
  .icon-badge.ok{background:var(--brand-mist);color:var(--brand-primary);}
  .icon-badge svg{width:24px;height:24px;}

  h1{
    font-family:'Fraunces',serif;font-weight:600;font-size:1.5rem;
    text-align:center;color:#141C17;line-height:1.25;margin-bottom:0.5rem;
  }
  .lead{
    text-align:center;font-size:0.87rem;color:#5E6E67;line-height:1.55;
    max-width:34ch;margin:0 auto 1.75rem;
  }

  form{display:flex;flex-direction:column;gap:16px;}

  label{
    display:block;font-size:0.68rem;font-weight:700;letter-spacing:0.07em;
    text-transform:uppercase;color:#5E6E67;margin-bottom:7px;
  }
  .pw-field{position:relative;}
  input[type="password"],input[type="text"]{
    width:100%;height:44px;border:1px solid #DCE6E1;border-radius:10px;
    background:#F8FAF9;padding:0 42px 0 14px;font-family:inherit;font-size:0.9rem;
    color:#1A241F;outline:none;transition:border-color .15s,box-shadow .15s,background .15s;
  }
  input::placeholder{color:#9AA8A2;}
  input:focus{border-color:var(--brand-primary);background:#fff;box-shadow:0 0 0 3px rgba(30,77,59,0.12);}

  .toggle-eye{
    position:absolute;right:10px;top:50%;transform:translateY(-50%);
    background:none;border:none;color:#9AA8A2;cursor:pointer;padding:6px;
    display:flex;align-items:center;justify-content:center;
  }
  .toggle-eye:hover{color:#5E6E67;}
  .toggle-eye svg{width:17px;height:17px;}

  .hint{font-size:0.74rem;color:#8A978F;margin-top:-6px;}

  .alert{
    font-size:0.82rem;line-height:1.5;border-radius:10px;padding:11px 14px;
    background:#FDF0EF;border:1px solid #F5C6C2;color:#B3261E;
  }

  .btn-primary{
    height:46px;background:var(--brand-primary);color:#fff;border:none;
    border-radius:10px;font-family:inherit;font-size:0.9rem;font-weight:700;
    letter-spacing:0.01em;cursor:pointer;margin-top:2px;
    transition:background .15s,transform .1s;
  }
  .btn-primary:hover{background:var(--brand-primary-hover);}
  .btn-primary:active{transform:scale(0.98);}

  .back-link{
    display:block;text-align:center;margin-top:1.4rem;font-size:0.83rem;color:#5E6E67;
  }
  .back-link a{color:var(--brand-primary);font-weight:700;text-decoration:none;}
  .back-link a:hover{text-decoration:underline;}
</style>
</head>
<body>

<div class="card">

  <?php if ($done): ?>

    <div class="icon-badge ok">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <h1>Password updated</h1>
    <p class="lead">Your password has been changed. You can now log in with your new password.</p>
    <a href="student_login" style="text-decoration:none;">
      <button type="button" class="btn-primary" style="width:100%;">Go to Log In</button>
    </a>

  <?php elseif (!$token_valid): ?>

    <div class="icon-badge bad">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
    </div>
    <h1>Link not valid</h1>
    <p class="lead"><?= htmlspecialchars($token_error) ?></p>
    <a href="student_forgotpassword" style="text-decoration:none;">
      <button type="button" class="btn-primary" style="width:100%;">Request a New Link</button>
    </a>
    <div class="back-link">Back to <a href="student_login">Log In</a></div>

  <?php else: ?>

    <div class="icon-badge ok">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
    </div>
    <h1>Set a new password</h1>
    <p class="lead">Choose a new password for your Student Portal account. It must be at least 8 characters.</p>

    <form method="POST" action="student_reset_password" data-confirm="Set this as your new password?" data-icon="question">
      <input type="hidden" name="token" value="<?= htmlspecialchars($raw_token) ?>">

      <?php if ($form_error): ?>
        <div class="alert"><?= htmlspecialchars($form_error) ?></div>
      <?php endif; ?>

      <div>
        <label for="new_password">New Password</label>
        <div class="pw-field">
          <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters" minlength="8" autocomplete="new-password" required>
          <button type="button" class="toggle-eye" data-target="new_password" aria-label="Show password">
            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
          </button>
        </div>
      </div>

      <div>
        <label for="confirm_password">Confirm New Password</label>
        <div class="pw-field">
          <input type="password" id="confirm_password" name="confirm_password" placeholder="Retype your new password" minlength="8" autocomplete="new-password" required>
          <button type="button" class="toggle-eye" data-target="confirm_password" aria-label="Show password">
            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
          </button>
        </div>
      </div>

      <button type="submit" name="set_password_btn" class="btn-primary">Update Password</button>
    </form>

    <div class="back-link">Back to <a href="student_login">Log In</a></div>

  <?php endif; ?>

</div>

<script>
  document.querySelectorAll('.toggle-eye').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.target);
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
  });
</script>

<script src="../js/sweetalert2.all.min.js"></script>
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
