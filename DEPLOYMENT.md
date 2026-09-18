# Deployment — Namecheap shared hosting (cPanel)

The site is two halves that deploy independently:

| Half | What it is | Where it goes |
|---|---|---|
| Frontend | Next.js, exported to static HTML/CSS/JS | `public_html/` |
| Backend | PHP 8 + MySQL — the API and the admin dashboard | `public_html/api`, `public_html/admin`, and a **private** `olisa-app/` |

No Node runtime is needed on the server. Apache serves the static files and
runs the PHP directly, which is what shared hosting is good at.

---

## 1. Target layout on the server

```
/home/<cpanel-user>/
├── olisa-app/                  ← PRIVATE. Not reachable over HTTP.
│   ├── bootstrap.php
│   ├── config.php              ← credentials (chmod 600, never in git)
│   ├── src/                    ← application classes
│   ├── schema/                 ← mysql.sql, sqlite.sql
│   ├── bin/                    ← migrate, create-admin, seed, prune, send-queue
│   ├── shared/trials.json      ← copy of the repo's shared/trials.json
│   └── storage/                ← sessions + logs (chmod 700)
│
└── public_html/                ← the web root
    ├── index.html, _next/, …   ← the exported Next.js site
    ├── .htaccess
    ├── _init.php               ← finds olisa-app (denied to browsers)
    ├── api/submit.php          ← the public form endpoint
    └── admin/                  ← the dashboard
```

`olisa-app` sits **beside** `public_html`, not inside it. That is the single
most important detail here: the database password and the screening data it
unlocks must not be fetchable over HTTP if PHP ever stops executing.

---

## 2. Build the frontend

Locally:

```bash
npm install
npm run build          # writes ./out
```

`out/` is the whole static site. Upload its **contents** (not the folder) into
`public_html/`.

---

## 3. Upload

Easiest first time: cPanel → File Manager → Upload a zip → Extract.

```bash
# from the repo root, build the two upload bundles
npm run build
zip -r site.zip out/.              # extract into public_html/
zip -r app.zip backend/app shared  # extract into /home/<user>/, then rename
zip -r web.zip backend/public/.    # extract into public_html/ (merges api/ + admin/)
```

After extracting `app.zip`, rename `backend/app` to `olisa-app` and move
`shared/` inside it:

```bash
mv backend/app olisa-app
mv shared olisa-app/shared
rmdir backend
```

If SSH is available (Namecheap Stellar Plus and up), rsync is less error-prone:

```bash
rsync -av --delete out/                 user@host:~/public_html/
rsync -av backend/public/               user@host:~/public_html/
rsync -av --exclude config.php --exclude storage backend/app/  user@host:~/olisa-app/
rsync -av shared/                       user@host:~/olisa-app/shared/
```

---

## 4. Create the database

cPanel → **MySQL Databases**:

1. Create a database, e.g. `olisa`. cPanel prefixes it: `cpuser_olisa`.
2. Create a user, e.g. `olisa`. Also prefixed: `cpuser_olisa`.
3. Add the user to the database with **ALL PRIVILEGES**.

Note all three values — database, user, password.

---

## 5. Configure

```bash
cd ~/olisa-app
cp config.example.php config.php
chmod 600 config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # value for security.hash_key
```

Edit `config.php`:

```php
'app.url'           => 'https://yourdomain.org',
'db.name'           => 'cpuser_olisa',
'db.user'           => 'cpuser_olisa',
'db.password'       => '…',
'security.hash_key' => '…the 64 hex characters you just generated…',
'session.secure'    => true,   // requires HTTPS to be working
```

Then permissions:

```bash
chmod 700 ~/olisa-app/storage
chmod 755 ~/olisa-app
```

---

## 6. Create the tables and the first account

cPanel → **Terminal** (or SSH):

```bash
cd ~/olisa-app
php bin/migrate.php
php bin/create-admin.php
```

`create-admin.php` prints a one-time password and forces a change at first
sign-in. Sign in at `https://yourdomain.org/admin/login.php`.

