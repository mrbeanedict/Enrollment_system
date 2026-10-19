-- Migration: question types beyond multiple choice (identification,
-- fill in the blank, enumeration) in online quizzes. Safe to re-run.
--
-- Accepted answers for typed questions live in quiz_choices rows with
-- is_correct = 1 (identification / fill_blank: one row; enumeration: one
-- row per required item; "|" separates alternate accepted spellings inside
-- a row). The student's typed answer is stored in quiz_answers.answer_text
-- (enumeration as a JSON array); points_earned carries partial credit.

ALTER TABLE quiz_questions
    ADD COLUMN IF NOT EXISTS question_type ENUM('multiple_choice','identification','fill_blank','enumeration')
        NOT NULL DEFAULT 'multiple_choice' AFTER question_text;

ALTER TABLE quiz_answers
    ADD COLUMN IF NOT EXISTS answer_text   TEXT         NULL DEFAULT NULL AFTER choice_id,
    ADD COLUMN IF NOT EXISTS points_earned DECIMAL(5,2) NULL DEFAULT NULL AFTER is_correct;
