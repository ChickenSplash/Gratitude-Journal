# Gratitude Journal

Three lines a day, kept in an account that's yours.

Sign in, write what you're grateful for, and read back everything you've
written. Entries live in SQLite, scoped to the user who wrote them.

## Stack

| Piece    | Choice                                     |
|----------|--------------------------------------------|
| Server   | Laravel 13 on PHP 8.4                      |
| Frontend | Livewire 4 — no bundler, no build step     |
| Database | SQLite                                     |
| Auth     | Laravel's own, email + password            |
| Styling  | One handwritten stylesheet, no CSS framework |

There is no Node toolchain. The page loads one CSS file and one small script,
both served straight out of `resources/assets/`, which is the whole reason a
build step isn't needed.

## Running it

### With Docker

```fish
docker network create edge   # once, if it doesn't exist yet
docker compose up -d --build
```

That's it — no `.env` to write, no key to generate, no directory to create
first. On the first boot the container makes `./data`, generates an application
key into `./data/app_key`, creates `./data/journal.sqlite` and migrates it.
Open <http://localhost:3000>.

`./data` is bind-mounted to `/data` in the container, so the database sits in
the project directory rather than sealed inside a named volume. Rebuilding the
image never touches it, and neither the key nor the entries change — so nobody
gets signed out by a deploy.

Files are written as uid 1000. On a host where yours isn't:

```fish
env UID=(id -u) GID=(id -g) docker compose up -d
```

Backing it up — a plain file copy is *not* enough, because a WAL-mode database
also has a `-wal` sidecar holding recent writes. `VACUUM INTO` takes a
consistent snapshot of both:

```fish
docker compose exec journal php -r '(new PDO("sqlite:/data/journal.sqlite"))->exec("VACUUM INTO \"/data/backup.sqlite\"");'
```

`backup.sqlite` lands next to the database in `./data`, ready to copy off.

### Without Docker

```fish
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

Open <http://localhost:8000/gratitude-journal>. `php artisan test` runs the
suite; it uses an in-memory database and touches nothing on disk.

### A note on macOS and Windows

The bind mount above is the right default on a Linux host, where it's a native
mount. On Docker Desktop it goes through VirtioFS/gRPC-FUSE, where SQLite's WAL
shared-memory file and POSIX advisory locks have a long history of being slow or
unreliable — expect the occasional `database is locked` or `disk I/O error`.
If you hit that while developing on a Mac, run it natively as above, or point
`DB_DATABASE` at a path inside the container.

## Cloudflare Tunnel

The container joins the external `edge` network under the name
`gratitude-journal`, so the tunnel reaches it at `http://gratitude-journal:3000`.

```yaml
ingress:
  - hostname: your.domain
    path: /gratitude-journal.*
    service: http://gratitude-journal:3000
  - service: http_status:404
```

Everything the browser is asked to fetch sits under `/gratitude-journal`: the
pages, the stylesheet, the script and Livewire's own update endpoint. That's
deliberate, and there's a test that fails if a URL ever escapes the prefix —
`cloudflared` forwards the path as-is, so anything outside it would 404 before
reaching PHP. Hostname-based ingress (no `path:` line) works just as well; `/`
redirects into `/gratitude-journal` either way.

`cloudflared` terminates TLS and forwards the real scheme and client address in
`X-Forwarded-*`, which the app trusts from private ranges only. That's what
makes the session cookie mark itself `Secure` and login throttling count per
visitor rather than per tunnel.

## Layout

```
routes/web.php                          every URL, all under one prefix
config/journal.php                      the prefix, and the shape of an entry
app/Livewire/Journal.php                the signed-in page
app/Livewire/AuthPanel.php              sign in / create account
app/Support/Journal.php                 every read and write against entries
app/Support/ExportFile.php              parsing an untrusted export file
app/Http/Controllers/                   logout, import, export, static assets
resources/views/layouts/app.blade.php   the shell
resources/assets/app.css                every style
resources/assets/app.js                 theme toggle, toast timer
tests/Feature/                          the suite
docker/                                 entrypoint, Apache vhost, php.ini
```

`app/Support/Journal.php` is where the queries live. Both Livewire components
and all three controllers go through it, and every method takes the user it
acts for, so a public id guessed from another account can't reach a row it
doesn't own.

## Routes

| Method | Path                            | Auth | Purpose                          |
|--------|---------------------------------|------|----------------------------------|
| `GET`  | `/`                             | –    | Redirect into the app            |
| `GET`  | `/gratitude-journal/login`      | –    | Sign in                          |
| `GET`  | `/gratitude-journal/register`   | –    | Create account                   |
| `GET`  | `/gratitude-journal`            | yes  | Today's three lines, and history |
| `GET`  | `/gratitude-journal/export`     | yes  | Download the journal as JSON     |
| `POST` | `/gratitude-journal/import`     | yes  | Add the entries from a file      |
| `POST` | `/gratitude-journal/logout`     | yes  | Sign out                         |
| `GET`  | `/gratitude-journal/assets/…`   | –    | Stylesheet and script            |
| `POST` | `/gratitude-journal/livewire/…` | –    | Livewire component updates       |
| `GET`  | `/up`                           | –    | What Docker's healthcheck calls  |

