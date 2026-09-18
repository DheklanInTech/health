-- SQLite mirror of schema/mysql.sql, for local development and the test suite.
-- Column names and types are kept deliberately compatible so Repo.php runs
-- identical SQL against both engines.

CREATE TABLE IF NOT EXISTS submissions (
  id             TEXT    NOT NULL PRIMARY KEY,
  application_id TEXT    NOT NULL UNIQUE,
  trial          TEXT    NOT NULL,
  status         TEXT    NOT NULL DEFAULT 'new',
  email          TEXT    NOT NULL,
  phone          TEXT,
  age            INTEGER,
  sex            TEXT,
  answers        TEXT    NOT NULL,
  eligibility    TEXT    NOT NULL,
  verdict        TEXT    NOT NULL DEFAULT 'review',
  bmi            REAL,
  ip_hash        TEXT,
  user_agent     TEXT,
  created_at     TEXT    NOT NULL,
  updated_at     TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_submissions_trial_created ON submissions (trial, created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions (status);
CREATE INDEX IF NOT EXISTS idx_submissions_verdict ON submissions (verdict);
CREATE INDEX IF NOT EXISTS idx_submissions_email ON submissions (email);
CREATE INDEX IF NOT EXISTS idx_submissions_created ON submissions (created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_ip_recent ON submissions (ip_hash, created_at);

CREATE TABLE IF NOT EXISTS submission_notes (
  id            TEXT NOT NULL PRIMARY KEY,
  submission_id TEXT NOT NULL REFERENCES submissions (id) ON DELETE CASCADE,
  author_email  TEXT NOT NULL,
  body          TEXT NOT NULL,
  created_at    TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_notes_submission ON submission_notes (submission_id, created_at);

CREATE TABLE IF NOT EXISTS admin_users (
  id            TEXT    NOT NULL PRIMARY KEY,
  email         TEXT    NOT NULL UNIQUE,
  name          TEXT    NOT NULL,
  role          TEXT    NOT NULL DEFAULT 'admin',
  password_hash TEXT    NOT NULL,
  disabled      INTEGER NOT NULL DEFAULT 0,
  must_change   INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT    NOT NULL,
  last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS audit_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_email TEXT    NOT NULL,
  action      TEXT    NOT NULL,
  target_type TEXT    NOT NULL,
  target_id   TEXT,
  meta        TEXT,
  ip_hash     TEXT,
  created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_events (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_target ON audit_events (target_type, target_id);

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  identifier TEXT    NOT NULL,
  ip_hash    TEXT    NOT NULL,
  successful INTEGER NOT NULL DEFAULT 0,
  created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts (identifier, ip_hash, created_at);

-- ---------------------------------------------------------------- messaging

CREATE TABLE IF NOT EXISTS email_messages (
  id            TEXT    NOT NULL PRIMARY KEY,
  subject       TEXT    NOT NULL,
  preheader     TEXT,
  body          TEXT    NOT NULL,
  cta_label     TEXT,
  cta_url       TEXT,
  audience      TEXT    NOT NULL DEFAULT 'single',
  audience_meta TEXT,
  status        TEXT    NOT NULL DEFAULT 'queued',
  total         INTEGER NOT NULL DEFAULT 0,
  sent_count    INTEGER NOT NULL DEFAULT 0,
  failed_count  INTEGER NOT NULL DEFAULT 0,
  created_by    TEXT    NOT NULL,
  created_at    TEXT    NOT NULL,
  finished_at   TEXT
);

CREATE INDEX IF NOT EXISTS idx_email_messages_created ON email_messages (created_at);
CREATE INDEX IF NOT EXISTS idx_email_messages_status ON email_messages (status);

CREATE TABLE IF NOT EXISTS email_recipients (
  id            TEXT    NOT NULL PRIMARY KEY,
  message_id    TEXT    NOT NULL REFERENCES email_messages (id) ON DELETE CASCADE,
  submission_id TEXT,
  email         TEXT    NOT NULL,
  name          TEXT,
  reference     TEXT,
  trial         TEXT,
  status        TEXT    NOT NULL DEFAULT 'pending',
  attempts      INTEGER NOT NULL DEFAULT 0,
  claim         TEXT,
  error         TEXT,
  sent_at       TEXT,
  created_at    TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_email_recipients_message ON email_recipients (message_id, status);
CREATE INDEX IF NOT EXISTS idx_email_recipients_claim ON email_recipients (claim);
CREATE INDEX IF NOT EXISTS idx_email_recipients_email ON email_recipients (email);

CREATE TABLE IF NOT EXISTS email_suppressions (
  email      TEXT NOT NULL PRIMARY KEY,
  reason     TEXT NOT NULL DEFAULT 'unsubscribed',
  note       TEXT,
  created_by TEXT,
  created_at TEXT NOT NULL
);
