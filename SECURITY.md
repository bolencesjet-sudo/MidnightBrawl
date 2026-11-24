# Security configuration and deployment

Changes in this checkout harden the PHP/MySQL application; they are not a guarantee against every attack or a penetration-test certification. Do not publish PHP source as a static website.

## Update the existing installation

1. Back up MySQL with your normal protected database backup process. Test restoring backups on a separate database.
2. Use a supported PHP release in production (the application rejects production PHP below 8.2; prefer a currently supported release). This workstation currently has PHP 8.0.30, which is suitable only for these local compatibility checks, not production.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader` from the application directory. Commit `composer.lock`; keep `vendor/` out of Git.
4. Configure the PHP process environment using `.env.example` as a reference. This app does **not** automatically load `.env` files. On XAMPP, configure Apache/server environment variables and restart Apache; variables set in a terminal apply to PHP launched from that terminal, not an already running Apache service.
5. Run `php install.php`. It adds the security tables and missing user columns without removing tournament data. Existing accounts are grandfathered as verified so they remain usable; new player accounts must verify their email. Review old account email ownership separately if it has never been checked. The installer needs schema privileges; the running application does not.
6. Create a separate administrator as described below. Existing organizers can use **Admin sign in** with their email and existing password.
7. Run the checks below, test actual provider email delivery on staging, and verify the public host's access restrictions before releasing.

## SMTP and registration verification

Use your provider's SMTP credentials or a dedicated app password, not your regular mailbox password. Verify your sending address/domain with the provider and configure its SPF/DKIM records. Use a provider sandbox in previews.

| Preferred variable | Supported spelling from the request | Example/value |
| --- | --- | --- |
| `SMTP_HOST` | `form_smpt_host` (also `smpt_host`) | Provider SMTP hostname, such as `smtp.your-provider.example` |
| `SMTP_PORT` | `smpt_port` | `587` for STARTTLS; `465` for implicit TLS |
| `SMTP_USERNAME` | `smpt_username` | Provider SMTP username |
| `SMTP_PASSWORD` | `smpt_password` | Provider SMTP password/app password |
| `SMTP_FROM_EMAIL` | `smpt_from_email` | Provider-verified sender address |
| `SMTP_FROM_NAME` | `smpt_from_name` | `Midnight Brawl` |

Names are case-sensitive. Prefer the uppercase `SMTP_*` names; if both are set, uppercase wins. There is no `form_smpt_host` HTML form: it is a compatibility environment-variable name only. SMTP is server-side and never returned by the API. TLS certificate verification remains enabled. Port 465 uses implicit TLS; other provider ports use required STARTTLS. Debug output is disabled, including transport exception details.

### Your Gmail settings

For the supplied Google account, use:

```text
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=bolencesjet@gmail.com
SMTP_PASSWORD=<your Google App Password, entered privately>
SMTP_FROM_EMAIL=bolencesjet@gmail.com
SMTP_FROM_NAME=Midnight Brawl
```

Sign into this Google account, enable **2-Step Verification**, then open [Google App Passwords](https://myaccount.google.com/apppasswords) and create an app password for `Midnight Brawl`. Enter its value directly into the PHP host's secret/environment setting (and Vercel only if it actually executes your backend). Do not use your normal Google password. App passwords may be unavailable for some managed or protected accounts; [Google explains the requirements](https://support.google.com/accounts/answer/185833). Use the same Gmail address as the sender unless Google has approved a different sending alias.

Set `TF_APP_KEY` to a cryptographically random secret of at least 32 characters. Generate it locally, for example with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, then save it in your server's secret settings. Never paste it into chat or commit it. Changing it invalidates outstanding verification codes.

Signup creates an unverified player and sends a six-digit code. No player session or tournament mutation is allowed before verification. Codes expire after ten minutes, are stored as keyed hashes, and can be used once. Verification is limited to five attempts per account per ten-minute window; resend is limited to once per minute. A new code replaces the previous code. Failed email delivery rolls back signup/code changes. After reopening the site, an unverified player can sign in with their password and request a new code.

### Vercel environment-variable procedure

1. Open your Vercel dashboard and select the correct project.
2. Open **Settings → Environment Variables**.
3. Add each preferred `SMTP_*` variable above. Select **Production** for real credentials. Use separate sandbox credentials for Preview/Development. Mark passwords/keys sensitive where supported. Do not add a `NEXT_PUBLIC_`, `PUBLIC_`, or `VITE_` prefix.
4. Add `TF_APP_KEY`, `TF_ENV=production`, `TF_APP_URL=https://your-production-domain`, and the `TF_DB_*` settings in `.env.example` on the server that actually executes PHP. Database TLS encryption is required; CA settings are optional. With both CA settings unset, database certificates and hostnames are not verified. Supplying `TF_DB_SSL_CA` or `TF_DB_SSL_CA_PEM` enables verification.
5. Save the variables and create a new deployment/redeploy. Changed variables do not update existing deployments.
6. On staging, register a fresh player email, receive the code, verify it, and check sign-in and resend behavior. Verify that production and previews do not share the same database or SMTP credentials.

