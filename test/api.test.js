/* End-to-end tests against a real Express app and a real (throwaway) SQLite
   file. No mocks: the interesting behaviour lives in the SQL. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';

const DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'gratitude-test-'));
process.env.DATA_DIR = DATA_DIR; // read by src/db.js at import time

const { createApp } = await import('../src/app.js');

let baseUrl;
let server;

before(async () => {
  server = createApp().listen(0);
  await new Promise(resolve => server.once('listening', resolve));
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

after(async () => {
  await new Promise(resolve => server.close(resolve));
  fs.rmSync(DATA_DIR, { recursive: true, force: true });
});

/** A fetch that remembers its own session cookie, i.e. one browser. */
function client() {
  let cookie = null;

  return async function call(method, path, body) {
    const res = await fetch(baseUrl + path, {
      method,
      headers: {
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        ...(cookie ? { Cookie: cookie } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const setCookie = res.headers.get('set-cookie');
    if (setCookie) cookie = setCookie.split(';')[0];

    return { status: res.status, body: await res.json().catch(() => null) };
  };
}

let userCount = 0;
async function signUp(call, password = 'correct-horse') {
  const username = `tester${++userCount}`;
  const res = await call('POST', '/api/auth/register', { username, password });
  assert.equal(res.status, 201, JSON.stringify(res.body));
  return { username, password };
}

const entry = (id, ...items) => ({ id, date: '2026-01-02T10:00:00.000Z', items });

describe('auth', () => {
  it('starts as a guest and reports no user', async () => {
    const res = await client()('GET', '/api/auth/me');
    assert.equal(res.status, 200);
    assert.equal(res.body.user, null);
  });

  it('registers, stays signed in, and signs out', async () => {
    const call = client();
    const { username } = await signUp(call);

    const me = await call('GET', '/api/auth/me');
    assert.equal(me.body.user.username, username);

    await call('POST', '/api/auth/logout');
    const after = await call('GET', '/api/auth/me');
    assert.equal(after.body.user, null);
  });

  it('rejects a duplicate username', async () => {
    const call = client();
    const { username, password } = await signUp(call);

    const again = await client()('POST', '/api/auth/register', { username, password });
    assert.equal(again.status, 409);
  });

  it('rejects a duplicate username differing only in case', async () => {
    const call = client();
    const { username, password } = await signUp(call);

    const shouty = await client()(
      'POST', '/api/auth/register', { username: username.toUpperCase(), password }
    );
    assert.equal(shouty.status, 409);
  });

  it('rejects short passwords and odd usernames', async () => {
    const call = client();
    assert.equal(
      (await call('POST', '/api/auth/register', { username: 'ok.name', password: 'short' })).status,
      400
    );
    assert.equal(
      (await call('POST', '/api/auth/register', { username: 'no spaces', password: 'long-enough' })).status,
      400
    );
  });

  it('gives the same answer for a wrong password and an unknown user', async () => {
    const call = client();
    const { username } = await signUp(call);

    const wrongPassword = await client()(
      'POST', '/api/auth/login', { username, password: 'not-the-password' }
    );
    const unknownUser = await client()(
      'POST', '/api/auth/login', { username: 'nobody.at.all', password: 'not-the-password' }
    );

    assert.equal(wrongPassword.status, 401);
    assert.equal(unknownUser.status, 401);
    assert.equal(wrongPassword.body.error, unknownUser.body.error);
  });

  it('logs back in with the right password', async () => {
    const { username, password } = await signUp(client());

    const call = client();
    const login = await call('POST', '/api/auth/login', { username, password });
    assert.equal(login.status, 200);
    assert.equal((await call('GET', '/api/auth/me')).body.user.username, username);
  });

  it('refuses a forged session cookie', async () => {
    const res = await fetch(`${baseUrl}/api/entries`, {
      headers: { Cookie: 'gj_session=definitely-not-a-real-session' },
    });
    assert.equal(res.status, 401);
  });
});

describe('entries', () => {
  it('needs an account', async () => {
    const call = client();
    assert.equal((await call('GET', '/api/entries')).status, 401);
    assert.equal((await call('POST', '/api/entries', entry('a', 'x'))).status, 401);
  });

  it('creates, lists and deletes', async () => {
    const call = client();
    await signUp(call);

    const created = await call('POST', '/api/entries', entry('e1', 'sunshine', 'tea'));
    assert.equal(created.status, 201);
    assert.deepEqual(created.body.entry.items, ['sunshine', 'tea']);

    const list = await call('GET', '/api/entries');
    assert.equal(list.body.entries.length, 1);
    assert.deepEqual(list.body.entries[0].items, ['sunshine', 'tea']);

    assert.equal((await call('DELETE', '/api/entries/e1')).status, 200);
    assert.equal((await call('GET', '/api/entries')).body.entries.length, 0);
  });

  it('keeps the order of the lines within an entry', async () => {
    const call = client();
    await signUp(call);
    await call('POST', '/api/entries', entry('ordered', 'first', 'second', 'third'));

    const [saved] = (await call('GET', '/api/entries')).body.entries;
    assert.deepEqual(saved.items, ['first', 'second', 'third']);
  });

  it('lists newest first', async () => {
    const call = client();
    await signUp(call);

    await call('POST', '/api/entries', { id: 'old', date: '2020-01-01T00:00:00.000Z', items: ['old'] });
    await call('POST', '/api/entries', { id: 'new', date: '2026-06-01T00:00:00.000Z', items: ['new'] });

    const ids = (await call('GET', '/api/entries')).body.entries.map(e => e.id);
    assert.deepEqual(ids, ['new', 'old']);
  });

  it('drops blank lines and rejects an entry with nothing in it', async () => {
    const call = client();
    await signUp(call);

    const mixed = await call('POST', '/api/entries', entry('mixed', 'kept', '   ', ''));
    assert.deepEqual(mixed.body.entry.items, ['kept']);

    assert.equal((await call('POST', '/api/entries', entry('blank', '  '))).status, 400);
    assert.equal((await call('POST', '/api/entries', { items: 'not an array' })).status, 400);
  });

  it("won't delete another account's entry", async () => {
    const alice = client();
    const bob = client();
    await signUp(alice);
    await signUp(bob);

    await alice('POST', '/api/entries', entry('alices-entry', 'her tea'));

    assert.equal((await bob('DELETE', '/api/entries/alices-entry')).status, 404);
    assert.equal((await alice('GET', '/api/entries')).body.entries.length, 1);
  });

  it("won't show one account another's entries", async () => {
    const alice = client();
    const bob = client();
    await signUp(alice);
    await signUp(bob);

    await alice('POST', '/api/entries', entry('private', 'a secret'));
    assert.deepEqual((await bob('GET', '/api/entries')).body.entries, []);
  });
});

describe('bulk import', () => {
  it('adopts guest entries', async () => {
    const call = client();
    await signUp(call);

    const res = await call('POST', '/api/entries/bulk', {
      entries: [entry('g1', 'one'), entry('g2', 'two')],
    });

    assert.equal(res.body.added, 2);
    assert.equal(res.body.entries.length, 2);
  });

  it('is idempotent — re-importing the same file adds nothing', async () => {
    const call = client();
    await signUp(call);
    const payload = { entries: [entry('dup', 'again and again')] };

    assert.equal((await call('POST', '/api/entries/bulk', payload)).body.added, 1);

    const second = await call('POST', '/api/entries/bulk', payload);
    assert.equal(second.body.added, 0);
    assert.equal(second.body.entries.length, 1);
  });

  it('survives the same id twice inside one payload', async () => {
    const call = client();
    await signUp(call);

    const res = await call('POST', '/api/entries/bulk', {
      entries: [entry('twin', 'first copy'), entry('twin', 'second copy')],
    });

    assert.equal(res.status, 200);
    assert.equal(res.body.added, 1);
    assert.equal(res.body.entries.length, 1);
  });

  /* The reason entry ids are unique per account rather than globally: two
     people importing the same export file must not collide. */
  it('lets two accounts import the same export file', async () => {
    const alice = client();
    const bob = client();
    await signUp(alice);
    await signUp(bob);

    const exported = { entries: [entry('shared-id', 'a shared memory')] };

    assert.equal((await alice('POST', '/api/entries/bulk', exported)).body.added, 1);

    const bobsImport = await bob('POST', '/api/entries/bulk', exported);
    assert.equal(bobsImport.status, 200);
    assert.equal(bobsImport.body.added, 1);
    assert.equal(bobsImport.body.entries.length, 1);
  });

  it('round-trips: what the API returns can be imported back unchanged', async () => {
    const call = client();
    await signUp(call);
    await call('POST', '/api/entries', entry('trip', 'the original'));

    const exported = (await call('GET', '/api/entries')).body.entries;
    const reimported = await call('POST', '/api/entries/bulk', { entries: exported });

    assert.equal(reimported.body.added, 0);
    assert.equal(reimported.body.entries.length, 1);
  });

  it('skips malformed entries instead of failing the whole import', async () => {
    const call = client();
    await signUp(call);

    const res = await call('POST', '/api/entries/bulk', {
      entries: [entry('good', 'real'), null, { items: [] }, 'nonsense'],
    });

    assert.equal(res.body.added, 1);
  });

  it('rejects a payload that is not a list', async () => {
    const call = client();
    await signUp(call);
    assert.equal((await call('POST', '/api/entries/bulk', { entries: 'nope' })).status, 400);
  });
});

describe('the app itself', () => {
  it('answers the healthcheck', async () => {
    const res = await fetch(`${baseUrl}/healthz`);
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { ok: true });
  });

  it('serves the journal at the root', async () => {
    const res = await fetch(baseUrl);
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type'), /text\/html/);
    assert.match(await res.text(), /Gratitude Journal/);
  });

  it('answers unknown paths with JSON rather than HTML', async () => {
    const res = await fetch(`${baseUrl}/api/nothing-here`);
    assert.equal(res.status, 404);
    assert.equal((await res.json()).error, 'Not found.');
  });

  it('answers malformed JSON with 400, not 500', async () => {
    const res = await fetch(`${baseUrl}/api/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{ this is not json',
    });
    assert.equal(res.status, 400);
  });
});
