# Fix the downloading Vercel deployment

The existing public deployment returns `application/x-httpd-php` and raw PHP for `/`, `/api.php` and `/config.php`. The PHP code is being served as static files. Changing the filename or forcing an HTML content type would not execute authentication or database code.

This checkout now includes `Dockerfile.vercel`, following [Vercel's PHP container guide](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker). Vercel detects this filename and selects its Container preset, including when another framework would otherwise be detected. The application remains PHP/MySQL. No tournament algorithms, event rules or stored account passwords are changed by the deployment fix.

## What the deployment runs

- FrankenPHP with PHP 8.4, `pdo_mysql`, `mbstring`, OpenSSL and the locked Composer dependencies.
- Caddy serves `/app/public`, never the repository root. It serves only the four existing assets and sends dynamic requests to `public/index.php`.
- `/` and `/index.php` execute the existing page. `/api.php` executes the existing API. `/health` reports that PHP is running. All other paths return a JSON 404. There are no public installer/admin CLI/database/source-file routes.
- Production sessions live in MySQL's `web_sessions` table and use database locks, so login and CSRF state survive container restarts and work across instances. Session cookie identifiers are hashed before storage.
- Explicit Docker copies and ignore files omit Git, runtime backups, `.env` files, SQL exports, tests and local credentials. Application PHP libraries are outside the public directory.
- The container trusts the forwarding header supplied by Vercel's HTTPS proxy. Do not expose this production image directly to untrusted traffic without an equivalent trusted proxy.

## Configure the existing Vercel project

1. Open **Vercel → midnightbrawl → Settings → Build and Deployment**. The repository root directory must be the directory containing `Dockerfile.vercel` and `composer.json`. Select **Container** if automatic detection has not selected it. Clear any previous static Output Directory or custom build/install override. Do not select a static site or serve the repository root.
2. Under **Settings → Environment Variables**, add the values below for **Production**. Use separate database/email credentials for Preview. Enter secrets privately in Vercel; do not paste them into chat or commit them.
3. In Clever Cloud's MySQL add-on console, open its connection/environment settings and copy the database name, username, password, port and TLS details. The supplied host alone is not sufficient to connect. Use the connection port actually shown by Clever Cloud.
4. Apply the additive database migration to that existing hosted database using a PHP environment with access to it. Use a migration identity that can create/alter tables; `php migrate.php` works with an existing database and does not require CREATE DATABASE permission. It preserves existing records and does not add demo events. Back up the hosted database first. Do not import an empty local database over an existing hosted database.
5. Deploy the commit containing this fix to the existing project. Vercel should build a container image rather than upload raw PHP. Environment changes require a new deployment. See [Vercel Container Runtime](https://vercel.com/docs/functions/runtimes/container).
6. Follow the release checks below before announcing that registration is ready.

| Variable | Production value |
| --- | --- |
| `TF_ENV` | `production` (also set in the image) |
| `TF_APP_URL` | `https://midnightbrawl.vercel.app` |
| `TF_APP_KEY` | Random secret of at least 32 characters; keep stable across instances/deployments |
| `TF_DB_HOST` | `bgk6s8myavpb5l1l1n38-mysql.services.clever-cloud.com` |
| `TF_DB_PORT` | Port shown by Clever Cloud, normally `3306` |
| `TF_DB_NAME` | Your Clever Cloud database name |
| `TF_DB_USER` | Your restricted Clever Cloud database username, not `root` |
| `TF_DB_PASSWORD` | Its password |
| `TF_DB_SSL_CA` | `/etc/ssl/certs/ca-certificates.crt` only if the provider's server certificate chains to a system-trusted CA; otherwise use `TF_DB_SSL_CA_PEM` below |
| `TF_DB_SSL_CA_PEM` | Provider's CA certificate PEM text if it uses a private CA; the app materializes this privately for the connection and deletes the temporary file |
| `TF_SESSION_STORE` | `database` (also set in the image; mandatory in production) |
| `TF_TRUST_PROXY` | `1` (also set in the image for Vercel's HTTPS proxy) |
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_USERNAME` | `bolencesjet@gmail.com` |
| `SMTP_PASSWORD` | Google App Password for this account, not your Google login password |
| `SMTP_FROM_EMAIL` | `bolencesjet@gmail.com` |
| `SMTP_FROM_NAME` | `Midnight Brawl` |

`MYSQL_ADDON_HOST`, `MYSQL_ADDON_PORT`, `MYSQL_ADDON_DB`, `MYSQL_ADDON_USER` and `MYSQL_ADDON_PASSWORD` are also accepted for Clever Cloud connection values. Explicit `TF_DB_*` names take precedence. Production requires verified TLS; there is no option to disable certificate verification. If the selected database plan does not offer a verified TLS connection, obtain a provider-supported secure connection before using it for production.

Generate the application key privately with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Enable Google 2-Step Verification and create a [Google App Password](https://support.google.com/accounts/answer/185833) for SMTP. Never use public/browser environment-variable prefixes for secrets.

Migration and administrator CLI tools run from the source checkout with the selected database variables set in the PHP process. The runtime image intentionally does not include the CLI tools or schema files. They are never exposed as HTTP setup endpoints. Configure local migration TLS with a real local CA path or the PEM environment variable; a container-only path will not exist on Windows.

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
node tests/integration.mjs
node tests/deployment.mjs
```

The integration suite uses shared MySQL sessions. Deployment tests use a PHP adapter for the same route/asset policy and exercise two independent servers, server restart, logout and strict session IDs. They create and remove only their own isolated `tfv1_test_*` database.

Docker is not installed in the current workstation environment, so the image build/Caddy startup must also be validated by Vercel's build or a Docker-enabled machine before the deployment is considered verified. This guide does not claim the remote deployment has succeeded until the live URL checks pass.
