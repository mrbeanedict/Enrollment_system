-- Migration: assignment Turn in / Unsubmit, multiple attachments, private comments.
-- Applied by hand to the live database (enroll7_db); safe to re-run.
--
-- A submission now has a status:
--   draft      files attached but not handed in — only the student sees them
--   turned_in  handed in (submitted_at = when); what the teacher sees and grades
-- Unsubmit moves turned_in back to draft and keeps the files. Only turned_in
-- counts as "submitted" anywhere (gradebook, To-do, Due lists).
--
-- Existing rows were all real submissions, so they become turned_in (the
-- column is added with that default, then the default flips to draft) and
-- their single file moves into gradebook_submission_files. The old
-- file_path / original_filename columns are kept, nullable, but no longer read.

ALTER TABLE gradebook_submissions
    ADD COLUMN IF NOT EXISTS status ENUM('draft','turned_in') NOT NULL DEFAULT 'turned_in' AFTER student_id;
ALTER TABLE gradebook_submissions
    ALTER COLUMN status SET DEFAULT 'draft';
ALTER TABLE gradebook_submissions
    MODIFY submitted_at TIMESTAMP NULL DEFAULT NULL,
    MODIFY file_path VARCHAR(255) NULL DEFAULT NULL,
    MODIFY original_filename VARCHAR(255) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS gradebook_submission_files (
    file_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id     INT UNSIGNED NOT NULL,
    file_path         VARCHAR(255) NOT NULL,  -- stored name under uploads/gradebook_submissions/
    original_filename VARCHAR(255) NOT NULL,
    file_size         INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    KEY submission_id (submission_id),
    CONSTRAINT fk_gsf_submission FOREIGN KEY (submission_id)
        REFERENCES gradebook_submissions (submission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO gradebook_submission_files (submission_id, file_path, original_filename, uploaded_at)
SELECT gs.submission_id, gs.file_path, gs.original_filename, COALESCE(gs.submitted_at, CURRENT_TIMESTAMP)
FROM gradebook_submissions gs
WHERE gs.file_path IS NOT NULL AND gs.file_path <> ''
  AND NOT EXISTS (SELECT 1 FROM gradebook_submission_files f
                  WHERE f.submission_id = gs.submission_id AND f.file_path = gs.file_path);

-- Private comments: one thread per (assignment, student), between that
-- student and the teacher only. Keyed by item + student rather than
-- submission, so it exists before any file is attached and survives unsubmit.
CREATE TABLE IF NOT EXISTS submission_comments (
    comment_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id           INT UNSIGNED NOT NULL,
    student_id        INT NOT NULL,
    author_type       ENUM('student','teacher') NOT NULL,
    author_teacher_id INT UNSIGNED NULL DEFAULT NULL,
    body              TEXT NOT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY item_student (item_id, student_id),
    CONSTRAINT fk_sc_item    FOREIGN KEY (item_id)           REFERENCES gradebook_items (item_id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_student FOREIGN KEY (student_id)        REFERENCES students (student_id),
    CONSTRAINT fk_sc_teacher FOREIGN KEY (author_teacher_id) REFERENCES teachers (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
