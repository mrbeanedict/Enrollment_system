<?php
// A distinct cookie name keeps the student portal's session independent
// from the admin/staff one — otherwise logging into one role in a
// second browser tab would silently overwrite the other tab's session
// (they'd share the same default PHP session cookie).
session_name('STUDENT_SESSID');
session_start();
include('../config.php');

// Already logged in — don't show the login form again, send them onward.
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

            header("Location: student_dashboard");
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
<title>Sign In — Student Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,450;9..144,560;9..144,650&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_student.css') ?>">
</head>
<body class="auth-page auth-landing-page">

<?php $modal_open = !empty($error_message); ?>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<img class="bg-photo" src="../assets/images/background/ui_landscape_clear.png" alt="Greenfield Senior High School">
<div class="bg-scrim"></div>

<div class="landing<?= $modal_open ? ' hidden' : '' ?>" id="landing">
  <div class="landing-mark"><img src="../assets/images/log_ui.png" alt=""></div>
  <p class="landing-eyebrow">Greenfield Senior High School</p>
  <h1 class="landing-title">Student Portal</h1>
  <p class="landing-sub">Sign in to view your enrollment, schedule, and payments.</p>
  <button type="button" class="btn-open-login" id="openLoginBtn">Log In</button>
</div>

<div class="login-modal<?= $modal_open ? ' open' : '' ?>" id="loginModal">
<div class="modal">
  <button type="button" class="close-btn" id="closeLoginBtn" aria-label="Close">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
  </button>

  <div class="crest">
    <img src="../assets/images/log_ui.png" alt="Greenfield Senior High School">
  </div>

  <p class="school-name">GREENFIELD SENIOR HIGH SCHOOL</p>
  <h1>Student Portal</h1>
  <p class="lede">Sign in to view your enrollment, schedule, and payments.</p>

  <form method="POST" action="student_login">
    <?php if (!empty($error_message)): ?>
      <div class="auth-error"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="field">
      <label class="field-label" for="username">Username</label>
      <div class="input-wrap">
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
        <label class="field-label" for="password">Password</label>
        <span class="capslock-note" id="capslockNote">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 14h6l-1 8 10-14h-6l1-6z"/></svg>
          Caps Lock is on
        </span>
      </div>
      <div class="input-wrap">
        <input
          type="password"
          id="password"
          name="password"
          placeholder="Enter your password"
          autocomplete="current-password"
          required
        >
        <button type="button" class="eye-btn" id="toggleEye" aria-label="Show password">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" id="eyeIcon"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>
        </button>
      </div>
    </div>

    <div class="field-options">
      <label class="remember-me" for="rememberMe">
        <input type="checkbox" id="rememberMe" name="remember_me">
        <span>Remember me</span>
      </label>
      <a class="forgot-link" href="student_forgotpassword">Forgot password?</a>
    </div>

    <input type="hidden" name="tab_token" id="tabToken" value="">

    <button type="submit" name="login_btn" class="login-btn">Log In</button>
  </form>
</div>
</div>

<p class="landing-footer-credit">&copy; <?= date('Y') ?> Greenfield Senior High School. All rights reserved.</p>

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
    function setCaps(isCaps) { capsNote.style.visibility = isCaps ? 'visible' : 'hidden'; }
    function checkCaps(e) {
      setCaps(typeof e.getModifierState === 'function' && e.getModifierState('CapsLock'));
    }
    pwInput.addEventListener('keydown', checkCaps);
    pwInput.addEventListener('keyup', checkCaps);
    pwInput.addEventListener('blur', function () { setCaps(false); });
  })();

  (function () {
    var landing  = document.getElementById('landing');
    var modal    = document.getElementById('loginModal');
    var openBtn  = document.getElementById('openLoginBtn');
    var closeBtn = document.getElementById('closeLoginBtn');

    function openModal()  { landing.classList.add('hidden'); modal.classList.add('open'); document.getElementById('username').focus(); }
    function closeModal() { modal.classList.remove('open'); landing.classList.remove('hidden'); }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);

    // Click on the backdrop (not the card itself) closes the modal.
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
  })();

  document.querySelector('form[method="POST"]').addEventListener('submit', function () {
    document.getElementById('pageLoader').classList.add('show');
  });

  (function () {
    var token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36).slice(2));
    sessionStorage.setItem('sg_tab_token_student', token);
    document.getElementById('tabToken').value = token;
  })();
</script>

</body>
</html>
