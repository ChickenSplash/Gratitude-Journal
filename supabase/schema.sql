-- Gratitude Journal — database schema
--
-- Run this once in your Supabase project: SQL Editor → New query → paste →
-- Run. It is safe to run again; every statement is idempotent.

-- ── Table ────────────────────────────────────────────────────────────
create table if not exists public.entries (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid not null references auth.users (id) on delete cascade,
  entry_date  timestamptz not null default now(),
  items       jsonb not null,
  created_at  timestamptz not null default now(),

  -- The app writes an array of strings; reject anything else at the door.
  constraint entries_items_is_array check (jsonb_typeof(items) = 'array')
);

-- The journal only ever reads "my entries, newest first".
create index if not exists entries_user_date_idx
  on public.entries (user_id, entry_date desc);

-- ── Row Level Security ───────────────────────────────────────────────
-- Without this, the anon key in the page would expose every row to
-- everyone. With it, a user can only ever touch rows carrying their own
-- auth.uid() — which is what makes shipping that key in public safe.
alter table public.entries enable row level security;

drop policy if exists "Read own entries"   on public.entries;
drop policy if exists "Insert own entries" on public.entries;
drop policy if exists "Update own entries" on public.entries;
drop policy if exists "Delete own entries" on public.entries;

-- Guests (anonymous sign-ins) also carry the `authenticated` role, so
-- these four policies cover them too.
create policy "Read own entries"
  on public.entries for select
  to authenticated
  using (auth.uid() = user_id);

create policy "Insert own entries"
  on public.entries for insert
  to authenticated
  with check (auth.uid() = user_id);

create policy "Update own entries"
  on public.entries for update
  to authenticated
  using (auth.uid() = user_id)
  with check (auth.uid() = user_id);

create policy "Delete own entries"
  on public.entries for delete
  to authenticated
  using (auth.uid() = user_id);
