<?php
session_name('TEACHER_SESSID');
session_start();
session_destroy();
header("Location: teacher_login");
exit();
