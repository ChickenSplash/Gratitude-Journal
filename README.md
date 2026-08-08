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
unusually simple. The one hard requirement: **`pb_data/` must survive restarts
and redeploys.** That directory *is* your users' journals. Platforms with an
ephemeral filesystem will lose it without warning.

The simplest setup is a single host serving both halves: drop
`gratitude-journal.html` into `pocketbase/pb_public/` as `index.html` and set
`POCKETBASE_URL` to `window.location.origin`. One thing to deploy, and no CORS
to configure.

### Free options that keep your data

These are genuinely free and give you a real disk:

- **[Oracle Cloud Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)**
  — the most generous. 200 GB of block storage, 10 TB/month egress, and either
  two AMD micro instances (1 GB RAM each) or ~2 Arm OCPUs with 12 GB. Free for
  the life of the account, not a 12-month trial. Two catches: Arm capacity is
  frequently "out of host capacity" in popular regions, and Oracle may reclaim
  Always Free instances idle for 7 straight days (under 20% CPU, network and
  memory) — which a quiet journal can easily trip. An uptime pinger, or
  upgrading to pay-as-you-go, avoids that.
- **[Google Cloud Always Free](https://cloud.google.com/free/docs/compute-getting-started)**
  — one `e2-micro` in `us-west1`, `us-central1` or `us-east1` plus 30 GB of
  disk. Smaller, but no idle-reclamation policy. Choose **Standard** persistent
  disk; Balanced and SSD are not free.
- **A Raspberry Pi or spare machine at home**, exposed with a free
  [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/).
  Gives you HTTPS with no public IP, no port forwarding and no cloud account.
  Your uptime is your own.

The static HTML can go on Cloudflare Pages, GitHub Pages or Netlify for free if
you'd rather keep it separate — but if you use the `pb_public/` approach above
you don't need a second host at all.

#### If you serve the page from GitHub Pages

This repo already publishes to
<https://chickensplash.github.io/Gratitude-Journal/gratitude-journal.html>, so
the front end is done. Two consequences:

- **PocketBase must be reachable over `https`.** Pages is HTTPS-only, and
  browsers block an HTTPS page from calling an `http://` API — the requests fail
  silently with a mixed-content error and the app falls back to looking
  unreachable. So the default `http://127.0.0.1:8090` cannot work from the
  published page; that value is for local development only. Put Caddy,
  nginx or a Cloudflare Tunnel in front of PocketBase for a certificate.
- **Set the CORS origin to `https://chickensplash.github.io`**, since the page
  and the API are on different domains.

Rename the file to `index.html` if you'd like the bare
`/Gratitude-Journal/` URL to work — right now it 404s.

### Free tiers that will eat your data

Worth naming, because they're the usual suggestions:

- **Render's free tier** has an ephemeral filesystem and sleeps after
  inactivity. PocketBase's own maintainer
  [advises against relying on it](https://github.com/pocketbase/pocketbase/discussions/6992)
  for anything you need to keep. Same story for any free container tier without
  an attached volume.
- **Fly.io no longer has a free tier** — new accounts get a short trial only.
  It's a good paid host; mount a volume at `/pb_data` if you use it.
- **[PocketHost](https://pockethost.io/pricing)** is PocketBase-specific managed
  hosting and handles TLS, backups and updates for you, but it is now paid
  (~$10/month, or a one-off lifetime option).

### Paid, if you'd rather not administer anything

A ~$5/month VPS (Hetzner, DigitalOcean) with Caddy in front for TLS, or
[PikaPods](https://www.pikapods.com/), which has a one-click PocketBase image.

### Back it up regardless

Free tiers can and do disappear. PocketBase has backups built in — *Settings →
Backups* in the dashboard, which can run on a schedule and upload to any
S3-compatible bucket (Cloudflare R2's free tier is enough for journal-sized
data). The app's own **Export** button is a per-user fallback, not a substitute.

### Set the CORS origin

PocketBase allows all origins by default, which is fine locally. If you serve
the HTML from a different domain than the API, restrict it once deployed — in
the dashboard under *Settings*, or with `--origins=https://yourdomain.example`.
Serving from `pb_public/` sidesteps this entirely.

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
