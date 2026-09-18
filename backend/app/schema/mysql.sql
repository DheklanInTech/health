-- MySQL / MariaDB schema for the Trial Path admin dashboard.
-- Safe to re-run. Apply with:  php app/bin/migrate.php
-- or paste into cPanel -> phpMyAdmin -> SQL.
--
-- Note: JSON payloads use LONGTEXT rather than the JSON type, because shared
-- hosting may run MariaDB or MySQL 5.6 where JSON behaves differently or is
-- absent. The application encodes/decodes, so nothing is lost.

CREATE TABLE IF NOT EXISTS submissions (
  id             CHAR(36)     NOT NULL,
  application_id VARCHAR(32)  NOT NULL,
  trial          VARCHAR(32)  NOT NULL,
  status         VARCHAR(24)  NOT NULL DEFAULT 'new',
  email          VARCHAR(255) NOT NULL,
  phone          VARCHAR(64)      NULL,
  age            SMALLINT         NULL,
  sex            VARCHAR(32)      NULL,
  answers        LONGTEXT     NOT NULL,
  eligibility    LONGTEXT     NOT NULL,
  verdict        VARCHAR(16)  NOT NULL DEFAULT 'review',
  bmi            DECIMAL(5,1)     NULL,
  ip_hash        VARCHAR(64)      NULL,
  user_agent     VARCHAR(255)     NULL,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_submissions_application_id (application_id),
  KEY idx_submissions_trial_created (trial, created_at),
  KEY idx_submissions_status (status),
  KEY idx_submissions_verdict (verdict),
  KEY idx_submissions_email (email),
  KEY idx_submissions_created (created_at),
  KEY idx_submissions_ip_recent (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_notes (
  id            CHAR(36)     NOT NULL,
  submission_id CHAR(36)     NOT NULL,
  author_email  VARCHAR(255) NOT NULL,
  body          TEXT         NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_notes_submission (submission_id, created_at),
  CONSTRAINT fk_notes_submission FOREIGN KEY (submission_id)
    REFERENCES submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id            CHAR(36)     NOT NULL,
  email         VARCHAR(255) NOT NULL,
  name          VARCHAR(255) NOT NULL,
  role          VARCHAR(16)  NOT NULL DEFAULT 'admin',
  password_hash VARCHAR(255) NOT NULL,
  disabled      TINYINT(1)   NOT NULL DEFAULT 0,
  must_change   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  actor_email VARCHAR(255) NOT NULL,
  action      VARCHAR(64)  NOT NULL,
  target_type VARCHAR(32)  NOT NULL,
  target_id   VARCHAR(64)      NULL,
  meta        TEXT             NULL,
  ip_hash     VARCHAR(64)      NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT       NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(255) NOT NULL,
  ip_hash    VARCHAR(64)  NOT NULL,
  successful TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_attempts_lookup (identifier, ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- messaging
-- Email composed in the dashboard. One row per message, one row per recipient,
-- so a partial failure is visible per address rather than as a single count.

CREATE TABLE IF NOT EXISTS email_messages (
  id            CHAR(36)     NOT NULL,
  subject       VARCHAR(255) NOT NULL,
  preheader     VARCHAR(255)     NULL,
  body          LONGTEXT     NOT NULL,
  cta_label     VARCHAR(120)     NULL,
  cta_url       VARCHAR(512)     NULL,
  audience      VARCHAR(16)  NOT NULL DEFAULT 'single',
  audience_meta TEXT             NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'queued',
  total         INT          NOT NULL DEFAULT 0,
  sent_count    INT          NOT NULL DEFAULT 0,
  failed_count  INT          NOT NULL DEFAULT 0,
  created_by    VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL,
  finished_at   DATETIME         NULL,
  PRIMARY KEY (id),
  KEY idx_email_messages_created (created_at),
  KEY idx_email_messages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_recipients (
  id            CHAR(36)     NOT NULL,
  message_id    CHAR(36)     NOT NULL,
  submission_id CHAR(36)         NULL,
  email         VARCHAR(255) NOT NULL,
  name          VARCHAR(255)     NULL,
  reference     VARCHAR(32)      NULL,
  trial         VARCHAR(32)      NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
  attempts      INT          NOT NULL DEFAULT 0,
  claim         VARCHAR(36)      NULL,
  error         VARCHAR(255)     NULL,
  sent_at       DATETIME         NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_email_recipients_message (message_id, status),
  KEY idx_email_recipients_claim (claim),
  KEY idx_email_recipients_email (email),
  CONSTRAINT fk_email_recipients_message FOREIGN KEY (message_id)
    REFERENCES email_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Addresses that must never be written to again. Checked at queue time, so an
-- opt-out cannot be undone by someone re-running an old audience filter.
CREATE TABLE IF NOT EXISTS email_suppressions (
  email      VARCHAR(255) NOT NULL,
  reason     VARCHAR(32)  NOT NULL DEFAULT 'unsubscribed',
  note       VARCHAR(255)     NULL,
  created_by VARCHAR(255)     NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
