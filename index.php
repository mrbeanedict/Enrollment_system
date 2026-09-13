<?php
// Project root has no homepage of its own — the public site lives under
// roles/student/public/. This exists purely so localhost/Enrollment_system/
// (typed directly, bookmarked, or reached via a relative "index.php" link
// from a page outside that folder) doesn't 404. Hardcoded rather than
// pulling in bootstrap.php (DB connection and all) just for a redirect —
// same site-root prefix as APP_URL in shared/config/config.php.
header('Location: /Enrollment_system/roles/student/public/index');
exit;
