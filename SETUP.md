# Setting up the cloud journal

The journal works with no setup at all — open `gratitude-journal.html` and
entries are stored in that browser. Everything below is for the version that
stores entries in a database behind a login, so the same journal follows you
across devices.

## About "MySQL"

Supabase is built on **PostgreSQL**, not MySQL — there is no option to swap
the engine. For an app like this the difference is invisible: it's a managed
SQL database with a REST API, a free tier, and authentication included, which
is what makes the login and guest access below possible without writing a
server. If you specifically need MySQL, see [Really need MySQL?](#really-need-mysql)
at the end.

## What you need to do

### 1. Create a Supabase project

1. Sign up at <https://supabase.com> and create a new project (the free tier
   is enough — no card needed).
2. Pick a strong database password and a region near you.
3. Wait a minute or two for it to finish provisioning.

### 2. Create the table

In your project: **SQL Editor → New query**, paste the contents of
[`supabase/schema.sql`](supabase/schema.sql), and hit **Run**.

That creates one `entries` table and switches on Row Level Security, so each
person can only read and write their own rows.

### 3. Turn on guest access

**Authentication → Sign In / Providers** (older dashboards call it
*Providers*):

- **Email** — leave enabled. This is the sign-up/sign-in form.
- **Anonymous sign-ins** — turn this **on**. This is what powers the
  "Continue as a guest" button.

While you're there, decide about **Confirm email** (under Email):

- **On** (default): new accounts must click a link before they can sign in.
  Safer, but you'll want to add your own SMTP details under
  **Authentication → Emails** before real users arrive — the built-in sender
  is rate-limited to a handful of messages an hour and is meant for testing.
- **Off**: sign-up works instantly. Fine while you're the only user.

### 4. Paste your keys into the app

**Project Settings → API Keys**. Copy:

- **Project URL** — looks like `https://abcdefgh.supabase.co`
- **anon** / **publishable** key — a long string starting `eyJ…` or `sb_publishable_…`

Open `gratitude-journal.html`, find these two lines near the top of the
`<script type="text/babel">` block, and fill them in:

```js
const SUPABASE_URL = 'https://abcdefgh.supabase.co';
const SUPABASE_ANON_KEY = 'eyJhbGciOi…';
```

**Do not** use the `service_role` / secret key. That one bypasses every
security rule and must never appear in a web page. The anon key is designed
to be public — it can only do what the policies in `schema.sql` allow.

### 5. Serve the page

Supabase auth needs a real origin, so opening the file directly from your
hard disk (`file:///…`) won't work. Any static host will do:

```sh
# quick local check
python3 -m http.server 8000
# then visit http://localhost:8000/gratitude-journal.html
```

For a real URL, drop the file on GitHub Pages, Netlify, Vercel, or Cloudflare
Pages — all free for something this size.

Finally, tell Supabase where the app lives: **Authentication → URL
Configuration → Site URL**, set it to your deployed address. Confirmation and
password-reset emails link back to that address.

## How it behaves

| | Where entries live | Survives clearing the browser? |
|---|---|---|
| **Signed in with email** | Your Supabase database | Yes — sign in anywhere |
| **Guest** | Your Supabase database | No — the guest session is stored in this browser |
| **"Use this device only"** | `localStorage` | No |

A guest can add an email and password at any time from the banner at the top
of the page. Because Supabase keeps the same user id, every entry they have
already written comes with them — nothing is copied or lost.

If you used the journal before setting any of this up, the entries already in
your browser aren't gone: sign in and the app offers to upload them.

## Housekeeping

Guest accounts accumulate — one per person who taps "Continue as a guest" and
never comes back. To clear out the abandoned ones, run this occasionally in
the SQL Editor:

```sql
delete from auth.users
where is_anonymous = true
  and created_at < now() - interval '30 days'
  and id not in (select user_id from public.entries);
```

Deleting a user removes their entries too (`on delete cascade`), so keep the
`not in` clause if you'd rather hang on to guests who actually wrote
something.

## Really need MySQL?

Nothing here ties you to Supabase, but MySQL costs you the parts Supabase
gives away. You'd need:

1. A MySQL host — PlanetScale, Amazon RDS, or your own server.
2. A small backend API (Node/Express, PHP, whatever you know) to talk to it —
   a browser can't safely connect to MySQL directly, because the credentials
   would be visible to anyone who views source.
3. Your own authentication: password hashing, session tokens, password
   resets, and a guest-account scheme — roughly the work of steps 3 and 4
   above, written by hand.
4. Per-user filtering in every query, since MySQL has no equivalent of Row
   Level Security.

That's a few days' work rather than a few minutes, and it's the sort of code
that's unpleasant to get wrong. Worth it if you have an existing MySQL estate
to fit into; otherwise Postgres-via-Supabase does the same job here.
