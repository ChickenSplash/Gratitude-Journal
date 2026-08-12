<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Journal;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves entries out of the database the Express version wrote.
 *
 * That app kept /data/journal.sqlite, whose tables share their names with the
 * ones Laravel migrates but not their shape, so the two can't live in one file.
 * This version writes gratitude-journal.sqlite instead and leaves the old file
 * alone — which also means both can be open at once, which is what makes this
 * command possible.
 *
 * Accounts are not carried across. The old passwords are scrypt hashes in a
 * bespoke format, and the old accounts had usernames where these have email
 * addresses; there is nothing to verify against and nowhere to send mail. So
 * the flow is: create an account through the site, then point this at it.
 */
class ImportLegacyJournal extends Command
{
    protected $signature = 'journal:import-legacy
                            {path? : The old journal.sqlite (defaults to config journal.legacy_database)}
                            {--into= : Email address of the account to import into}
                            {--from= : Legacy username, when the old database holds more than one}';

    protected $description = "Copy entries out of the Express version's database into an account";

    public function handle(Journal $journal): int
    {
        $path = $this->argument('path') ?? config('journal.legacy_database');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $user = $this->targetAccount();

        if ($user === null) {
            return self::FAILURE;
        }

        $legacy = $this->openLegacy($path);

        if ($legacy === null) {
            return self::FAILURE;
        }

        $legacyUserId = $this->legacyUserId($legacy);

        if ($legacyUserId === null) {
            return self::FAILURE;
        }

        $entries = $this->readEntries($legacy, $legacyUserId);

        if ($entries === []) {
            $this->warn('That account has no entries in the old database.');

            return self::SUCCESS;
        }

        // Journal::import() skips public_ids the account already holds, so
        // running this twice adds nothing the second time.
        $added = $journal->import($user, $entries);
        $skipped = count($entries) - $added;

        $this->info("Imported {$added} of ".count($entries)." entries into {$user->email}."
            .($skipped > 0 ? " {$skipped} were already there." : ''));

        return self::SUCCESS;
    }

    private function targetAccount(): ?User
    {
        $email = $this->option('into');

        if (! $email) {
            $this->error('Which account? Pass --into=you@example.com.');

            return null;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No account with the email {$email}. Create one on the site first, then run this again.");
        }

        return $user;
    }

    /**
     * @return Connection|null
     */
    private function openLegacy(string $path)
    {
        config([
            'database.connections.journal_legacy' => [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        $legacy = DB::connection('journal_legacy');

        foreach (['users', 'entries', 'entry_items'] as $table) {
            if (! $legacy->getSchemaBuilder()->hasTable($table)) {
                $this->error("{$path} doesn't look like a journal from the Express version — no {$table} table.");

                return null;
            }
        }

        return $legacy;
    }

    private function legacyUserId(Connection $legacy): ?string
    {
        $accounts = $legacy->table('users')->orderBy('username')->get(['id', 'username']);

        if ($accounts->isEmpty()) {
            $this->error('The old database has no accounts in it.');

            return null;
        }

        if ($username = $this->option('from')) {
            $match = $accounts->firstWhere('username', $username);

            if (! $match) {
                $this->error("No legacy account called {$username}. It holds: ".$accounts->pluck('username')->join(', '));

                return null;
            }

            return $match->id;
        }

        if ($accounts->count() > 1) {
            $this->error('That database holds more than one account. Pick one with --from='
                .$accounts->pluck('username')->join(' / --from='));

            return null;
        }

        $this->line("Reading the legacy account <info>{$accounts[0]->username}</info>.");

        return $accounts[0]->id;
    }

    /**
     * @return list<array{id: string, date: Carbon, items: list<string>}>
     */
    private function readEntries(Connection $legacy, string $userId): array
    {
        $rows = $legacy->table('entries as e')
            ->join('entry_items as i', 'i.entry_id', '=', 'e.id')
            ->where('e.user_id', $userId)
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('i.position')
            ->get(['e.public_id', 'e.entry_date', 'i.body']);

        $entries = [];

        foreach ($rows as $row) {
            if (! isset($entries[$row->public_id])) {
                $entries[$row->public_id] = [
                    'id' => $row->public_id,
                    'date' => $this->parseDate($row->entry_date),
                    'items' => [],
                ];
            }

            $entries[$row->public_id]['items'][] = $row->body;
        }

        return array_values($entries);
    }

    private function parseDate(mixed $value): Carbon
    {
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }
}
