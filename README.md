# Trial Path — trial screening site

Static Next.js frontend, PHP + MySQL backend, admin dashboard for reviewing
screening applications. Built to run on ordinary cPanel shared hosting.

```
app/                    Next.js pages — one per trial
components/             UI components, including the screening form
lib/trials.ts           typed view over shared/trials.json
shared/trials.json      ← the screening questions. One source of truth.
backend/
  app/                  PHP application — deploys OUTSIDE the web root
    src/                classes (Auth, Repo, Db, Eligibility, …)
    schema/             mysql.sql + sqlite.sql
    bin/                migrate, create-admin, seed, prune, send-queue
  public/               deploys INTO public_html
    api/submit.php      the public form endpoint
    admin/              the dashboard
DEPLOYMENT.md           step-by-step for Namecheap/cPanel
```

## The one rule worth knowing

`shared/trials.json` defines every screening question. The React form renders
from it and the PHP validator checks against it. Add a question there and it
appears on the form, is validated server-side, shows on the dashboard detail
page and lands in the CSV export — with no second edit anywhere.

Adding a whole trial takes two steps: a block in `shared/trials.json`, and a
rule set in `backend/app/src/Eligibility.php` if it needs automated screening.

## Run it locally

Frontend:

```bash
npm install
npm run dev              # http://localhost:3000
```

Backend (no MySQL needed — it falls back to SQLite):

```bash
cd backend/app
cp config.example.php config.php
```

Set `'db.driver' => 'sqlite'`, `'session.secure' => false`, and any
`security.hash_key`. Then:

```bash
php bin/migrate.php
php bin/create-admin.php
php bin/seed.php --count=140          # realistic mock applications
cd ../public && php -S 127.0.0.1:8080
```

Dashboard at <http://127.0.0.1:8080/admin/login.php>.

The forms post to `/api/submit.php`, so during frontend development either run
`next dev` behind the same origin or point `SUBMIT_ENDPOINT` in
`components/ScreeningForm.tsx` at `http://127.0.0.1:8080/api/submit.php` and add
that origin to `security.allowed_origins`.

## Build for production

```bash
npm run build            # static site into ./out
```

Then follow [DEPLOYMENT.md](DEPLOYMENT.md).

## What the dashboard does

- **Overview** — volume, trend, the review queue, screening split, per-trial mix
- **Applications** — search, filter by trial/status/screening/date, sort,
  paginate, bulk status changes, CSV export
- **Detail** — every answer grouped by form step, screening flags with the
  answers that caused them marked, status workflow, coordinator notes, history
- **Messages** — email one applicant or a filtered audience, with a live
  preview of the responsive template at desktop and phone width, per-recipient
  delivery results, retry, and a do-not-email list
- **Activity log** — sign-ins, status changes, exports, deletions, email sent
- **Team** — owner/admin/viewer roles, one-time passwords, forced first change

## Automated screening

`backend/app/src/Eligibility.php` holds per-trial rules that compute BMI and
raise `exclusion` / `caution` / `info` flags, producing a verdict of *passes
screening*, *needs review* or *likely excluded*.

It is triage, not a decision. Nothing is auto-rejected, every application stays
in the queue, and only a person sets the status. Treat the rules as a draft
until the study team has checked them against the actual protocols.

## Security notes

- PDO prepared statements everywhere; no user value is ever concatenated into SQL
- `password_hash()` / `password_verify()`, forced change on first sign-in
- Sessions stored in the app's own directory, not the shared system temp dir,
  with idle timeout, absolute timeout and periodic ID rotation
- CSRF token on every state-changing form
- Login throttling and per-IP submission caps, both database-backed
- Honeypot and minimum fill time on the public endpoint
- Visitor IPs stored only as a keyed hash — never the address itself
- Screening answers and note contents are deliberately kept out of the audit log
  and out of notification email
- Content-Security-Policy with no inline script in the dashboard

## Known gaps

- The eligibility rules are a first pass and need clinical review.
- Email falls back to PHP `mail()` when no SMTP mailbox is configured. Configure
  `mail.smtp_*` before writing to real applicants (see DEPLOYMENT.md) — `mail()`
  has no useful failure reporting and poor deliverability on shared hosting.
- Delivery is recorded as "handed to the mail server", not as "reached the
  inbox". There is no bounce handling; bounced addresses are added to the
  do-not-email list by hand.
- No participant-facing "check my application status" page yet.
- No automated test suite; the backend was verified by hand end to end.
