<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads the JSON an export produces back into entries.
 *
 * The file is untrusted — it is whatever the user picked off their disk — so
 * anything malformed is dropped rather than allowed to reach the database.
 * A row's own id is kept when it looks sane, which is what makes re-importing
 * the same file land on the entries it already created instead of duplicating
 * them.
 */
class ExportFile
{
    /**
     * @return list<array{id: string, date: Carbon, items: list<string>}>
     *
     * @throws \JsonException when the contents are not JSON at all
     */
    public static function parse(string $contents): array
    {
        $parsed = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($parsed)) {
            return [];
        }

        $entries = [];
        $seen = [];

        foreach (array_slice($parsed, 0, config('journal.max_import')) as $raw) {
            $entry = self::clean($raw);

            // Two rows sharing an id would violate the per-account unique index,
            // so the first one wins and the rest of the file still imports.
            if ($entry === null || isset($seen[$entry['id']])) {
                continue;
            }

            $seen[$entry['id']] = true;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array{id: string, date: Carbon, items: list<string>}|null
     */
    private static function clean(mixed $raw): ?array
    {
        if (! is_array($raw) || ! isset($raw['items']) || ! is_array($raw['items'])) {
            return null;
        }

        $items = [];

        foreach ($raw['items'] as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $items[] = Str::limit(trim($item), config('journal.max_item_length'), end: '');

            if (count($items) === config('journal.max_items')) {
                break;
            }
        }

        if ($items === []) {
            return null;
        }

        return [
            'id' => self::cleanId($raw['id'] ?? null),
            'date' => self::cleanDate($raw['date'] ?? null),
            'items' => $items,
        ];
    }

    private static function cleanId(mixed $id): string
    {
        return is_string($id) && $id !== '' && strlen($id) <= 64
            ? $id
            : (string) Str::uuid();
    }

    private static function cleanDate(mixed $date): Carbon
    {
        if (! is_string($date)) {
            return now();
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return now();
        }
    }
}
