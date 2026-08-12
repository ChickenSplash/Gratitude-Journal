<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function upload(mixed $contents, string $name = 'journal.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            is_string($contents) ? $contents : json_encode($contents)
        );
    }

    #[Test]
    public function export_returns_the_journal_newest_first(): void
    {
        $user = User::factory()->create();

        Entry::factory()->for($user)->withItems(['Older'])->create([
            'public_id' => 'older',
            'entry_date' => now()->subDay(),
        ]);
        Entry::factory()->for($user)->withItems(['Newer', 'Also newer'])->create([
            'public_id' => 'newer',
            'entry_date' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('journal.export'));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="gratitude-journal-'.now()->format('Y-m-d').'.json"');

        $payload = $response->json();

        $this->assertSame(['newer', 'older'], array_column($payload, 'id'));
        $this->assertSame(['Newer', 'Also newer'], $payload[0]['items']);
    }

    #[Test]
    public function export_never_includes_another_accounts_entries(): void
    {
        $user = User::factory()->create();
        Entry::factory()->for(User::factory())->withItems(['Not yours'])->create();

        $this->actingAs($user)->get(route('journal.export'))->assertOk()->assertExactJson([]);
    }

    #[Test]
    public function import_adds_the_entries_in_the_file(): void
    {
        $user = User::factory()->create();

        $file = $this->upload([
            ['id' => 'a', 'date' => '2026-01-02T09:00:00.000Z', 'items' => ['Sunshine', 'Coffee']],
            ['id' => 'b', 'date' => '2026-01-03T09:00:00.000Z', 'items' => ['A quiet morning']],
        ]);

        $this->actingAs($user)
            ->post(route('journal.import'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('toast', 'Imported 2 entries.');

        $this->assertSame(2, $user->entries()->count());
        $this->assertDatabaseHas('entry_items', ['body' => 'Sunshine']);
    }

    #[Test]
    public function importing_the_same_file_twice_adds_nothing_the_second_time(): void
    {
        $user = User::factory()->create();
        $entries = [['id' => 'a', 'date' => '2026-01-02T09:00:00.000Z', 'items' => ['Sunshine']]];

        $this->actingAs($user)->post(route('journal.import'), ['file' => $this->upload($entries)]);

        $this->actingAs($user)
            ->post(route('journal.import'), ['file' => $this->upload($entries)])
            ->assertSessionHas('toast', 'Nothing new in that file.');

        $this->assertSame(1, $user->entries()->count());
    }

    #[Test]
    public function two_accounts_can_import_the_same_export(): void
    {
        $entries = [['id' => 'a', 'date' => '2026-01-02T09:00:00.000Z', 'items' => ['Sunshine']]];

        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)
                ->post(route('journal.import'), ['file' => $this->upload($entries)])
                ->assertSessionHas('toast', 'Imported 1 entry.');

            $this->assertSame(1, $user->entries()->count());
        }
    }

    #[Test]
    public function an_export_round_trips_through_the_importer(): void
    {
        $author = User::factory()->create();
        Entry::factory()->for($author)->withItems(['Sunshine', 'Coffee'])->create();
        Entry::factory()->for($author)->withItems(['A quiet morning'])->create([
            'entry_date' => now()->subDay(),
        ]);

        $exported = $this->actingAs($author)->get(route('journal.export'))->content();

        $reader = User::factory()->create();
        $this->actingAs($reader)
            ->post(route('journal.import'), ['file' => $this->upload($exported)])
            ->assertSessionHas('toast', 'Imported 2 entries.');

        $this->assertSame(
            $this->actingAs($author)->get(route('journal.export'))->json(),
            $this->actingAs($reader)->get(route('journal.export'))->json(),
        );
    }

    #[Test]
    public function a_file_that_is_not_json_is_reported_rather_than_thrown(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('journal.import'), ['file' => $this->upload('not json at all')])
            ->assertRedirect()
            ->assertSessionHas('toast', "Couldn't read that file — is it a journal export?");
    }

    #[Test]
    public function malformed_entries_are_dropped_rather_than_stored(): void
    {
        $user = User::factory()->create();

        $file = $this->upload([
            'nonsense',
            ['items' => 'not a list'],
            ['items' => []],
            ['items' => ['', '   ']],
            ['id' => 'ok', 'date' => 'not a date', 'items' => ['Kept', 42, null]],
        ]);

        $this->actingAs($user)
            ->post(route('journal.import'), ['file' => $file])
            ->assertSessionHas('toast', 'Imported 1 entry.');

        $this->assertSame(['Kept'], $user->entries()->sole()->items->pluck('body')->all());
    }

    #[Test]
    public function an_empty_file_says_so(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('journal.import'), ['file' => $this->upload([])])
            ->assertSessionHas('toast', 'No entries found in that file.');
    }

    #[Test]
    public function a_file_repeating_one_id_imports_it_once(): void
    {
        $user = User::factory()->create();

        $file = $this->upload([
            ['id' => 'a', 'date' => '2026-01-02T09:00:00.000Z', 'items' => ['First']],
            ['id' => 'a', 'date' => '2026-01-03T09:00:00.000Z', 'items' => ['Duplicate']],
        ]);

        $this->actingAs($user)
            ->post(route('journal.import'), ['file' => $file])
            ->assertSessionHas('toast', 'Imported 1 entry.');

        $this->assertSame(['First'], $user->entries()->sole()->items->pluck('body')->all());
    }

    #[Test]
    public function import_and_export_are_closed_to_guests(): void
    {
        $this->get(route('journal.export'))->assertRedirect(route('journal.login'));
        $this->post(route('journal.import'))->assertRedirect(route('journal.login'));
    }
}
