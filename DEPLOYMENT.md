# BrightFuture production deployment

## 1. Environment

Copy `.env.example` to `.env` on the server and set:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `BASE_URL` to the deployed path or `/`
- MySQL host, port, database, username and password
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_SECURITY`
- `MAIL_USERNAME`, `MAIL_PASSWORD`
- `MAIL_FROM`, `MAIL_FROM_NAME`
- `SUBMISSION_STORAGE_PATH` to an absolute, writable directory outside the web document root.
- `TRUSTED_PROXIES` to a comma-separated list of exact proxy IPs only when TLS
  terminates at a reverse proxy; leave it blank for direct HTTPS.

Never commit `.env`, SMTP passwords or database passwords.

## 2. Database

Create a backup before deployment.

```powershell
php scripts/migrate.php status
php scripts/migrate.php dry-run
php scripts/migrate.php migrate
php scripts/smoke.php
```

Do not edit an applied migration. Create a forward-only migration file with
the next sequence number instead.

After deploying the private-storage code and before reopening submissions,
move existing attachments and update their database references:

```powershell
php scripts/migrate-submissions-storage.php
```

Back up the database and `uploads/submissions/` first. The command is
idempotent and removes legacy public copies only after their references point
to private storage. When running it from CLI, set `SUBMISSION_STORAGE_PATH`
in `.env`; a CLI process cannot reliably infer the web server's document root.

## 3. Retention cleanup

Review eligible row counts before enabling the scheduled cleanup:

```powershell
php scripts/cleanup-data.php --dry-run
```

Schedule `php scripts/cleanup-data.php --apply` to run daily under the
application's database account. The command deletes in batches and applies the
retention periods documented in `database/README.md`; direct and group messages
are retained indefinitely. Capture its output in the normal operations logs.

## 4. Preflight

```powershell
php scripts/check-production.php
php scripts/test.php
curl.exe -i https://your-domain/health.php
```

The health endpoint must return HTTP 200 and `{"status":"ok"}`.

## 5. Web server

- Serve only the project root to PHP.
- Redirect HTTP to HTTPS.
- Enable HTTPS at the reverse proxy/load balancer.
- If PHP receives HTTP from a TLS-terminating proxy, configure that proxy to
  overwrite `X-Forwarded-Proto` with the original scheme and set
  `TRUSTED_PROXIES` to the exact addresses PHP sees in `REMOTE_ADDR`. Do not
  trust this header from arbitrary clients. Comma-separated forwarded chains
  are intentionally ignored.
- PHP sets the session cookie `Secure` flag and emits HSTS only when the
  request is directly HTTPS or the forwarded scheme came from a configured
  proxy. The `.env.example` default trusts no proxies.
- Verify the public HTTPS response to `/login.php` includes `Secure`,
  `HttpOnly`, and `SameSite=Lax` on the session cookie, plus
  `Strict-Transport-Security`. Verify plain HTTP is redirected to HTTPS at the
  proxy before testing authentication.
- Configure upload and session directories as writable but not public.
- Keep `SUBMISSION_STORAGE_PATH` outside every served document root and writable
  only by the application account.
- Disable directory listing.
- Do not expose `.env`, `.git`, `database/`, `scripts/`, `test-results/` or
  `node_modules/`.
- Retain the `uploads/submissions/.htaccess` deny rule for Apache. For Nginx,
  add a deny rule for the deployed URI prefix (adjust it if `BASE_URL` is a
  subdirectory):

  ```nginx
  location ^~ /uploads/submissions/ {
      deny all;
      return 404;
  }
  ```

## 6. AI assistant

When enabling the assistant, set `OPENAI_API_KEY` and, if needed,
`OPENAI_MODEL` in the server environment or server-side `.env`. Never put
provider credentials in page markup, JavaScript, API responses, logs, or source
control.

The assistant sends the current question, up to twelve preceding chat messages,
and an authorized lesson excerpt (when applicable) to the OpenAI Responses API.
Requests set `store=false`; this does not guarantee zero provider-side
retention. Review the provider's current data-processing terms and account
controls before production use, and tell learners not to submit passwords,
sensitive personal information, or confidential data. BrightFuture keeps chat
history in the PHP login session and stores only account-level quota counters
in MySQL. The quota is forty requests per account per hour with a five-second
minimum interval; failed provider attempts also count.

## 7. Mail

Use a dedicated transactional SMTP account.

Supported modes:

- `MAIL_SECURITY=ssl` for port 465
- `MAIL_SECURITY=starttls` for port 587
- `MAIL_SECURITY=none` only for a trusted local relay

Before configuring a provider, run `php scripts/test-smtp.php`; it uses a
loopback SMTP fixture and sends no external email. In staging, use controlled
accounts and an inbox to verify real delivery through the configured provider.
Verify both reset-password and registration verification flows in staging
before enabling mail in production.
To verify failure handling, use an intentionally invalid SMTP configuration
only in staging, then confirm the application logs a non-sensitive failure and
the user-facing flow remains generic. Do not test provider failures in production.

## 8. CI

GitHub Actions runs:

- PHP syntax
- Database baseline import
- Forward migrations
- PHP smoke tests
- Local SMTP protocol integration tests
- Playwright Chromium tests

Configure environment-specific database and mail secrets in the deployment
platform, not in the workflow file.
