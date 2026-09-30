-- Migration: class announcements on the LMS subject Stream.
-- Applied by hand to the live database (enroll7_db); safe to re-run.
--
-- A teacher's class announcement is stored as a discussion thread with
-- kind = 'announcement' rather than in a table of its own, so its comments
-- are ordinary discussion_posts and reuse all of the discussion code:
-- enrollment/assignment checks, locking, teacher moderation, notifications.
--
--   discussion    student- or teacher-started thread  (Discussions tab)
--   announcement  teacher-only post, commentable      (Stream tab, pinned in the teacher's list)
--
-- The school-wide `announcements` table (admin, whole school) is unrelated.

ALTER TABLE discussion_threads
    ADD COLUMN IF NOT EXISTS kind ENUM('discussion','announcement') NOT NULL DEFAULT 'discussion' AFTER title;
