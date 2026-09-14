-- Reference schema only — not executed by the application.
-- Previously this table was created via an unconditional
-- `CREATE TABLE IF NOT EXISTS` in shared/helpers/functions.php, run on
-- every page load. The table already exists in the live database, so
-- that DDL was removed from the app; this file documents its structure
-- for anyone setting up a fresh database.
--
-- Staging table only — a submission here never touches `payments` (the
-- trusted ledger every balance calc reads from) until Treasury confirms it.

CREATE TABLE IF NOT EXISTS online_payment_submissions (
    submission_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enrollment_id     INT NOT NULL,
    amount            DECIMAL(10,2) NOT NULL,
    payment_method    ENUM('GCash','Bank Transfer','Card','Maya','GrabPay') NOT NULL,
    reference_no      VARCHAR(50) NOT NULL,
    status            ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    submitted_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by       INT UNSIGNED NULL,
    reviewed_at       TIMESTAMP NULL,
    rejection_reason  VARCHAR(255) NULL,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id)
);
