<?php

namespace Database\Seeders;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * A signed-in account with a few days behind it, for looking at the history
 * view without typing one entry at a time.
 *
 * Refuses to run in production: it creates an account with a password printed
 * in this file, which is fine on a laptop and nowhere else.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('This seeder makes a demo account with a known password. Not in production.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Human', 'password' => 'password', 'email_verified_at' => now()],
        );

        $days = [
            ['Sunshine through the kitchen window', 'Coffee that was still hot', 'A quiet morning'],
            ['A walk that took longer than planned', 'Someone laughed at my joke'],
            ['Finished a thing I had been avoiding', 'Rain on the window', 'Nowhere to be'],
        ];

        foreach ($days as $ago => $items) {
            Entry::factory()
                ->for($user)
                ->withItems($items)
                ->create(['entry_date' => now()->subDays($ago)]);
        }

        $this->command->info('Signed-in demo: demo@example.com / password');
    }
}
