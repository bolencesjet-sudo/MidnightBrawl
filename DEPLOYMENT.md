# Fix the downloading Vercel deployment

The existing public deployment returns `application/x-httpd-php` and raw PHP for `/`, `/api.php` and `/config.php`. The PHP code is being served as static files. Changing the filename or forcing an HTML content type would not execute authentication or database code.

This checkout now includes explicit `vercel.json` routing and a PHP function at `api/entry.php`. The initial container auto-detection did not change the live site, so the default deployment now uses the documented [vercel-php runtime](https://github.com/vercel-community/php) pinned to `0.9.0`. This is a community-maintained runtime. The optional container definition is retained at `deploy/Dockerfile` for a separately configured container host. The application remains PHP/MySQL. No tournament algorithms, event rules or stored account passwords are changed by the deployment fix.

## What the deployment runs

- A PHP function using `vercel-php@0.9.0` and Node 22. The build checks PHP 8.2+ and the required `pdo_mysql`, `mbstring`, OpenSSL and session extensions, then includes the locked Composer dependencies.
- `vercel.json` routes the four existing assets as static files and rewrites the page/API/health routes to `api/entry.php`. That entry dispatches only to the existing page, API or health handler. A final 404 rule blocks all other public paths, including source files.
- `/` and `/index.php` execute the existing page. `/api.php` executes the existing API. `/health` reports that PHP is running. All other paths return a JSON 404. There are no public installer/admin CLI/database/source-file routes.
- Production sessions live in MySQL's `web_sessions` table and use database locks, so login and CSRF state survive container restarts and work across instances. Session cookie identifiers are hashed before storage.
- Deployment ignore files omit Git, runtime backups, `.env` files, SQL exports, tests and local credentials. PHP libraries are bundled for execution and cannot be downloaded through the route table.
- The function trusts the HTTPS forwarding header supplied by Vercel's proxy. `api/php.ini` disables debug output and file uploads.

The Vercel project Node.js setting and `package.json` both use **22.x**, matching this PHP runtime. Node starts the PHP launcher; changing that setting does not migrate the database or fix a missing database certificate.

Sign in, Register and Admin sign-in are always available in the upper-right account controls, including while event data is unavailable. The forms still require a working backend to authenticate or create accounts. A form opened during an outage obtains a fresh session/CSRF token when service returns before submitting credentials.

## Configure the existing Vercel project

1. Open **Vercel → midnightbrawl → Settings → Build and Deployment**. The repository root directory must be the directory containing `vercel.json` and `composer.json`. The checked-in configuration selects the **Other** framework, clears build/install commands, sets the output root, and declares the executable PHP function explicitly. No Container preset is needed for this configuration.
2. Under **Settings → Environment Variables**, add the values below for **Production**. Use separate database/email credentials for Preview. Enter secrets privately in Vercel; do not paste them into chat or commit them.
3. In Clever Cloud's MySQL add-on console, open its connection/environment settings and copy the database name, username, password, port and TLS details. The supplied host alone is not sufficient to connect. Use the connection port actually shown by Clever Cloud.
4. Apply the additive database migration to that existing hosted database using a PHP environment with access to it. Use a migration identity that can create/alter tables; `php migrate.php` works with an existing database and does not require CREATE DATABASE permission. It preserves existing records and does not add demo events. Back up the hosted database first. Do not import an empty local database over an existing hosted database.
5. Deploy the commit containing this fix to the existing project. Vercel must build the `api/entry.php` PHP function; its build log should show Composer installation and the PHP runtime check. Environment changes require a new deployment. The live page must not be served directly from the root PHP file.
6. Follow the release checks below before announcing that registration is ready.

| Variable | Production value |
| --- | --- |
| `TF_ENV` | `production` (also set in `vercel.json`) |
| `TF_APP_URL` | `https://midnightbrawl.vercel.app` |
| `TF_APP_KEY` | Random secret of at least 32 characters; keep stable across instances/deployments |
| `TF_DB_HOST` | `bgk6s8myavpb5l1l1n38-mysql.services.clever-cloud.com` |
| `TF_DB_PORT` | Port shown by Clever Cloud, normally `3306` |
| `TF_DB_NAME` | Your Clever Cloud database name |
| `TF_DB_USER` | Your restricted Clever Cloud database username, not `root` |
| `TF_DB_PASSWORD` | Its password |
| `TF_DB_SSL_CA` | Optional. Provider CA file path to enable database certificate and hostname verification; leave unset for TLS without CA verification |
| `TF_DB_SSL_CA_PEM` | Optional alternative to the file path. Provider CA certificate PEM text; leave unset for TLS without CA verification |
| `TF_SESSION_STORE` | `database` (also set in `vercel.json`; mandatory in production) |
| `TF_TRUST_PROXY` | `1` (also set in `vercel.json` for Vercel?s HTTPS proxy) |
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_USERNAME` | `bolencesjet@gmail.com` |
| `SMTP_PASSWORD` | Google App Password for this account, not your Google login password |
| `SMTP_FROM_EMAIL` | `bolencesjet@gmail.com` |
| `SMTP_FROM_NAME` | `Midnight Brawl` |

`MYSQL_ADDON_HOST`, `MYSQL_ADDON_PORT`, `MYSQL_ADDON_DB`, `MYSQL_ADDON_USER` and `MYSQL_ADDON_PASSWORD` are also accepted for Clever Cloud connection values. Explicit `TF_DB_*` names take precedence. Production requires TLS encryption. With both CA settings unset, self-signed and hostname-mismatched database certificates are accepted. This encrypts traffic but does not authenticate the database server. Supplying a CA enables certificate and hostname verification. Invalid configured CAs fail rather than retrying without verification.

For the reported `Configure verified TLS for the production database` error, deploy this revision with `TF_DB_SSL_CA` and `TF_DB_SSL_CA_PEM` unset. The custom CA requirement has been removed. No database migration, account reset or data import is required for this change.

The existing Vercel project's complete legacy set `HOST`, `DB_NAME`, `USER`, and `PASSWORD` is also supported. All four must be present before any are used, so an operating-system `USER` setting cannot accidentally become the database login. Existing sensitive Vercel values can stay in place; their values do not need to be printed or copied into source code.

Generate the application key privately with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Enable Google 2-Step Verification and create a [Google App Password](https://support.google.com/accounts/answer/185833) for SMTP. Never use public/browser environment-variable prefixes for secrets.

Migration and administrator CLI tools run from the source checkout with the selected database variables set in the PHP process. The deployment excludes schema/test files, and CLI tools are never exposed as HTTP setup endpoints. They use the same production TLS behavior. If opting into CA verification, use a real local CA path or the PEM environment variable; a container-only path will not exist on Windows.

## Release checks

1. `https://midnightbrawl.vercel.app/` returns HTTP 200 with `Content-Type: text/html` and renders the application. It must contain no `<?php` source or download prompt.
2. `/health` returns HTTP 200 and `{"status":"ok"}`. This checks the runtime only.
3. `/api.php` returns JSON containing the event/account state, never PHP source. A 503 indicates missing environment settings, database reachability/TLS or migration issues; inspect private runtime logs.
4. `/config.php`, `/lib.php`, `/sessions.php`, `/admin.php`, `/install.php`, `/migrate.php`, `/composer.lock`, `/.git/config`, `/.env`, `/database/schema.sql`, `/TFv1.session.sql`, `/tests/integration.mjs` and `/runtime/backup.sql` return 404.
5. Existing admin sign-in, event display, account permissions and event edits still work. The admin record must exist in the hosted database; the local XAMPP admin does not automatically appear in Clever Cloud. Use the existing hosted organizer or create a separate hosted admin through the private CLI.
6. Register a real test email, receive its verification code, verify it, sign in and sign out. Check resend cooldown and a wrong code. Gmail delivery cannot be proven using a local SMTP fixture.
7. Use at least two fresh browser sessions and confirm a login survives a new container instance. Confirm the test has not modified any real tournament scores or rosters.
8. Disable/delete the earlier static deployment or restrict its access in Vercel, including its unique deployment URL. Replacing the production alias alone does not remove old source-exposing deployment URLs. Review any actual secrets that were committed or included in those deployments and rotate exposed credentials if present.

## Local verification

### Registration reports service temporarily unavailable

This JSON response comes from the API's database exception handler. A browser refresh cannot repair database credentials, provider suspension or missing schema. If even a fresh `GET /api.php` returns 503, registration is blocked before account creation. Check **Vercel → midnightbrawl → Logs** for the corresponding `TFv1 database` entry. Updated responses include a reference that matches the private log, plus a database error category; connection attempts retry once only for connection/capacity failures. Registration writes and email delivery are never automatically replayed.

Run `php deploy/diagnose-database.php` privately with the same environment variables as the deployed PHP function. It reads schema metadata without creating accounts, changing tables or printing secrets. Running it under default local XAMPP settings checks the local database, not Clever Cloud.

- `database_connection`: restore/start the provider database and verify host, port and network access.
- `database_busy`: check provider connection limits and active connections.
- `database_permissions`: correct the hosted credentials or required database-user privileges.
- `database_name`: use the exact database name issued by the provider.
- `database_tls`: correct the provider TLS/CA configuration.
- `database_schema`: run `php migrate.php` privately against the existing hosted database using a migration-capable user. This additive migration preserves tournament data. Do not reinstall/reset the database as a registration workaround.

After correcting the cause and redeploying updated code, run the live registration validation check below, then verify real email delivery with an inbox you control. A successful validation check alone does not prove registration/email delivery works.

### Registration shows "Failed to fetch"

This browser error means no usable HTTP response arrived. Check the exact URL and browser Network/Console details; the message alone does not distinguish an interrupted connection, blocked request, TLS problem, redirect, or hosting outage. The client retries a failed session read once, limits waiting time, preserves form entries on failure, and never automatically repeats a registration POST because it might already have been processed.

1. Open the official HTTPS website directly. Confirm `TF_APP_URL` matches its origin in the PHP hosting environment. Avoid submitting from an old deployment URL or a saved local HTML file.
2. Run `node tests/live.mjs https://midnightbrawl.vercel.app/` to check HTML, assets, runtime and API availability. Substitute the actual official URL when different.
3. Run `node tests/live-registration.mjs https://midnightbrawl.vercel.app/` to check the registration POST with a session cookie, Origin and CSRF token. This submits blank, invalid registration input; it creates no account and sends no email. It consumes normal request/rate-limit counters. A 422 email-validation response is expected. A 403 points to session/origin configuration; a 503 requires checking the private server logs/configuration.
4. If both checks pass but a browser fails, inspect the failed `api.php` request in that browser's Network tab for a blocked connection, redirect or certificate error. Compare with a private window or another connection to isolate browser/network interference. Keep cookies enabled for the site.
5. If a submission loses its response, try signing in with the same email before creating another account. An existing unverified account can continue email verification through sign-in. Real SMTP delivery still requires a successful registration with an inbox you control.

Deploy the updated `assets/app.js` with the application and refresh the browser before retesting. Never use permissive CORS, `no-cors`, or disabled email verification as a network-error workaround.

### Test commands

```powershell
php tests/algorithms.php
node tests/integration.mjs --vercel
node tests/deployment.mjs --vercel
php tests/configuration.php
node tests/live.mjs https://midnightbrawl.vercel.app/
```

The integration suite uses shared MySQL sessions. Deployment tests use a PHP adapter that evaluates the checked-in `vercel.json` route table and exercise two independent servers, server restart, logout and strict session IDs. They create and remove only their own isolated `tfv1_test_*` database.

The Vercel-hosted runtime build and live URL still need to pass the release checks; a successful local route test alone does not prove the remote domain was updated. This guide does not claim the remote deployment has succeeded until the live URL checks pass.

The live smoke check is read-only and requires the data API to succeed, as well as HTML, runtime health and assets. A green hosting build alone is not sufficient to consider the portal ready.
