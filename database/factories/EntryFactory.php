<?php

namespace Database\Factories;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Entry>
 */
class EntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'public_id' => (string) Str::uuid(),
            'entry_date' => now(),
        ];
    }

    /**
     * @param  list<string>  $items
     */
    public function withItems(array $items): static
    {
        return $this->afterCreating(function (Entry $entry) use ($items) {
            $entry->items()->createMany(
                array_map(
                    fn (int $position, string $body) => compact('position', 'body'),
                    array_keys($items),
                    array_values($items),
                )
            );
        });
    }
}
