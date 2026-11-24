# TFv1 — Team Fight version.1

A PHP + MySQL tournament manager for XAMPP. Original TFv1 shield branding, dark and white themes, responsive navigation, a fixed header, and scrollable tables. Composer installs PHPMailer for verified email registration; Node is used only for development tests.

## Start locally

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Review `config.php`. Defaults: `127.0.0.1:3306`, database `tfv1`, user `root`, blank password (the standard local XAMPP configuration). Environment variables `TF_DB_HOST`, `TF_DB_PORT`, `TF_DB_NAME`, `TF_DB_USER`, and `TF_DB_PASSWORD` override these values. Use a dedicated database user and password for any shared deployment.
3. In this directory, run `php install.php`. This creates the MySQL database and relational tables from `database/schema.sql`. It also adds a clearly labeled fictional sample tournament when no event exists. Re-running the installer preserves existing data.
4. Open **http://localhost/TFv1/**.
5. Run `composer install --no-dev`. Run `php install.php` again to apply the additive security migration. Follow [SECURITY.md](SECURITY.md) to configure SMTP and create your separate admin through `php admin.php`. Existing organizers sign in through **Admin sign in** with their email. First-organizer browser setup is available only over loopback in development. There are no default passwords.
6. Create a real event. The sample tournament is separate from real registrations.

Use a supported PHP release (8.2+ required in production) with `pdo_mysql` and `mbstring`, and MySQL 5.7+/MariaDB 10.4+ are required. Tested with this XAMPP installation. Google Fonts are optional: local system fonts work offline.

## Deploy on Vercel

