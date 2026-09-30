-- ============================================================
-- section_subject_meetings — a subject can meet several times a week
-- ------------------------------------------------------------
-- Before: section_subjects held ONE day/start/end/room per subject per
-- semester, so a subject could only meet once a week.
-- After:  every weekly class meeting is its own row here. The teacher
--         stays per subject (section_subjects.teacher_id).
--
-- Rules (enforced by the key + roles/staff/scheduler/_schedule_lib.php):
--   - at most one meeting per subject per day (uq_one_per_day)
--   - meetings are removed with their subject row (FK cascade)
--
-- section_subjects.day/start_time/end_time/room are kept as a mirror of
-- the subject's FIRST meeting (earliest day, then time), written by the
-- scheduler on every save — so anything still reading the old columns
-- keeps working. New code reads this table.
--
-- Safe to re-run: CREATE IF NOT EXISTS + INSERT IGNORE backfill.
-- ============================================================

CREATE TABLE IF NOT EXISTS `section_subject_meetings` (
  `meeting_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `semester`   tinyint(1) NOT NULL DEFAULT 1,
  `day`        enum('Mon','Tue','Wed','Thu','Fri') NOT NULL,
  `start_time` time NOT NULL,
  `end_time`   time NOT NULL,
  `room`       varchar(50) DEFAULT NULL COMMENT 'Room override for this meeting; NULL = section homeroom',
  PRIMARY KEY (`meeting_id`),
  UNIQUE KEY `uq_one_per_day` (`section_id`, `subject_id`, `semester`, `day`),
  KEY `idx_slot` (`semester`, `day`, `start_time`),
  CONSTRAINT `fk_meetings_section_subject`
    FOREIGN KEY (`section_id`, `subject_id`, `semester`)
    REFERENCES `section_subjects` (`section_id`, `subject_id`, `semester`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill: each existing single slot becomes that subject's first meeting.
INSERT IGNORE INTO `section_subject_meetings` (section_id, subject_id, semester, day, start_time, end_time, room)
SELECT section_id, subject_id, semester, day, start_time, end_time, NULLIF(room, '')
FROM `section_subjects`
WHERE day IS NOT NULL AND start_time IS NOT NULL AND end_time IS NOT NULL;