**No terminal on your plan?** Paste `schema/mysql.sql` into phpMyAdmin → SQL,
then run `bin/create-admin.php` once via cPanel → Cron Jobs (set it to run in a
minute, capture the output by mail, then delete the cron entry).

---

## 7. HTTPS

cPanel → **SSL/TLS Status** → run AutoSSL. Once the padlock works:

- uncomment the HTTPS redirect block in `public_html/.htaccess`
- uncomment the `Strict-Transport-Security` header in the same file
- confirm `'session.secure' => true` in `config.php`

Do this before real applications arrive. The forms carry health information,
and a session cookie sent over plain HTTP is a session anyone on the network
can take.

---

## 8. Verify

```bash
curl -I https://yourdomain.org/                       # 200, static HTML
curl -I https://yourdomain.org/admin/login.php        # 200
curl -I https://yourdomain.org/_init.php              # 403/404 — must NOT be 200
curl -I https://yourdomain.org/../olisa-app/config.php # must not resolve
```

Then by hand:

1. Complete a real screening form on the live site.
2. Confirm it appears in the dashboard within seconds.
3. Export a CSV and open it.
4. Check the activity log recorded the export.

---

## 9. Backups

cPanel's own backups cover the account, but keep a separate copy of the
database — it is the part you cannot rebuild from the repo.

cPanel → **Cron Jobs**, daily:

```
0 2 * * * /usr/bin/mysqldump -u cpuser_olisa -p'PASSWORD' cpuser_olisa | gzip > ~/backups/olisa-$(date +\%F).sql.gz
```

Weekly, trim old backups and old audit rows:

```
0 3 * * 0 find ~/backups -name 'olisa-*.sql.gz' -mtime +30 -delete
0 4 * * 0 /usr/local/bin/php ~/olisa-app/bin/prune.php
```

Backups contain health information. Keep them inside the account, never in
`public_html`, and delete local copies once restored.

---

## 10. Email

Two different things send mail, and they have different requirements.

### Notifications to the coordinator

Set `'mail.notify' => true` and `'mail.to'` in `config.php`. The message carries
only the reference and a dashboard link — never the answers.

### Messages to applicants (Admin → Messages)

The dashboard can write to one applicant or to a filtered audience. Before using
it on real people, configure SMTP.

PHP's `mail()` hands the message to the server's local MTA. Its reputation is
shared with every other account on the box, its failures are invisible — it
returns success as soon as the message is *accepted*, which says nothing about
whether it arrived — and on shared hosting it usually lands in spam. That is
tolerable for the occasional notification to your own inbox. It is not
tolerable for a trial invitation.

Create a mailbox in cPanel → **Email Accounts**, then fill in `config.php`:

```php
'mail.from'            => 'no-reply@yourdomain.org',
'mail.from_name'       => 'Trial Path',
'mail.reply_to'        => 'coordinator@yourdomain.org',  // a mailbox someone reads

'mail.smtp_host'       => 'mail.yourdomain.org',
'mail.smtp_port'       => 587,          // 465 if you set encryption to 'ssl'
'mail.smtp_user'       => 'no-reply@yourdomain.org',   // the full address
'mail.smtp_password'   => '...',
'mail.smtp_encryption' => 'tls',        // never '' in production
```

No Composer package is needed — `src/Smtp.php` speaks ESMTP, STARTTLS and
AUTH LOGIN/PLAIN over a socket.

Then publish SPF, DKIM and DMARC records for the domain (cPanel → **Email
Deliverability** generates them). Without these, a message claiming to be from
your domain but sent from a shared host is exactly what a forgery looks like,
and it will be filed accordingly.

### Draining the queue

Nothing is sent inside the request that composes it — a campaign of a few
hundred takes minutes and a PHP request is killed at thirty seconds. The
compose page sends what fits in about 15 seconds and queues the rest, which you
can finish with **Send the rest** on the message page.

For anything larger, let cron do it (cPanel → **Cron Jobs**, every minute):

```
* * * * * /usr/local/bin/php /home/<user>/olisa-app/bin/send-queue.php --quiet
```

Overlapping runs are safe: recipients are claimed with a token, so no address is
ever written to twice.

### Before the first campaign

