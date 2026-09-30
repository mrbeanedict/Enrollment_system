-- Migration: written feedback alongside a grade.
-- Applied by hand to the live database (enroll7_db); safe to re-run.
--
-- The teacher grades turned-in work from roles/teacher/teacher_gradebook.php
-- (score + optional feedback); the student reads both on
-- roles/lms/student_assignments.php. Feedback lives on the same
-- gradebook_scores row as the score, so the existing grade rollups are
-- untouched and a score changed from Grade Management keeps its feedback.

ALTER TABLE gradebook_scores
    ADD COLUMN IF NOT EXISTS feedback    TEXT      NULL DEFAULT NULL AFTER raw_score,
    ADD COLUMN IF NOT EXISTS feedback_at TIMESTAMP NULL DEFAULT NULL AFTER feedback;