**Vercel deployment:** see [DEPLOYMENT.md](DEPLOYMENT.md). This repository now provides explicit Vercel PHP-function routing in `vercel.json`, a fixed entry point and durable MySQL sessions. The optional container configuration is retained in `deploy/Dockerfile`. The runtime uses the same PHP application and same-origin API. Configure the hosted Clever Cloud database and SMTP settings, apply the additive schema migration, and verify the live deployment. `.htaccess` remains for XAMPP/Apache; the container uses Caddy and its public front controller instead.

References: [Vercel runtimes](https://vercel.com/docs/functions/runtimes), [Vercel environment variables](https://vercel.com/docs/environment-variables), [PHPMailer SMTP documentation](https://github.com/PHPMailer/PHPMailer).

## Dedicated administrator creation

Admin accounts are created explicitly through the private CLI. Do not make your player account your routine admin account.

### Requested default administrator

Run `php admin.php --create-default` against the intended database to create the requested initial account:

- Username: `tfv1admin`
- Initial password: `TFv1-Admin-2026!`
- Internal email/reset identifier: `tfv1-admin@example.invalid` (reserved, non-deliverable address; no email is sent).

Use **Admin sign in**. The command inserts a separate, verified organizer with a hashed password and an audit entry. It refuses an existing username/email instead of changing any account. It does not run automatically on page visits, deployment or installation. Existing administrators, players, tournaments and login checks are preserved. These credentials work only after the command reports successful creation; they cannot bypass a database outage. For Vercel, run the CLI privately with that deployment's database and production settings.

To replace the initial password, run `.\setup-admin.ps1 -ResetPassword`, enter `tfv1-admin@example.invalid` as the email, and choose a unique new password. Subsequent attempts to create defaults will still refuse the existing account and preserve its new password.

### Custom administrator

For this workstation, run `.\setup-admin.ps1`. It defaults to username `bolencesjet` and email `bolencesjet@gmail.com`, prompts privately for your chosen password, and restores its temporary environment settings afterward. No admin password has been chosen or saved for you. If that email already belongs to a player, choose a different admin email; if it belongs to an existing organizer, use Admin sign in with that organizer email instead.

**Existing local account:** `bolencesjet@gmail.com` was found to be an organizer. Its username is now `bolencesjet`; its existing password is preserved. Use **Admin sign in** with either identifier. To choose a new password privately, run `.\setup-admin.ps1 -ResetPassword`. This changes only the password of the existing organizer and revokes its prior sessions. The script without `-ResetPassword` is for creating a separate new admin with an unused email.

If reset fails, read the specific PHP error printed above the PowerShell exception. Reset identifies the existing enabled organizer by email; it does not require a new username. Invalid email/password input is rejected before database access. `database_connection` means the configured database is unreachable; run `php deploy/diagnose-database.php` for a read-only check. For local XAMPP, start MySQL first. For the Vercel account, configure this CLI process to use the same hosted database; Vercel environment variables are not automatically available in local PowerShell, and `.env.example` is not loaded. Hosted CLI operations require the supported production PHP version and production environment/TLS settings. `Existing enabled admin not found` means the email is incorrect, the account is disabled/not an organizer, or the CLI is connected to another database. Do not recreate the database to fix a reset failure.

In PowerShell, on the machine connected to the intended database:

```powershell
$env:TF_ADMIN_USERNAME = 'your-admin-username'
$env:TF_ADMIN_EMAIL = 'your-admin-email@example.com'
$env:TF_ADMIN_NAME = 'Administrator'
$adminSecret = Read-Host 'New admin password (at least 15 characters)' -AsSecureString
$env:TF_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new('', $adminSecret).Password
try { php admin.php } finally { Remove-Item Env:TF_ADMIN_PASSWORD; $adminSecret.Dispose() }
```

The script creates an organizer account with a verified email under operator control; it does not reset or overwrite an existing account. The database and other production variables must already be set. Use **Admin sign in** with the username (case-insensitive) or email. Player sign-in cannot authenticate an organizer. The CLI operator is responsible for ensuring the admin email belongs to them. Public organizer setup is disabled in production; local first-organizer setup is allowed only over loopback in development.

Admin accounts can list accounts, grant/revoke roles, disable/re-enable users, and manage every event. Password hashes are never returned. They cannot register themselves as tournament players. Sensitive permission/status changes and deletion require the admin password again. Disabling an account revokes its existing sessions permanently, even after re-enabling it. Promoted organizers must use Admin sign in before obtaining organizer access. Administrators cannot disable themselves or change their own role.

In **Event settings**, admins can edit the name, game label and registration deadline. Team size, scoring and formation remain fixed to protect existing competition data. Event deletion requires the exact event name, a reason and the admin password; it removes the event and its dependent tournament data. A global deletion audit entry survives. Back up before deliberate deletion.

## Deployment controls

- Set `TF_ENV=production`, `TF_APP_URL` to the exact HTTPS origin, and all required database/key variables. Production fails closed when these are missing, when PHP is too old, or when root/blank database credentials are used. Keep database host/port values free of DSN parameters.
- Use a dedicated application database user limited to SELECT/INSERT/UPDATE/DELETE on this database. Use a different migration user for installation. Production requires encrypted database TLS. CA verification is optional; without it, encryption does not authenticate the database server. Restrict database network access to application hosts. Do not expose port 3306 publicly.
- Serve HTTPS only. Enable `TF_TRUST_PROXY=1` only when requests can reach PHP exclusively through a trusted reverse proxy that overwrites `X-Forwarded-Proto`. Do not trust arbitrary forwarding headers for IP rate limits; the app uses `REMOTE_ADDR`. Behind a proxy, configure the web server to supply the real, trusted client address or limits will apply to the proxy collectively.
- Production sessions are stored in the shared MySQL `web_sessions` table. Development can use PHP file sessions outside the document root, or `TF_SESSION_STORE=database` for production-like checks. Sessions expire after 30 minutes idle or eight hours absolute. Cookies are HttpOnly, SameSite=Strict, and Secure in production. Authentication regenerates session IDs and CSRF tokens.
- The API accepts same-origin JSON requests, enforces CSRF on mutations, imposes a 16 KiB body limit, and emits no permissive CORS headers. With `TF_APP_URL` set it rejects a different Origin. Keep this single-origin policy unless a reviewed integration requires otherwise.
- Enable Apache `mod_rewrite` and `AllowOverride` for this directory. Root rules deny dotfiles, Git metadata, dependency code, database scripts, tests, runtime files, source helpers, configuration and backups. Configure equivalent explicit denials on other web servers. Confirm externally that `/.git/config`, `/.env`, `/config.php`, `/admin.php`, `/composer.lock`, `/database/schema.sql`, `/tests/integration.mjs` and `/runtime/` return 403/404 without content. PHP's development server does not honor `.htaccess` and must never be public.
- HTML and API responses include CSP, frame protection, MIME protection, referrer policy and permissions restrictions; HTTPS responses include HSTS. CSP blocks inline scripts; legacy inline styles remain allowed to preserve existing layout. User-provided text is escaped on rendering, including event/account fields. SQL mutations continue to use prepared statements and authorization checks inside transactions.
- Request limits use MySQL, not local files. Default fixed windows: reads 240/minute/IP, writes 1,800/10 minutes/IP, authentication 60/15 minutes/IP and 10/15 minutes/account, signup 20/hour/IP, sensitive admin confirmations 10/15 minutes/admin. Fixed-window boundary bursts are possible. Also configure host/provider WAF/DDoS controls and monitoring; application limits cannot stop network saturation.
- Turn PHP `display_errors` off and `log_errors` on in production php.ini as well. Application code suppresses displayed errors and returns generic service failures. Keep logs private and review failed login/rate-limit traffic, database failures and SMTP failures at the host.
- Public tournament roster/game-ID/result visibility is preserved. Only admins see the account directory/emails. Join requests are limited to their player, team captain or admin. Review your event's privacy requirements before release.
- Keep tested backups, dependency updates, infrastructure patching, monitoring and incident response in place. MFA/passkeys, a full password-reset workflow and an independent external penetration test are not implemented here.

## Verification and remaining deployment gates

Run from this checkout with MySQL running:

```powershell
composer validate --strict
composer audit --locked --no-dev
php tests/algorithms.php
node tests/integration.mjs
node tests/database-tls.mjs
node tests/secrets.mjs
```

Integration checks create a unique `tfv1_test_*` database and a loopback-only SMTP server with a locally generated, trusted test certificate. They exercise actual PHPMailer STARTTLS delivery without disabling certificate validation. Test accounts/messages are fictional. The suite cleans up its own database and certificate directory. Its database identity needs CREATE/DROP for test databases; production app credentials should not have those permissions.

Database TLS checks use a loopback MySQL protocol fixture with a self-signed certificate. They verify encrypted login without a CA, rejection of servers without TLS before credentials are sent, enforcement of an active session cipher, and hostname verification when an optional CA is configured. They do not modify the application database.

The secrets check scans high-confidence token/private-key/credential-URL patterns in the working files and all locally reachable Git blobs, without printing secrets. It cannot detect every password or inspect unfetched remote history. The GitHub token previously pasted into this conversation must be revoked independently; a clean repository scan does not revoke it. No history has been rewritten or force-pushed.

Validation performed locally: 289 integration assertions passed, 14 headless-browser assertions passed, and 62 round-robin algorithm combinations passed. PHP/JavaScript syntax checks, Composer strict validation and the dependency advisory audit passed. The local-ref scan inspected 22 historical blobs with no high-confidence findings. Apache returned 403 for tested configuration, Git, dependency, script, database, test and backup paths. The existing database was backed up under the ignored, web-denied `runtime/` directory before the additive migration; no tournament records were removed. The final CLI transaction check confirmed successful admin creation/reset and rollback on duplicate creation. Actual Gmail delivery and production hosting remain unverified.

Release gates still requiring operator configuration: supported production PHP, actual SMTP credentials and verified sender, application key, HTTPS origin/trusted proxy, restricted TLS database user, optional database CA verification, protected backup/restore, real-provider email delivery, public file-denial checks, and validation of the chosen hosting runtime. No production credentials, hosting account or final admin password were supplied, so those cannot be set on your behalf in this checkout.

## Checklist outcome

| Requested area | Result in this checkout |
| --- | --- |
| Environment, database, debug settings | Production validation, non-root database requirement, encrypted database TLS with optional CA verification, generic errors; actual production secrets/runtime still required |
| Admin routes, authentication, roles, account separation | Server-side authorization; separate admin sign-in and CLI setup; account directory, role/status controls; sensitive actions require password confirmation |
| Form validation, XSS, API protection | Typed/length-bound fields, prepared SQL, escaped HTML, CSP, CSRF, JSON-only writes and request-size limits |
| Rate limiting, CORS and security headers | Durable MySQL counters, authentication/account limits, resend cooldown, same-origin policy and response headers |
| Password hashing and registration strength | PHP password hashing/rehashing, minimum 15 characters for new accounts, bcrypt-safe 72-byte ceiling, predictable-password checks and UI guidance |
| Dependencies and unused packages | PHPMailer is the only Composer dependency and is used; locked version audited; no pre-existing package set to remove |
| Public files and Git secrets | Apache sensitive-path denials checked locally; high-confidence scan of all local refs and working files; exposed chat token still needs revocation |
| Audit and regression testing | PHP/JS checks, algorithm suite, isolated MySQL/SMTP integration suite, browser checks and local HTTP denial checks; no external penetration test performed |
| SMTP verification and Vercel procedure | PHPMailer with required TLS and single-use expiring codes; Gmail/account-specific settings and Vercel steps above; real App Password and deployment still required |
| Admin username/password | Existing organizer `bolencesjet@gmail.com` assigned username `bolencesjet`; password preserved, with secure interactive creation/reset scripts available |
| Admin event editing/deletion | Name/game/deadline edits; explicit permanent deletion with event-name/password confirmation and surviving deletion audit entry |