- Send to yourself first. The composer's audience picker takes a single address.
- Check the preview at both widths. The template reflows below 620px.
- Add anyone who asks to stop to the **do-not-email list**. It is applied when an
  audience is resolved, so an opt-out cannot be undone by re-running an old
  filter.
- Messages carry no health information and none of the screening answers. Keep
  it that way — email is not a safe place for them, and the footer tells
  recipients to ask for a call rather than reply with details.

---

## Routine operations

```bash
cd ~/olisa-app

php bin/create-admin.php --email=x@y.org --name="Name" --role=admin
php bin/migrate.php                 # after any schema change; safe to re-run
php bin/prune.php                   # trim audit events older than a year
php bin/send-queue.php              # drain queued email now, rather than waiting for cron
tail -50 storage/logs/php-error.log
```

Roles: **owner** manages the team and can delete; **admin** reviews, notes,
exports and sends email; **viewer** reads only — no export, because an export is
a copy of health answers leaving the system, and no messaging, because writing
to applicants is not a read.

---

## Updating the site later

Frontend only:

```bash
npm run build
rsync -av --delete out/ user@host:~/public_html/   # careful: see below
```

`--delete` would remove `api/`, `admin/`, `.htaccess` and `_init.php`. Either
drop `--delete`, or re-upload `backend/public/` straight after. Safer:

```bash
rsync -av out/ user@host:~/public_html/
rsync -av backend/public/ user@host:~/public_html/
```

Backend only: re-upload `backend/app/` to `~/olisa-app/` **excluding**
`config.php` and `storage/`, then run `php bin/migrate.php`.

Adding or changing a screening question: edit `shared/trials.json`, rebuild the
frontend, and copy the file to `~/olisa-app/shared/trials.json`. Both halves
read it, so they cannot disagree about what was asked.

---

## Running it locally with XAMPP

Worth doing before every deploy: the same PHP, the same Apache rules, the same
`.htaccess`, on your machine. Set up once, then it is two commands.

The whole site has to be served from **one origin** — the form posts to a
root-relative `/api/submit.php`, so the exported frontend and the PHP backend
must sit in the same docroot, exactly as they do in `public_html`.

The docroot is **not** the repo. This repo lives under `~/Desktop`, which macOS
protects with TCC; Apache is not in that allow-list and gets `EPERM` on every
file there whatever the permission bits say (the symptom is a bare 403 and
`AH00529 ... pcfg_openfile` in the error log). A plain home subdirectory is
outside TCC, so `scripts/local.sh` syncs the repo into `~/olisa-local`, laid out
the same way as the server:

```
~/olisa-local/
├── olisa-app/       <- private: src, config.php, schema, storage
└── public_html/     <- docroot: the exported site + api/ + admin/
```

It is a copy, so **re-run the script after editing any PHP file.**

### One-time setup

Already done in this repo:

- `backend/app/config.php` — local credentials, `session.secure => false`,
  rate limits raised so repeated testing does not trip the limiter.
- `scripts/local.sh` — syncs the repo into `~/olisa-local`, merging `out/` and
  `backend/public/` into the docroot and stripping the `.htaccess` HTTPS redirect (there is no certificate on
  localhost, so it would otherwise be a redirect loop).
- `/Applications/XAMPP/xamppfiles/etc/extra/httpd-olisa.conf` — a vhost on
  **port 8080**, pointed at `~/olisa-local/public_html`, with
  `SetEnv OLISA_APP_PATH ~/olisa-local/olisa-app` so `_init.php` finds the
  private application directory. Port 8080 keeps port 80 and the other projects
  in `htdocs` untouched.
- `httpd.conf` — `User`/`Group` changed from `daemon` to `dreamers`/`staff`, so
  Apache can read the docroot and write `storage/`. This applies to every XAMPP
  project on this machine, not just this one. The original is backed up beside
  it as `httpd.conf.bak-olisa`.

### Each time

```bash
sudo /Applications/XAMPP/xamppfiles/xampp start     # or the XAMPP Manager app
cd ~/Desktop/olisa-web
./scripts/local.sh --build                          # omit --build for backend-only changes
```

