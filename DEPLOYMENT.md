# Fix the downloading Vercel deployment

The existing public deployment returns `application/x-httpd-php` and raw PHP for `/`, `/api.php` and `/config.php`. The PHP code is being served as static files. Changing the filename or forcing an HTML content type would not execute authentication or database code.

This checkout now includes explicit `vercel.json` routing and a PHP function at `api/entry.php`. The initial container auto-detection did not change the live site, so the default deployment now uses the documented [vercel-php runtime](https://github.com/vercel-community/php) pinned to `0.9.0`. This is a community-maintained runtime. The optional container definition is retained at `deploy/Dockerfile` for a separately configured container host. The application remains PHP/MySQL. No tournament algorithms, event rules or stored account passwords are changed by the deployment fix.

## What the deployment runs

- A PHP function using `vercel-php@0.9.0` and Node 22. The build checks PHP 8.2+ and the required `pdo_mysql`, `mbstring`, OpenSSL and session extensions, then includes the locked Composer dependencies.
- `vercel.json` routes the four existing assets as static files and rewrites the page/API/health routes to `api/entry.php`. That entry dispatches only to the existing page, API or health handler. A final 404 rule blocks all other public paths, including source files.
- `/` and `/index.php` execute the existing page. `/api.php` executes the existing API. `/health` reports that PHP is running. All other paths return a JSON 404. There are no public installer/admin CLI/database/source-file routes.
- Production sessions live in MySQL's `web_sessions` table and use database locks, so login and CSRF state survive container restarts and work across instances. Session cookie identifiers are hashed before storage.
- Deployment ignore files omit Git, runtime backups, `.env` files, SQL exports, tests and local credentials. PHP libraries are bundled for execution and cannot be downloaded through the route table.
- The function trusts the HTTPS forwarding header supplied by Vercel?s proxy. `api/php.ini` disables debug output and file uploads.

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
| `TF_DB_SSL_CA` | A valid provider CA file path inside the runtime; prefer the portable `TF_DB_SSL_CA_PEM` setting below |
| `TF_DB_SSL_CA_PEM` | Provider's CA certificate PEM text if it uses a private CA; the app materializes this privately for the connection and deletes the temporary file |
| `TF_SESSION_STORE` | `database` (also set in `vercel.json`; mandatory in production) |
| `TF_TRUST_PROXY` | `1` (also set in `vercel.json` for Vercel?s HTTPS proxy) |
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_USERNAME` | `bolencesjet@gmail.com` |
| `SMTP_PASSWORD` | Google App Password for this account, not your Google login password |
| `SMTP_FROM_EMAIL` | `bolencesjet@gmail.com` |
| `SMTP_FROM_NAME` | `Midnight Brawl` |

`MYSQL_ADDON_HOST`, `MYSQL_ADDON_PORT`, `MYSQL_ADDON_DB`, `MYSQL_ADDON_USER` and `MYSQL_ADDON_PASSWORD` are also accepted for Clever Cloud connection values. Explicit `TF_DB_*` names take precedence. Production requires verified TLS; there is no option to disable certificate verification. If the selected database plan does not offer a verified TLS connection, obtain a provider-supported secure connection before using it for production.

Generate the application key privately with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Enable Google 2-Step Verification and create a [Google App Password](https://support.google.com/accounts/answer/185833) for SMTP. Never use public/browser environment-variable prefixes for secrets.

Migration and administrator CLI tools run from the source checkout with the selected database variables set in the PHP process. The deployment excludes schema/test files, and CLI tools are never exposed as HTTP setup endpoints. Configure local migration TLS with a real local CA path or the PEM environment variable; a container-only path will not exist on Windows.

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

```powershell
php tests/algorithms.php
node tests/integration.mjs --vercel
node tests/deployment.mjs --vercel
```

The integration suite uses shared MySQL sessions. Deployment tests use a PHP adapter that evaluates the checked-in `vercel.json` route table and exercise two independent servers, server restart, logout and strict session IDs. They create and remove only their own isolated `tfv1_test_*` database.

The Vercel-hosted runtime build and live URL still need to pass the release checks; a successful local route test alone does not prove the remote domain was updated. This guide does not claim the remote deployment has succeeded until the live URL checks pass.
