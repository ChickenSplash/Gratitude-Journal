<?php

namespace Tests\Feature;

use App\Livewire\Journal;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_page_opens_with_one_blank_slot_per_configured_line(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Journal::class)
            ->assertSet('drafts', ['', '', ''])
            ->assertSee('Your first entry will appear here.', escape: false);
    }

    #[Test]
    public function saving_stores_the_lines_in_order_and_clears_the_form(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->set('drafts', ['Sunshine', 'Coffee', 'A quiet morning'])
            ->call('save')
            ->assertSet('drafts', ['', '', '']);

        $entry = $user->entries()->with('items')->sole();

        $this->assertSame(
            ['Sunshine', 'Coffee', 'A quiet morning'],
            $entry->items->pluck('body')->all()
        );
    }

    #[Test]
    public function blank_slots_are_dropped_rather_than_stored(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->set('drafts', ['  ', 'Coffee', ''])
            ->call('save');

        $this->assertSame(['Coffee'], $user->entries()->sole()->items->pluck('body')->all());
    }

    #[Test]
    public function an_entirely_blank_form_saves_nothing(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->set('drafts', ['', '   ', ''])
            ->call('save');

        $this->assertSame(0, $user->entries()->count());
    }

    #[Test]
    public function history_lists_entries_newest_first(): void
    {
        $user = User::factory()->create();

        Entry::factory()->for($user)->withItems(['Older'])->create(['entry_date' => now()->subDays(2)]);
        Entry::factory()->for($user)->withItems(['Newer'])->create(['entry_date' => now()]);

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->call('toggleHistory')
            ->assertSeeInOrder(['Newer', 'Older']);
    }

    #[Test]
    public function history_is_hidden_until_asked_for(): void
    {
        $user = User::factory()->create();
        Entry::factory()->for($user)->withItems(['A secret'])->create();

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->assertDontSee('A secret')
            ->assertSee('View past entries (1)')
            ->call('toggleHistory')
            ->assertSee('A secret')
            ->assertSee('Hide past entries (1)');
    }

    #[Test]
    public function deleting_takes_two_presses(): void
    {
        $user = User::factory()->create();
        $entry = Entry::factory()->for($user)->withItems(['Coffee'])->create();

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->call('toggleHistory')
            ->assertDontSee('Delete this entry?')
            ->call('confirm', $entry->public_id)
            ->assertSee('Delete this entry?')
            ->call('delete', $entry->public_id);

        $this->assertSame(0, $user->entries()->count());
        $this->assertDatabaseCount('entry_items', 0);
    }

    #[Test]
    public function one_account_cannot_delete_another_accounts_entry(): void
    {
        $mine = User::factory()->create();
        $theirs = Entry::factory()->for(User::factory())->withItems(['Not yours'])->create();

        Livewire::actingAs($mine)
            ->test(Journal::class)
            ->call('delete', $theirs->public_id);

        $this->assertDatabaseHas('entries', ['id' => $theirs->id]);
    }

    #[Test]
    public function history_only_ever_shows_your_own_entries(): void
    {
        $mine = User::factory()->create();
        Entry::factory()->for($mine)->withItems(['Mine'])->create();
        Entry::factory()->for(User::factory())->withItems(['Someone elses'])->create();

        Livewire::actingAs($mine)
            ->test(Journal::class)
            ->call('toggleHistory')
            ->assertSee('Mine')
            ->assertDontSee('Someone elses')
            ->assertSee('Hide past entries (1)');
    }

    #[Test]
    public function dates_read_as_today_yesterday_then_a_calendar_date(): void
    {
        $user = User::factory()->create();

        Entry::factory()->for($user)->withItems(['Now'])->create(['entry_date' => now()]);
        Entry::factory()->for($user)->withItems(['Then'])->create(['entry_date' => now()->subDay()]);
        Entry::factory()->for($user)->withItems(['Ages ago'])->create([
            'entry_date' => now()->subYears(2)->setDate(now()->year - 2, 3, 5),
        ]);

        Livewire::actingAs($user)
            ->test(Journal::class)
            ->call('toggleHistory')
            ->assertSee('Today')
            ->assertSee('Yesterday')
            ->assertSee('5 Mar '.(now()->year - 2));
    }
}
