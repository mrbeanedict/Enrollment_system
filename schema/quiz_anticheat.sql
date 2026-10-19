-- Migration: quiz anti-cheat (tab-switch / leave tracking). Safe to re-run.
--
-- roles/lms/take_quiz.php reports every time a student leaves the quiz;
-- ajax/quiz_violation.php counts it here. 2 warnings, and the 3rd strike
-- auto-submits (auto_submitted = 1). last_seen is a heartbeat used to catch
-- a closed browser whose "leaving" message never arrived.

ALTER TABLE quiz_attempts
    ADD COLUMN IF NOT EXISTS violations        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_violation_at TIMESTAMP        NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_seen         TIMESTAMP        NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS auto_submitted    TINYINT(1)       NOT NULL DEFAULT 0;
