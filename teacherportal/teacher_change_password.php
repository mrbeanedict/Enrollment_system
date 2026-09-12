<?php
session_name('TEACHER_SESSID');
session_start();
include_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: teacher_login"); exit();
}

// Nothing to force — send them on to the normal dashboard.
if (empty($_SESSION['must_change_password'])) {
    header("Location: teacher_dashboard"); exit();
}

$user_id = (int) $_SESSION['user_id'];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($current_hash);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current, $current_hash)) {
        $error = 'Your current (temporary) password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new === $current) {
        $error = 'New password must be different from your temporary password.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $new_hash = password_hash($new, PASSWORD_BCRYPT);
        $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?");
        $upd->bind_param('si', $new_hash, $user_id);
        $upd->execute();
        $upd->close();

        $_SESSION['must_change_password'] = false;

        header("Location: teacher_dashboard"); exit();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password — Teacher Portal</title>
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
</style>
</head>
<body>

<div class="card">

  <div class="icon-badge">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
  </div>
  <h1>Set a new password</h1>
  <p class="lead">For security, you must change your temporary password before continuing. It must be at least 8 characters.</p>

  <form method="POST" action="teacher_change_password">
    <?php if ($error): ?>
      <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div>
      <label for="current_password">Temporary Password</label>
      <div class="pw-field">
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        <button type="button" class="toggle-eye" data-target="current_password" aria-label="Show password">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
        </button>
      </div>
    </div>

    <div>
      <label for="new_password">New Password</label>
      <div class="pw-field">
        <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters" minlength="8" autocomplete="new-password" required>
        <button type="button" class="toggle-eye" data-target="new_password" aria-label="Show password">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
        </button>
      </div>
    </div>

    <div>
      <label for="confirm_password">Confirm New Password</label>
      <div class="pw-field">
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Retype your new password" minlength="8" autocomplete="new-password" required>
        <button type="button" class="toggle-eye" data-target="confirm_password" aria-label="Show password">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
        </button>
      </div>
    </div>

    <button type="submit" name="change_password" class="btn-primary">Update Password</button>
  </form>

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

</body>
</html>
