<?php
// Project root has no homepage of its own — the public site lives under
// student/. This exists purely so localhost/Enrollment_system/ (typed
// directly, bookmarked, or reached via a relative "index.php" link from
// a page outside student/) doesn't 404.
header('Location: student/index');
exit;