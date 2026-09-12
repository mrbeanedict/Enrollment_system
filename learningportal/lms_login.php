<?php
// Distinct cookie name from student_login.php's STUDENT_SESSID — the LMS
// portal and the full Student Portal are fully independent sessions, so
// logging into one never affects the other, even in separate browser tabs.
session_name('STUDENT_LMS_SESSID');
session_start();
include('../config.php');

// Already logged in — don't show the login form again, send them onward.
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') === 'student') {
    header("Location: lms_home");
    exit();
}

$error_message = "";

if (isset($_POST['login_btn'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $login_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $error_message = login_throttle_check($conn, $username, $login_ip);

    if ($error_message !== '') {
        // Too many recent failed attempts from this username+IP — skip
        // the credential check entirely.
    } else {

    $stmt = $conn->prepare("
        SELECT user_student_id, student_id, username, password_hash
        FROM users_student
        WHERE username = ?
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $row = $result->fetch_assoc();

        if (password_verify($password, $row['password_hash'])) {

            login_throttle_record($conn, $username, $login_ip, true);
            session_regenerate_id(true);
            $_SESSION['logged_in']       = true;
            $_SESSION['user_student_id'] = $row['user_student_id'];
            $_SESSION['username']        = $row['username'];
            $_SESSION['role']            = 'student';
            $_SESSION['student_id']      = $row['student_id'];
            $_SESSION['sg_tab_token']    = $_POST['tab_token'] ?? '';

            $logStmt = $conn->prepare("
                INSERT INTO student_activity_log (student_id, activity_type, ip_address)
                VALUES (?, 'login', ?)
            ");
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $logStmt->bind_param("is", $row['student_id'], $ip);
            $logStmt->execute();
            $logStmt->close();

            header("Location: lms_home");
            exit();

        } else {
            login_throttle_record($conn, $username, $login_ip, false);
            $error_message = "Invalid username or password.";
        }

    } else {
        login_throttle_record($conn, $username, $login_ip, false);
        $error_message = "Invalid username or password.";
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
<title>Sign In — Student LMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,450;9..144,560;9..144,650&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/css_lms.css?v=<?= filemtime(__DIR__ . '/../css/css_lms.css') ?>">
</head>
<body class="auth-page">

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<div class="auth-backdrop" aria-hidden="true">
  <span class="backdrop-blob backdrop-blob-cream"></span>
  <span class="backdrop-blob backdrop-blob-info"></span>
  <span class="backdrop-blob backdrop-blob-primary"></span>
  <span class="backdrop-blob backdrop-blob-dark"></span>
  <span class="backdrop-blob backdrop-blob-glow"></span>
</div>

<div class="auth-shell">

  <!-- ============ LEFT: GRADIENT PANEL ============ -->
  <div class="auth-gradient-side">
    <div class="auth-gradient-frame">
      <div class="gradient-bg">
        <div class="blob blob-cream"></div>
        <div class="blob blob-info"></div>
        <div class="blob blob-primary"></div>
        <div class="blob blob-dark"></div>
      </div>

      <div class="gradient-top">
        <img src="../images/logo_mini2.png" alt="Greenfield Senior High School" class="brand-logo2" width="505" height="55">
      </div>

      <div class="gradient-bottom">
        <span class="gradient-eyebrow">Learning Management System</span>
        <p class="gradient-tagline">Your lessons, assignments, quizzes, and discussions all in one place.</p>
      </div>
    </div>
  </div>

  <!-- ============ RIGHT: LOGIN FORM ============ -->
  <div class="auth-form-side">
    <div class="auth-form-inner">
      <div class="auth-mark">
        <img src="../images/log_ui.png" alt="Greenfield Senior High School">
      </div>
      <span class="auth-eyebrow">Greenfield Senior High School</span>
      <h1>Student LMS</h1>
      <p class="auth-lead">Welcome back! Let's get to class.</p>

      <form method="POST" action="lms_login">
        <div class="auth-error<?= empty($error_message) ? ' is-empty' : '' ?>"><?= empty($error_message) ? '&nbsp;' : htmlspecialchars($error_message) ?></div>

        <div class="field">
          <label for="username">Username</label>
          <div class="field-input">
            <input
              type="text"
              id="username"
              name="username"
              placeholder="Enter your username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              autocomplete="username"
              required
            >
          </div>
        </div>

        <div class="field">
          <div class="field-label-row">
            <label for="password">Password</label>
            <span class="capslock-note" id="capslockNote">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 14h6l-1 8 10-14h-6l1-6z"/></svg>
              Caps Lock is on
            </span>
          </div>
          <div class="field-input">
            <input
              type="password"
              id="password"
              name="password"
              placeholder="Enter your password"
              autocomplete="current-password"
              required
            >
            <button type="button" class="toggle-eye" id="toggleEye" aria-label="Show password">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" id="eyeIcon"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>
            </button>
          </div>
        </div>

        <div class="field-options">
          <label class="remember-me" for="rememberMe">
            <input type="checkbox" id="rememberMe" name="remember_me">
            <span>Remember me</span>
          </label>
          <a class="forgot-link" href="../studentportal/student_forgotpassword">Forgot password?</a>
        </div>

        <input type="hidden" name="tab_token" id="tabToken" value="">

        <button type="submit" name="login_btn" class="btn-login">Log In</button>
      </form>

      <div class="switch-role">Looking for the Student Portal? <a href="../studentportal/student_login">Log in here</a></div>
    </div>
  </div>

</div>

<p class="auth-footer-credit">&copy; <?= date('Y') ?> Greenfield Senior High School. All rights reserved.</p>

<script>
  const pwInput = document.getElementById('password');
  const toggleBtn = document.getElementById('toggleEye');
  const eyeIcon = document.getElementById('eyeIcon');

  const EYE_OPEN = `<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>`;
  const EYE_CLOSED = `<path d="M3 3l18 18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M9.9 5.1A10.6 10.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a15.6 15.6 0 0 1-3.2 4M6.2 6.7C3.6 8.5 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.1 0 2.1-.2 3-.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M9.9 14.1a3 3 0 0 0 4.2-4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>`;

  toggleBtn.addEventListener('click', ()=>{
    const isPassword = pwInput.type === 'password';
    pwInput.type = isPassword ? 'text' : 'password';
    eyeIcon.innerHTML = isPassword ? EYE_CLOSED : EYE_OPEN;
    toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
  });

  (function () {
    var capsNote = document.getElementById('capslockNote');
    if (!capsNote) return;
    // visibility (not display/hidden) — the note always keeps its slot in
    // the label row, so toggling it can never change the row's height or
    // nudge anything else in the card.
    function setCaps(isCaps) { capsNote.style.visibility = isCaps ? 'visible' : 'hidden'; }
    function checkCaps(e) {
      setCaps(typeof e.getModifierState === 'function' && e.getModifierState('CapsLock'));
    }
    pwInput.addEventListener('keydown', checkCaps);
    pwInput.addEventListener('keyup', checkCaps);
    pwInput.addEventListener('blur', function () { setCaps(false); });
  })();

  document.querySelector('form[method="POST"]').addEventListener('submit', function () {
    document.getElementById('pageLoader').classList.add('show');
  });

  (function () {
    var token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36).slice(2));
    sessionStorage.setItem('sg_tab_token_lms', token);
    document.getElementById('tabToken').value = token;
  })();
</script>

</body>
</html>
