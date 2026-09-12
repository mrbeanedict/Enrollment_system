<?php
session_name('STUDENT_SESSID');
session_start();
session_destroy();
header("Location: student_login");
exit();