## Schema

```
users        id, name, email (unique), email_verified_at, password, timestamps
entries      id, user_id -> users, public_id, entry_date, timestamps
entry_items  id, entry_id -> entries, position, body
```

Plus Laravel's own `sessions`, `cache` and `jobs` tables.

Entry lines are normalised into `entry_items` rather than stored as a JSON blob,
so search, streaks or word counts stay ordinary SQL later on.

`public_id` is the id the browser sees and the one that ends up in an export
file. It is unique **per account**, not globally — `UNIQUE (user_id, public_id)`.
That is what makes import idempotent (re-importing the same file adds nothing)
while still letting two people import the same export without colliding with
each other.

Foreign keys are on with `ON DELETE CASCADE`: deleting a user clears their
sessions, entries and lines in one statement.

## Import and export

Export writes a flat JSON array, one object per entry:

```json
[{ "id": "…", "date": "2026-08-12T09:00:00.000Z", "items": ["…"] }]
```

Import reads that same shape, so a journal round-trips through a file
unchanged — and because `public_id` is what identifies an entry, importing the
same file twice adds nothing the second time. Both are plain HTTP — a link and
a form post rather than anything Livewire does — so they work with JavaScript
off and can be scripted with curl and a session cookie. Anything malformed in
an imported file is dropped rather than allowed to reach the database.

## Email verification

Not switched on. Accounts work the moment they're created, and nothing sends
mail. Turning it on later is three steps and no rewriting:

1. **Implement the contract.** `class User extends Authenticatable implements
   MustVerifyEmail` in `app/Models/User.php`. The `Registered` event that
   `AuthPanel::register()` already fires is what Laravel listens for to send the
   mail, so nothing in the sign-up flow changes.
2. **Add the routes and gate the journal.** Laravel ships the controllers; what
   you need is a `verification.notice` route pointing at a "check your inbox"
   view, the signed `verification.verify` route, and `['auth', 'verified']` on
   the journal route in `routes/web.php`.
3. **Point `MAIL_*` at something real.** `MAIL_MAILER=log` writes the
   verification link into the log instead of sending it, which is a good way to
   test step 2 before you have SMTP credentials. After that, any of Laravel's
   drivers — SMTP, Resend, Postmark, Mailgun, SES — is a config change.

Worth knowing before you start: this is the one change here that can lock you
out of your own journal. Existing accounts have `email_verified_at` set to
null, so the moment step 2 lands, every one of them hits the notice page. If
the mailer isn't working yet there's no way past it. Backfill first —
`User::whereNull('email_verified_at')->update(['email_verified_at' => now()])`
— so only accounts made after the switch have to verify.

Password reset is in the same position: the `password_reset_tokens` table is
already there, and the flow needs the same working mailer.

## Accounts only

There is no anonymous or in-memory mode: sign in first, then write. Every entry
hangs off a `user_id`, and reads, writes and deletes are all scoped by it.

Nothing in the schema rules out adding a guest flow later. `Journal::import()`
is already a bulk insert of entries into an account — the same call the import
endpoint uses — so entries written outside an account would have somewhere to
land.

## Security notes

- **Passwords** — bcrypt at 12 rounds, Laravel's default, salted per user.
- **Sessions** — stored in SQLite, sent as an `HttpOnly; SameSite=Lax` cookie
  that marks itself `Secure` over HTTPS. The id is regenerated on sign-in, so a
  session fixed before login isn't the one that ends up authenticated.
- **Throttling** — 8 failed sign-ins per email + IP per 15 minutes. Keyed on
  both, so one account being hammered from elsewhere can't lock its owner out.
- **Enumeration** — a wrong password and an email with no account return the
  same message. There's a test that fails if they ever drift apart.
- **Ownership** — reads, deletes and imports are all scoped by `user_id`.
- **CSRF** — Laravel's token on every form and on Livewire's endpoint.
- **Uploads** — imports are capped at 5 MB and 1000 entries, parsed with
  `JSON_THROW_ON_ERROR`, and never touch the filesystem.

## Design notes

A few decisions that aren't obvious from reading the files:

- **The prefix is real routing, not a web-server alias.** Livewire's update and
  script endpoints are re-registered under it in `AppServiceProvider`, because
  it picks its own root-level paths by default.
- **The stylesheet is served by a controller**, not out of `public/`. A
  directory called `public/gratitude-journal/` would shadow the page of the same
  name — Apache would try to list the folder instead of handing the request to
  Laravel.
- **Routes are deliberately not cached** in the container. Config and views are.
  Two routes are registered from a service provider, and a dozen routes aren't
  worth the subtlety.
- **`opcache.validate_timestamps=0`** in the image: the code can't change
  without a rebuild, so there's no reason to stat every file on every request.
