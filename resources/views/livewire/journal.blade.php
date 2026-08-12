<div>
    {{-- The three lines. Whether anything has been typed is worked out in the
         browser: asking the server after every keystroke, only to grey out a
         button, would be a round trip per character. --}}
    <section
        class="card"
        aria-label="New entry"
        x-data="{ blank: true }"
        x-on:input="blank = [...$el.querySelectorAll('textarea')].every(t => t.value.trim() === '')"
        x-on:entry-saved.window="blank = true"
    >
        @foreach ($drafts as $index => $draft)
            <div class="field" wire:key="draft-{{ $index }}">
                <label for="gratitude-{{ $index }}">{{ $index + 1 }}.</label>
                <textarea
                    id="gratitude-{{ $index }}"
                    placeholder="I'm grateful for..."
                    wire:model="drafts.{{ $index }}"
                    wire:keydown.ctrl.enter.prevent="save"
                    wire:keydown.meta.enter.prevent="save"
                ></textarea>
            </div>
        @endforeach

        <button
            type="button"
            class="btn btn-primary"
            wire:click="save"
            x-bind:disabled="blank"
        >Save entry</button>

        <p class="hint"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> saves</p>
    </section>

    @if ($this->entries->isEmpty())
        <p class="empty">Your first entry will appear here.</p>
    @endif

    <div class="history-bar">
        @if ($this->entries->isNotEmpty())
            <button
                type="button"
                class="btn btn-quiet"
                wire:click="toggleHistory"
                aria-expanded="{{ $showHistory ? 'true' : 'false' }}"
                aria-controls="history"
            >
                {{ $showHistory ? 'Hide past entries' : 'View past entries' }} ({{ $this->entries->count() }})
            </button>

            <a class="btn btn-icon" href="{{ route('journal.export') }}">Export</a>
        @endif

        {{-- A plain form post rather than a Livewire upload: no temporary upload
             directory to keep tidy, and it still works with JavaScript off. --}}
        <form
            method="POST"
            action="{{ route('journal.import') }}"
            enctype="multipart/form-data"
            x-data
            x-on:change="$el.submit()"
        >
            @csrf
            <button
                type="button"
                class="btn btn-icon"
                x-on:click="$refs.file.click()"
            >Import</button>
            <input
                x-ref="file"
                type="file"
                name="file"
                accept="application/json,.json"
                class="sr-only"
                aria-label="Import entries from a JSON file"
            >
        </form>
    </div>

    @error('file') <p class="form-error" role="alert">{{ $message }}</p> @enderror

    @if ($showHistory && $this->entries->isNotEmpty())
        <section class="history" id="history">
            <h2>Past entries</h2>

            @foreach ($this->entries as $entry)
                <article class="entry" wire:key="entry-{{ $entry->public_id }}">
                    <header>
                        <time datetime="{{ $entry->entry_date->toIso8601String() }}">
                            {{ $entry->display_date }}
                        </time>

                        @if ($confirming === $entry->public_id)
                            <div class="confirm">
                                <span>Delete this entry?</span>
                                <button
                                    type="button"
                                    class="btn btn-icon btn-danger"
                                    wire:click="delete(@js($entry->public_id))"
                                >Delete</button>
                                <button
                                    type="button"
                                    class="btn btn-icon"
                                    wire:click="confirm(null)"
                                >Keep</button>
                            </div>
                        @else
                            <button
                                type="button"
                                class="btn btn-icon"
                                wire:click="confirm(@js($entry->public_id))"
                            >Delete<span class="sr-only"> entry from {{ $entry->display_date }}</span></button>
                        @endif
                    </header>

                    <ol>
                        @foreach ($entry->items as $item)
                            <li>{{ $item->body }}</li>
                        @endforeach
                    </ol>
                </article>
            @endforeach
        </section>
    @endif
</div>