To explicitly create the requested default administrator, run `php admin.php --create-default` with the intended database configured. Use **Admin sign in** with username `tfv1admin` and initial password `TFv1-Admin-2026!` only after creation succeeds. Existing accounts are never overwritten. See [SECURITY.md](SECURITY.md#requested-default-administrator) for changing this initial password and hosted-database setup.

Follow [DEPLOYMENT.md](DEPLOYMENT.md). `vercel.json` declares an executable PHP function and explicit routes instead of relying on static file hosting; production sessions use the shared MySQL database. Configure the hosted database and SMTP environment variables and run the additive migration before releasing registration.

## Run a tournament

### Player password recovery

Choose **Forgot password?** in **Player sign in**, enter your registered email, and use the emailed temporary password within 15 minutes. It works once and opens **Change password** after sign-in. You can also open **Change password** from Event settings or your account menu. Enter the temporary password as your current password and choose a new passphrase (15 characters minimum, 72 bytes maximum). Recovery requests preserve your existing password until you save a new one. Changing the password signs out other sessions. Organizer accounts continue using the separate **Organizer sign in** form.

Before deploying this feature, run `php migrate.php` against the hosted database to add `password_resets`; existing records are preserved. Recovery uses the same SMTP settings as registration. Verify actual delivery with a player inbox after deployment.

### Registration example

1. Open the official tournament URL and click **Register** in the upper-right corner.
2. Enter your name (for example, `Juan Dela Cruz`), an email inbox you control, and a unique password of at least 15 characters (maximum 72 bytes). Accept the fair-play agreement and click **Create account**.
3. Enter the six-digit code from your email within 10 minutes. If needed, wait one minute before requesting another code.
4. Select an event with open registration. Under **Players**, click **Register to play**. Enter a display name such as `JuanDC`, your actual game ID/server, and a preferred position from the event's list. The secondary position is optional and must be different. Accept fair play and click **Register player**.
5. Find your entry in **Players** and click **Check in** before team selection.

The administrator must complete initial setup first. Use a separate player account to participate if you are an organizer. Email verification requires working SMTP.

### Fixing “Origin is not allowed”

This message means the API rejected the website address used to submit the form. It happens before account creation or event registration. `TF_APP_URL` must identify the official website you open in your browser.

- Hosted example: open `https://midnightbrawl.vercel.app` and set `TF_APP_URL=https://midnightbrawl.vercel.app` on the host executing PHP. If you use another domain, configure that domain instead. Update the appropriate hosting environment and redeploy/restart PHP so it receives the setting.
- Local XAMPP example: open `http://localhost/TFv1/` and set `TF_APP_URL=http://localhost` in Apache/PHP's process environment. Include a nonstandard port if used, such as `http://localhost:8080`. Restart Apache after changing its environment. `.env.example` is a reference; this application does not load `.env` files.
- An app path such as `/TFv1/`, hostname capitalization and default ports are normalized by the API. Different domains, HTTP versus HTTPS, and different nonstandard ports remain blocked. `localhost` and `127.0.0.1` are different hosts.
- For a Vercel preview, prefer the official production URL for registration. If testing a preview is necessary, configure its exact URL in that preview's environment with separate test database/email settings. Avoid wildcard CORS or removing the origin/CSRF checks.

After correcting the setting, reopen the official URL, refresh the page for a fresh session, and retry. Run `php tests/origin.php` to check origin matching locally.

### Tournament workflow

- Create an event with independent **team size**, **formation mode**, **format**, **race target**, **deadline (Asia/Manila)**, and **game positions**. Competition rules are fixed at creation; admins can edit the name, game label and deadline, or delete an event with password confirmation.
- Players create accounts, register their display name, unique event game ID and preferred positions, accept the fair-play code, then check in. One registration per account per event. Organizers may check in players, and captains may check in their roster.
- **Captain Mode:** the organizer creates teams with available checked-in captains; captains pick players in snake order. The organizer can make a pick on a captain's behalf. Create all teams before beginning the draft.
- **Free Mode:** checked-in players create their own teams and request to join others. The captain or organizer approves requests. Players without a team stay in the available pool.
- **Random Shuffle:** the organizer shuffles checked-in players into full teams. Their count must divide evenly by team size; at least two teams are required. The first assigned member becomes captain. This does not claim to balance skill or positions.
- The organizer reviews and locks full rosters, then generates a single or double round robin. Teams stay fixed. Odd team counts receive byes. Rounds are generated; organizers coordinate actual match times.
- Captains for participating teams and officials submit scores. Exactly one team must reach the race target (1–5). A **different, non-participating referee or organizer** confirms each score before standings update. Invite a trusted second official: they create an account, then an organizer grants referee access in Event settings.
- Referees can return pending or confirmed results for correction, with a required reason. Old scores and reasons remain in the activity log. A replacement result requires independent confirmation again.
- Standings use 1 point per win and 0 per loss. Exactly two tied teams use head-to-head wins, then game-win differential. Groups of three or more use game-win differential. Remaining ties share a rank; alphabetical ordering is display-only. CSV export is available.
- **Reset tournament** requires the exact event name and a reason. It permanently removes teams, join requests, fixtures and results, unlocks rosters, and preserves registrations, check-ins and the audit trail. Existing schedules cannot be regenerated or shuffled silently.

See [SECURITY.md](SECURITY.md) for SMTP/Vercel environment-variable instructions, administrator creation, production controls, testing and release gates. New player accounts require email-code verification. Administrators use separate accounts from players.

Player game positions are independent from account permissions. Organizer and referee permissions apply across events; captain permissions are per team. Visitors can view event rosters, game IDs, match results, standings and event activity. Keep this visibility in mind when sharing the local site.

## Fair play

TFv1 prohibits cheating, exploits, account sharing and match fixing through its participant agreement. Roster locks, server-side permissions, valid race scores, independent result verification and an activity trail protect tournament administration. **The application does not inspect gameplay or detect cheats.** Use the game's anti-cheat tools, referee observation and game evidence. Balanced shuffle and a top-two final are optional future extensions, not included in this version.

## Database and verification

Relational InnoDB tables: `users`, `events`, `players`, `teams`, `team_members`, `join_requests`, `matches`, `audit_log`. Foreign keys and unique constraints protect relationships; mutations use transactions and a MySQL advisory lock. Passwords are hashed. State-changing requests require a session CSRF token. SQL uses prepared statements. Apache denies direct access to database scripts and test files.

Run these checks while MySQL is running:

```powershell
php tests/algorithms.php
node tests/integration.mjs
```

The integration suite creates a uniquely named `tfv1_test_*` MySQL database and a temporary PHP server, exercises real API requests, then removes only its own test database. The application database and first-organizer setup remain untouched. The test database user needs CREATE/DROP privileges for these isolated checks. Algorithm tests cover 2–32 teams, both round-robin formats, byes, reversed legs, score confirmation and ties.

Back up `tfv1` regularly using phpMyAdmin or `mysqldump`. Theme preference and the selected event are stored in the browser; all tournament records are stored in MySQL. For production hosting, use HTTPS, a restricted MySQL account, secure session configuration and operational backups.
