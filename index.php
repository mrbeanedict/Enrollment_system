<?php
// Project root has no homepage of its own — the public site lives under
// roles/student/public/. This exists purely so localhost/Enrollment_system/
// (typed directly, bookmarked, or reached via a relative "index.php" link
// from a page outside that folder) doesn't 404. Only the APP_URL helper
// rather than all of bootstrap.php (DB connection and all) just for a redirect.
require_once __DIR__ . '/shared/helpers/app_url.php';
header('Location: ' . APP_URL . '/roles/student/public/index');
exit;
