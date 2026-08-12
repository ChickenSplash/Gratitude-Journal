<?php

namespace App\Livewire;

use App\Models\Entry;
use App\Support\Journal as JournalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The whole signed-in page: today's three lines, and everything written before.
 */
class Journal extends Component
{
    /** @var array<int, string> */
    public array $drafts = [];

    public bool $showHistory = false;

    /** The entry whose delete button has been pressed once. */
    public ?string $confirming = null;

    public function mount(): void
    {
        $this->drafts = $this->blankDrafts();
    }

    /**
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return app(JournalService::class)->history(Auth::user());
    }

    /**
     * The drafts with the blank ones dropped — what would actually be saved.
     *
     * @return list<string>
     */
    #[Computed]
    public function filled(): array
    {
        return array_values(array_filter(array_map('trim', $this->drafts), fn ($line) => $line !== ''));
    }

    public function save(): void
    {
        if ($this->filled === []) {
            return;
        }

        app(JournalService::class)->record(Auth::user(), $this->filled);

        $this->drafts = $this->blankDrafts();
        unset($this->entries, $this->filled);

        $this->toast('Entry saved.');
        $this->dispatch('entry-saved');
    }

    public function delete(string $publicId): void
    {
        $this->confirming = null;

        if (app(JournalService::class)->forget(Auth::user(), $publicId)) {
            unset($this->entries);
            $this->toast('Entry deleted.');
        }
    }

    public function confirm(?string $publicId): void
    {
        $this->confirming = $publicId;
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
        $this->confirming = null;
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', message: $message);
    }

    /**
     * @return array<int, string>
     */
    private function blankDrafts(): array
    {
        return array_fill(0, (int) config('journal.slots'), '');
    }

    public function render()
    {
        return view('livewire.journal');
    }
}
