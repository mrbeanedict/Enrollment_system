<?php
session_name('STUDENT_LMS_SESSID');
session_start();
session_destroy();
header("Location: lms_login");
exit();
