<?php

namespace App\Support;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every read and write an account makes against its own entries.
 *
 * Each method takes the user it acts for and scopes on them, so a public id
 * guessed from another account can never reach a row it doesn't own.
 */
class Journal
{
    /**
     * @return Collection<int, Entry>
     */
    public function history(User $user): Collection
    {
        return $user->entries()->with('items')->newestFirst()->get();
    }

    /**
     * One transaction per entry: an entry with no lines must never exist.
     *
     * @param  list<string>  $items
     */
    public function record(User $user, array $items): Entry
    {
        return DB::transaction(function () use ($user, $items) {
            $entry = $user->entries()->create([
                'public_id' => (string) Str::uuid(),
                'entry_date' => now(),
            ]);

            $this->writeItems($entry, $items);

            return $entry->load('items');
        });
    }

    public function forget(User $user, string $publicId): bool
    {
        return $user->entries()->where('public_id', $publicId)->delete() > 0;
    }

    /**
     * Adds entries read out of an export file, skipping any this account
     * already holds, so re-importing the same file is a no-op rather than a
     * second copy of everything.
     *
     * @param  list<array{id: string, date: Carbon, items: list<string>}>  $incoming
     * @return int how many were new
     */
    public function import(User $user, array $incoming): int
    {
        if ($incoming === []) {
            return 0;
        }

        return DB::transaction(function () use ($user, $incoming) {
            $existing = $user->entries()
                ->whereIn('public_id', array_column($incoming, 'id'))
                ->pluck('public_id')
                ->flip();

            $added = 0;

            foreach ($incoming as $candidate) {
                if ($existing->has($candidate['id'])) {
                    continue;
                }

                $entry = $user->entries()->create([
                    'public_id' => $candidate['id'],
                    'entry_date' => $candidate['date'],
                ]);

                $this->writeItems($entry, $candidate['items']);
                $added++;
            }

            return $added;
        });
    }

    /**
     * The whole journal in the shape the importer expects back.
     *
     * @return list<array{id: string, date: string, items: list<string>}>
     */
    public function export(User $user): array
    {
        return $this->history($user)
            ->map(fn (Entry $entry) => $entry->toJournalArray())
            ->all();
    }

    /**
     * @param  list<string>  $items
     */
    private function writeItems(Entry $entry, array $items): void
    {
        $entry->items()->createMany(
            array_map(
                fn (int $position, string $body) => compact('position', 'body'),
                array_keys($items),
                array_values($items),
            )
        );
    }
}
