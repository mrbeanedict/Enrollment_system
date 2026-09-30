-- Migration: notification `type` (drives the icon/colour in the bell dropdowns).
-- Applied by hand to the live database (enroll7_db); safe to re-run.
--
--   general    default bell                                   (lessons, quizzes, discussions, grades)
--   approval   exclamation mark — someone must act            (coordinator: "awaiting approval/review")
--   schedule   calendar — section / subject / schedule news   (approved/rejected, teacher assignments)
--   highlight  prominent yellow bell — money & enrollment     (payment confirmed/declined/pending, Semester 2)
--
-- VARCHAR rather than ENUM so a new type is a PHP + CSS change, not another ALTER.
-- The Today / This week / Earlier grouping is NOT stored: it is computed from
-- created_at in each bell's SELECT (see shared/helpers/notify.php, notif_group_sql()),
-- because a stored "today" flag would be wrong by midnight.

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS type VARCHAR(20) NOT NULL DEFAULT 'general' AFTER link;

-- Backfill existing rows from their message text (only rows still on the default)
UPDATE notifications SET type = 'approval'
 WHERE type = 'general'
   AND (message LIKE '%awaiting approval%' OR message LIKE '%awaiting review%' OR message LIKE '%needs re-approval%');

UPDATE notifications SET type = 'schedule'
 WHERE type = 'general'
   AND (   message LIKE 'Section "%'
        OR message LIKE 'Subject "%'
        OR message LIKE 'Schedule for section "%'
        OR message LIKE 'You''ve been assigned to teach%');

UPDATE notifications SET type = 'highlight'
 WHERE type = 'general'
   AND (   message LIKE 'Your online payment%'
        OR message LIKE 'Your payment%'
        OR message LIKE 'Your Semester 2 request%');
