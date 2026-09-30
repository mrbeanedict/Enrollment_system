<?php
// Requires: $conn open (mysqli). Include after config.php.

// Notification types — drive the icon/colour in the bell dropdowns
// (assets/css/ui_modal.css, [data-type=…]). Stored in notifications.type
// (see schema/notifications_type.sql). Unknown values fall back to 'general'.
//   general    default bell                        lessons, quizzes, discussions, grades
//   approval   exclamation — someone must act      coordinator: "awaiting approval/review"
//   schedule   calendar — section/subject/schedule approved/rejected, teacher assignments
//   highlight  prominent yellow bell               payment + enrollment status for students
const NOTIF_TYPES = ['general', 'approval', 'schedule', 'highlight'];

// Shared insert used by every notify_* helper below. $recipient_type scopes
// the row to the right portal's session/user-id space — staff/teacher share
// the `users` table (their user_id values could theoretically collide with
// a student's user_student_id), students don't live in `users` at all, so
// every query against this table must always filter on both columns
// together, never user_id alone.
function notify_insert(mysqli $conn, string $recipient_type, array $ids, string $message, string $link, string $type = 'general'): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return;
    if (!in_array($type, NOTIF_TYPES, true)) $type = 'general';

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, recipient_type, message, link, type) VALUES (?, ?, ?, ?, ?)");
    foreach ($ids as $id) {
        $stmt->bind_param('issss', $id, $recipient_type, $message, $link, $type);
        $stmt->execute();
    }
    $stmt->close();
}

function notify_users(mysqli $conn, array $user_ids, string $message, string $link, string $type = 'general'): void {
    notify_insert($conn, 'staff', $user_ids, $message, $link, $type);
}

// $teacher_user_ids are teachers.user_id values (nullable — a teacher with
// no portal account yet has none; notify_insert()'s array_filter already
// silently drops any null/zero entries).
function notify_teacher_users(mysqli $conn, array $teacher_user_ids, string $message, string $link, string $type = 'general'): void {
    notify_insert($conn, 'teacher', $teacher_user_ids, $message, $link, $type);
}

// $user_student_ids are users_student.user_student_id values.
function notify_student_users(mysqli $conn, array $user_student_ids, string $message, string $link, string $type = 'general'): void {
    notify_insert($conn, 'student', $user_student_ids, $message, $link, $type);
}

// Every active staff member of one department ('coordinator', 'treasury',
// 'records', 'registrar', 'scheduler' — departments.department_name).
function notify_department(mysqli $conn, string $department, string $message, string $link, string $type = 'general'): void {
    $deptId = department_id($conn, $department);
    if ($deptId === null) return;
    $ids = [];
    $res = mysqli_query($conn, "SELECT user_id FROM staff WHERE department_id = " . (int)$deptId . " AND is_active = 1");
    if ($res) {
        while ($row = mysqli_fetch_row($res)) { $ids[] = (int)$row[0]; }
    }
    notify_users($conn, $ids, $message, $link, $type);
}

function notify_coordinators(mysqli $conn, string $message, string $link, string $type = 'approval'): void {
    notify_department($conn, 'coordinator', $message, $link, $type);
}


// ── Bell dropdown support (student / teacher / LMS / staff) ─────────────────

// SELECT-list fragment: adds a `notif_group` column — 'today', 'week' (the
// previous 6 days) or 'earlier'. Computed in SQL from created_at, not stored:
// a stored flag would be stale by midnight, and CURDATE() shares MySQL's clock
// with the created_at default, so the two can never disagree.
function notif_group_sql(): string {
    return "CASE WHEN DATE(created_at) = CURDATE() THEN 'today'
                 WHEN created_at >= CURDATE() - INTERVAL 6 DAY THEN 'week'
                 ELSE 'earlier' END AS notif_group";
}

function notif_time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

// Prints the tab strip + item list for one bell dropdown. $prefix is the role
// prefix already used by that dropdown's CSS classes ('student', 'teacher',
// 'lms', 'staff'); $rows come from a SELECT that includes type + notif_group.
// Tab switching is client-side (assets/js/ui_notif.js): every item is
// rendered, and the script shows the default "Today" tab on load — without JS
// the full list simply stays visible.
function notif_render_list(string $prefix, array $rows): void {
    if (!$rows) {
        echo '<div class="' . $prefix . '-notif-empty">No notifications yet.</div>';
        return;
    }
    $tabs = [
        'today'   => ['Today',     'Nothing new today.'],
        'week'    => ['This week', 'Nothing else this week.'],
        'earlier' => ['Earlier',   'No older notifications.'],
        'all'     => ['All',       'No notifications yet.'],
    ];
    echo '<div class="ui-notif" data-notif-root data-default-tab="today">';
    echo '<div class="ui-notif-tabs" role="tablist">';
    foreach ($tabs as $key => [$label, $empty]) {
        echo '<button type="button" class="ui-notif-tab' . ($key === 'today' ? ' active' : '') . '" role="tab"'
           . ' data-notif-tab="' . $key . '" data-empty="' . htmlspecialchars($empty) . '">' . $label . '</button>';
    }
    echo '</div>';
    foreach ($rows as $n) {
        $type = in_array($n['type'] ?? '', NOTIF_TYPES, true) ? $n['type'] : 'general';
        echo '<a href="' . APP_URL . '/' . htmlspecialchars($n['link']) . '"'
           . ' class="' . $prefix . '-notif-item' . ($n['is_read'] ? '' : ' unread') . '"'
           . ' data-type="' . $type . '" data-group="' . htmlspecialchars($n['notif_group']) . '">'
           . '<div class="' . $prefix . '-notif-msg">' . htmlspecialchars($n['message']) . '</div>'
           . '<div class="' . $prefix . '-notif-time">' . notif_time_ago($n['created_at']) . '</div>'
           . '</a>';
    }
    echo '<div class="' . $prefix . '-notif-empty" data-notif-empty hidden></div>';
    echo '</div>';
}
