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

Follow [DEPLOYMENT.md](DEPLOYMENT.md). `vercel.json` declares an executable PHP function and explicit routes instead of relying on static file hosting; production sessions use the shared MySQL database. Configure the hosted database and SMTP environment variables and run the additive migration before releasing registration.

## Run a tournament

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
