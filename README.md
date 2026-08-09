# Gratitude Journal

A single-page gratitude journal with two modes:

- **Guest** — write entries with no account. They live in memory and disappear when the tab closes.
- **Signed in** — entries are stored in SQLite, scoped to the user.

Anything written as a guest is moved into the account on sign-in, so nothing typed before logging in is lost.

## Stack

| Piece    | Choice                                                |
|----------|-------------------------------------------------------|
| Server   | Node 22 + Express                                     |
| Database | SQLite via `better-sqlite3`                           |
| Auth     | Hand-rolled: scrypt hashes + opaque session cookies   |
| Frontend | React, no build step, same look as the original        |

Two runtime dependencies in total. Password hashing uses Node's built-in `crypto.scryptSync`, so there's no native argon2/bcrypt build to fight with.

## Running it

### Without Docker

```fish
npm install
npm run dev          # node --watch, restarts on change
npm test             # node:test, no test dependencies
```

Open <http://localhost:3000>. The database appears at `./data/journal.sqlite`.

### With Docker

```fish
docker compose up --build -d
docker compose logs -f
```

The database lives in the `journal-data` named volume, so rebuilding the image never wipes it.

Backing it up:

```fish
docker compose exec journal node -e "new (require('better-sqlite3'))('/data/journal.sqlite').backup('/data/backup.sqlite')"
docker compose cp journal:/data/backup.sqlite ./backup.sqlite
```

## Layout

```
server.js               starts the janitor and listens
src/app.js              the Express app, wired up but not listening
src/db.js               schema and prepared statements
src/auth.js             hashing, session cookies, throttling
src/routes.js           /api
public/index.html       the page shell — markup only
public/styles.css       every style
public/journal-core.js  API client, localStorage, dates — no React, no JSX
public/journal-app.jsx  hooks and components
test/api.test.js        end-to-end tests
```

`journal-core.js` is plain JavaScript, so it loads as an ordinary script and
exposes one `Journal` global. Only `journal-app.jsx` goes through Babel. Keeping
the JSX to a single file is deliberate: without a bundler, splitting further
would mean several scripts sharing globals in a load order nothing enforces.

## Environment variables

| Variable        | Default   | Notes                                           |
|-----------------|-----------|-------------------------------------------------|
| `PORT`          | `3000`    |                                                 |
| `DATA_DIR`      | `./data`  | Where `journal.sqlite` is written                |
| `COOKIE_SECURE` | `false`   | Set `true` once you're serving over HTTPS        |
| `TRUST_PROXY`   | `false`   | Set `true` behind nginx/Caddy/Cloudflare Tunnel  |

## API

| Method   | Path                 | Auth | Purpose                       |
|----------|----------------------|------|-------------------------------|
| `GET`    | `/api/auth/me`       | –    | Current user, or `null`       |
| `POST`   | `/api/auth/register` | –    | Create account, start session |
| `POST`   | `/api/auth/login`    | –    | Start session                 |
| `POST`   | `/api/auth/logout`   | –    | End session                   |
| `GET`    | `/api/entries`       | yes  | All entries, newest first     |
| `POST`   | `/api/entries`       | yes  | Create one entry              |
| `POST`   | `/api/entries/bulk`  | yes  | Import / adopt guest entries  |
| `DELETE` | `/api/entries/:id`   | yes  | Delete one entry              |

`GET /healthz` sits outside `/api` and is what Docker's healthcheck calls.

## Schema

```
users        id, username (unique, case-insensitive), password_hash, created_at
sessions     id, user_id -> users, expires_at, created_at
entries      id, user_id -> users, public_id, entry_date, created_at
entry_items  entry_id -> entries, position, body
```

Entry lines are normalised into `entry_items` rather than stored as a JSON blob, so search, streaks or word counts stay ordinary SQL later on.

`public_id` is the id the browser sees and the one that ends up in an export file. It is unique **per account**, not globally — `UNIQUE (user_id, public_id)`. That is what makes import idempotent (re-importing the same file adds nothing) while still letting two people import the same export without colliding with each other.

Foreign keys are on with `ON DELETE CASCADE`: deleting a user clears their sessions, entries and lines in one statement.

## Upgrading from the localStorage-only version

The previous version of this app kept everything in `localStorage` under `gratitudeEntries`. Those entries are read back into guest state on first load, so an upgrade doesn't look like the journal has been wiped. They're only removed from `localStorage` once they've been adopted into an account — creating an account or signing in moves them across, and the key is cleared after that succeeds.

Guest entries written *after* the upgrade are in memory only, as above.

## Security notes

- **Passwords** — scrypt (N=16384, r=8, p=1), 16-byte random salt per user, compared with `timingSafeEqual`.
- **Sessions** — 256 bits of randomness, stored server-side, sent as an `HttpOnly; SameSite=Lax` cookie. JavaScript can't read it, so an XSS bug can't lift the token.
- **Throttling** — 8 login attempts per username+IP per 15 minutes, in-process.
- **Enumeration** — login failures return the same message whether or not the username exists.
- **Ownership** — reads, deletes and imports are all scoped by `user_id`, so a guessed entry id from another account returns 404 and can't overwrite anything.
- **CSRF** — `SameSite=Lax` plus a JSON-only API covers it at this scale. Add a real token if this ever becomes properly multi-user.

## Before exposing it to the internet

1. Put HTTPS in front (Caddy, or a Cloudflare Tunnel) and set `COOKIE_SECURE=true` and `TRUST_PROXY=true`.
2. Rate limit at the proxy, not just in-process.
3. Schedule the SQLite backup command above.

## Dropping Babel

`public/journal-app.jsx` is still compiled in the browser by `@babel/standalone`, roughly 300 KB on first load. Now that a real server is involved, you can precompile: build `journal-core.js` and `journal-app.jsx` with esbuild and replace the four script tags with one `<script src="/app.js">`.

That is also the point at which the two front-end files can become however many real ES modules the code wants, since a bundler makes `import` work.

Two other things fall out of it:

- The runtime dependency on unpkg.com disappears. Right now the app renders nothing if unpkg is unreachable.
- Bundle output can be fingerprinted, at which point `src/app.js` can serve `public/` with a long `max-age` instead of the `no-cache` it uses today.
