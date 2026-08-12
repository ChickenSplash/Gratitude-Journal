<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading entries out of the database the Express version wrote.
 *
 * The fixture below is that app's schema verbatim, so a change to it here is a
 * change to what the command claims it can read.
 */
class ImportLegacyJournalTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'legacy').'.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-shm', '-wal'] as $suffix) {
            @unlink($this->path.$suffix);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, list<array{public_id: string, date: string, items: list<string>}>>  $byUsername
     */
    private function legacyDatabase(array $byUsername): void
    {
        $db = new \PDO('sqlite:'.$this->path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $db->exec('CREATE TABLE users (
            id TEXT PRIMARY KEY,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE sessions (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE entries (
            id INTEGER PRIMARY KEY,
            user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            public_id TEXT NOT NULL,
            entry_date TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_entries_user_public ON entries(user_id, public_id)');
        $db->exec('CREATE TABLE entry_items (
            entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
            position INTEGER NOT NULL,
            body TEXT NOT NULL,
            PRIMARY KEY (entry_id, position)
        )');

        $entryId = 0;

        foreach ($byUsername as $username => $entries) {
            $userId = "u-{$username}";

            $db->prepare('INSERT INTO users VALUES (?, ?, ?, ?)')
                ->execute([$userId, $username, 'scrypt$16384$8$1$c2FsdA==$aGFzaA==', '2026-01-01 09:00:00']);

            foreach ($entries as $entry) {
                $entryId++;

                $db->prepare('INSERT INTO entries VALUES (?, ?, ?, ?, ?)')
                    ->execute([$entryId, $userId, $entry['public_id'], $entry['date'], '2026-01-01 09:00:00']);

                foreach ($entry['items'] as $position => $body) {
                    $db->prepare('INSERT INTO entry_items VALUES (?, ?, ?)')
                        ->execute([$entryId, $position, $body]);
                }
            }
        }
    }

    #[Test]
    public function it_copies_entries_into_the_named_account(): void
    {
        $this->legacyDatabase(['grateful.human' => [
            ['public_id' => 'pub-1', 'date' => '2026-08-10T09:00:00.000Z', 'items' => ['Sunshine', 'Coffee']],
            ['public_id' => 'pub-2', 'date' => '2026-08-11T09:00:00.000Z', 'items' => ['A quiet morning']],
        ]]);

        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('journal:import-legacy', ['path' => $this->path, '--into' => 'me@example.com'])
            ->expectsOutputToContain('Imported 2 of 2 entries into me@example.com.')
            ->assertSuccessful();

        $entries = $user->entries()->with('items')->newestFirst()->get();

        $this->assertSame(['pub-2', 'pub-1'], $entries->pluck('public_id')->all());
        $this->assertSame(['Sunshine', 'Coffee'], $entries->last()->items->pluck('body')->all());
        $this->assertSame('2026-08-10', $entries->last()->entry_date->format('Y-m-d'));
    }

    #[Test]
    public function running_it_twice_adds_nothing_the_second_time(): void
    {
        $this->legacyDatabase(['grateful.human' => [
            ['public_id' => 'pub-1', 'date' => '2026-08-10T09:00:00.000Z', 'items' => ['Sunshine']],
        ]]);

        $user = User::factory()->create(['email' => 'me@example.com']);
        $arguments = ['path' => $this->path, '--into' => 'me@example.com'];

        $this->artisan('journal:import-legacy', $arguments)->assertSuccessful();
        $this->artisan('journal:import-legacy', $arguments)
            ->expectsOutputToContain('Imported 0 of 1 entries into me@example.com. 1 were already there.')
            ->assertSuccessful();

        $this->assertSame(1, $user->entries()->count());
    }

    #[Test]
    public function it_takes_only_the_legacy_account_it_was_pointed_at(): void
    {
        $this->legacyDatabase([
            'grateful.human' => [['public_id' => 'mine', 'date' => '2026-08-10T09:00:00.000Z', 'items' => ['Mine']]],
            'someone.else' => [['public_id' => 'theirs', 'date' => '2026-08-10T09:00:00.000Z', 'items' => ['Theirs']]],
        ]);

        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('journal:import-legacy', [
            'path' => $this->path,
            '--into' => 'me@example.com',
            '--from' => 'grateful.human',
        ])->assertSuccessful();

        $this->assertSame(['Mine'], $user->entries()->sole()->items->pluck('body')->all());
    }

    #[Test]
    public function it_refuses_to_guess_when_the_old_database_holds_several_accounts(): void
    {
        $this->legacyDatabase([
            'grateful.human' => [['public_id' => 'a', 'date' => '2026-08-10T09:00:00.000Z', 'items' => ['One']]],
            'someone.else' => [['public_id' => 'b', 'date' => '2026-08-10T09:00:00.000Z', 'items' => ['Two']]],
        ]);

        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('journal:import-legacy', ['path' => $this->path, '--into' => 'me@example.com'])
            ->expectsOutputToContain('more than one account')
            ->assertFailed();

        $this->assertDatabaseCount('entries', 0);
    }

    #[Test]
    public function it_says_so_when_the_account_does_not_exist_yet(): void
    {
        $this->legacyDatabase(['grateful.human' => []]);

        $this->artisan('journal:import-legacy', ['path' => $this->path, '--into' => 'nobody@example.com'])
            ->expectsOutputToContain('Create one on the site first')
            ->assertFailed();
    }

    #[Test]
    public function it_says_so_when_the_file_is_not_a_legacy_journal(): void
    {
        (new \PDO('sqlite:'.$this->path))->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');

        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('journal:import-legacy', ['path' => $this->path, '--into' => 'me@example.com'])
            ->expectsOutputToContain("doesn't look like a journal from the Express version")
            ->assertFailed();
    }

    #[Test]
    public function it_says_so_when_there_is_no_file(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('journal:import-legacy', [
            'path' => '/nowhere/journal.sqlite',
            '--into' => 'me@example.com',
        ])->expectsOutputToContain('No such file')->assertFailed();
    }
}