First run only, create the database and the tables:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -h 127.0.0.1 -P 3308 -e "CREATE DATABASE IF NOT EXISTS olisa CHARACTER SET utf8mb4"
/Applications/XAMPP/xamppfiles/bin/php backend/app/bin/migrate.php
/Applications/XAMPP/xamppfiles/bin/php backend/app/bin/create-admin.php
/Applications/XAMPP/xamppfiles/bin/php backend/app/bin/seed.php     # optional mock data
```

This XAMPP build runs MariaDB on **port 3308**, not the usual 3306 — `etc/my.cnf`
sets it. `config.php` matches. Check with `SELECT @@port` if a connection is
refused.

After changing anything under `xamppfiles/etc`, Apache must be restarted before
it sees it: `sudo /Applications/XAMPP/xamppfiles/bin/apachectl -k restart`.

Use XAMPP's PHP, not the system one — it is the binary Apache runs, with the
same extensions.

Then:

| URL | What it is |
|---|---|
| http://localhost:8080/ | the site |
| http://localhost:8080/admin/login.php | the dashboard |
| http://localhost:8080/_init.php | must return **403** |

Submit a screening form, confirm the row appears in the dashboard, export a
CSV. That is the same verification pass as section 8, minus HTTPS.

### No MySQL, or just testing the PHP

SQLite needs no daemon at all, and the schema is written for both engines:

```bash
DB_DRIVER=sqlite /Applications/XAMPP/xamppfiles/bin/php backend/app/bin/migrate.php
```

Environment variables override `config.php`, so this leaves the file alone.
The database lands in `backend/app/storage/olisa.sqlite`.

### Before deploying

`backend/app/config.php` is local-only and git-ignored — the server gets its
own copy, with `session.secure => true`, real MySQL credentials and production
rate limits. `.local-www/` is scratch; never upload it. To turn the local host
off, delete the `Include etc/extra/httpd-olisa.conf` line from
`/Applications/XAMPP/xamppfiles/etc/httpd.conf`.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| "Application is not configured" | `_init.php` cannot find `olisa-app`. Check it is beside `public_html`, or set `SetEnv OLISA_APP_PATH /home/user/olisa-app` in `.htaccess`. |
| "Database unavailable" | Credentials in `config.php`, or the user was never granted privileges. Real reason is in `storage/logs/php-error.log`. |
| Login says session expired, repeatedly | `storage/sessions` is not writable (needs 700 and correct owner), or `session.secure => true` without working HTTPS. |
| Form returns 403 | Cross-origin POST. Check the form is served from the same domain as the API. |
| Form returns 429 | Rate limit — 5/hour per IP by default. Raise it in `config.php` if a clinic shares one connection. |
| Admin pages 500 | Check `storage/logs/php-error.log` first; it will name the file and line. |
| Email says "Could not connect" | `mail.smtp_host`/`port` wrong, or the host blocks outbound SMTP. Try port 465 with `'mail.smtp_encryption' => 'ssl'`. |
| Email says "does not offer STARTTLS" | The mailbox wants implicit TLS. Set port 465 and `'mail.smtp_encryption' => 'ssl'`. |
| Email says "authentication failed" | `mail.smtp_user` must be the **full** address, not the part before the @. |
| Messages sit at "queued" | Nothing is draining them. Press **Send the rest**, or add the `send-queue.php` cron job. |
| Messages send but land in spam | SPF, DKIM and DMARC are missing. cPanel → Email Deliverability. |

---

## What I need to deploy this for you

When the domain and hosting are bought, the fastest path is SSH:

- **cPanel URL** plus the account username
- **SSH access** (host, port, username, and a key or password) — Namecheap
  enables SSH on Stellar Plus and above under cPanel → SSH Access
- the **domain name**, and whether the site goes on the root domain or a subdomain

With those I can upload everything, create the database, run the migration and
first-admin script, turn on HTTPS and run the verification pass end to end.

Without SSH it still works, just with you doing the clicking: create the
database in cPanel, upload two zips through File Manager, and I will give you
the exact values to paste into `config.php`.

**Send credentials over something other than plain email**, and change the
cPanel password once the work is done.
