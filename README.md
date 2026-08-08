# Gratitude Journal

A small journal for writing down three things you're grateful for. One HTML
file, no build step. Entries can stay on your device, or sync to an account
backed by [PocketBase](https://pocketbase.io).

## How it stores things

The app has two modes and switches between them automatically:

| | Signed out | Signed in |
|---|---|---|
| Entries live in | `localStorage`, this browser only | PocketBase, per account |
| Works offline | Yes | Needs the server |
| Syncs between devices | No | Yes |

You never need an account. Open the file and start writing — that's the
original behaviour and it still works, including with no server running at all.

Entries are stored in **one** place at a time, never mirrored. Signing out
leaves nothing behind on the device, so a shared computer doesn't leak one
person's journal to the next. The first time you sign in with entries still on
the device, the app offers to move them into your account.

## Running it locally

You need two things: the PocketBase server, and the HTML file open in a browser.

**1. Start PocketBase**

```bash
cd pocketbase
./start.sh
```

On first run this downloads the PocketBase binary (~30 MB), applies the
migrations in `pb_migrations/`, and asks you to create an admin account. That
admin is for the PocketBase dashboard at <http://127.0.0.1:8090/_/> — it is
*not* a journal user. Journal users sign up in the app itself.

**2. Open the app**

Serve it over HTTP rather than opening the file directly, so the browser treats
it as a normal web page:

```bash
npx http-server . -p 8000
# then visit http://127.0.0.1:8000/gratitude-journal.html
```

Click **Sign in → Create an account** and you're going.

## Pointing the app at your own server

There's one line to change, near the top of `gratitude-journal.html`:

```html
<script>
  window.POCKETBASE_URL = 'http://127.0.0.1:8090';
</script>
```

Set it to wherever your PocketBase lives, e.g.
`https://journal.example.com`. Use `https` in production — a plain-HTTP server
sends passwords and session tokens in the clear.

## Hosting it

PocketBase is a single binary with an embedded SQLite database, so hosting is
unusually simple. Any of these work:

- **A small VPS** (Hetzner, DigitalOcean, ~$5/month). Copy the binary and
  `pb_migrations/` up, put nginx or Caddy in front for TLS, and run it under
  systemd.
- **[PikaPods](https://www.pikapods.com/)** — has a one-click PocketBase image;
  cheapest path if you'd rather not administer a server.
- **[Fly.io](https://fly.io)** — works well, but mount a persistent volume at
  `/pb_data`. Without one, every deploy wipes the database.
- **Railway / Render** — same caveat: attach a persistent disk, don't rely on
  the container filesystem.

Two things to get right wherever you host:

**Persist `pb_data/`.** That directory *is* your users' journals. On any
container platform it must be a mounted volume, and it should be backed up.

**Set the CORS origin.** PocketBase allows all origins by default, which is
fine locally. Once deployed, restrict it to the domain serving the HTML — in
the dashboard under *Settings*, or with
`--origins=https://yourdomain.example`.

To serve the app from PocketBase itself and skip CORS entirely, drop
`gratitude-journal.html` into `pocketbase/pb_public/` as `index.html` and set
`POCKETBASE_URL` to `window.location.origin`.

## Password resets need email

The **Forgot password?** link calls PocketBase's reset endpoint, which sends an
email. Until you configure SMTP under *Settings → Mail settings* in the
dashboard, PocketBase has no way to deliver it and the link won't do anything
useful. Sign-in and registration work fine without it.

Email verification is not required to save entries. If you want it, turn it on
for the `users` collection in the dashboard.

## The database

`pocketbase/pb_migrations/` defines the schema, so a fresh server is always set
up the same way. One collection is added; `users` is PocketBase's built-in one.

**`entries`**

| Field | Type | Notes |
|---|---|---|
| `user` | relation → `users` | Required. Cascade-deletes with the account. |
| `date` | date | When the entry was written |
| `items` | json | The list of things, as strings |
| `created` / `updated` | autodate | Set by the server |

Every access rule on the collection is:

```
@request.auth.id != "" && user = @request.auth.id
```

So you can only ever list, read, create, change or delete your own rows. This
is enforced by the server, not the UI. Verified: signed-out requests return
nothing, one user reading another's entry by id gets a 404, and trying to create
an entry owned by someone else is rejected.

Deleting an account deletes its entries with it, via the cascade on `user`.

## Development notes

The page loads React, Babel and the PocketBase SDK from unpkg and compiles the
JSX in the browser. That keeps the whole app to one file you can open and edit,
at the cost of a slower first paint — fine for a personal journal, not what
you'd ship to a large audience.

`Export` writes all visible entries to a JSON file; `Import` reads one back,
skipping entries already present (matched on content as well as id, so
re-importing the same file twice doesn't duplicate anything). Between them they
work as a backup, and as a way to move entries in or out of an account.
