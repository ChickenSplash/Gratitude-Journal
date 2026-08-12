<section class="card" aria-label="Account">
    <div class="tabs" role="tablist">
        <a
            role="tab"
            href="{{ route('journal.login') }}"
            aria-selected="{{ $this->registering() ? 'false' : 'true' }}"
            wire:navigate
        >Sign in</a>
        <a
            role="tab"
            href="{{ route('journal.register') }}"
            aria-selected="{{ $this->registering() ? 'true' : 'false' }}"
            wire:navigate
        >Create account</a>
    </div>

    <form wire:submit="submit">
        @if ($this->registering())
            <div class="field">
                <label for="name">Name</label>
                <input
                    id="name"
                    type="text"
                    autocomplete="name"
                    placeholder="Grateful Human"
                    wire:model="name"
                    required
                >
            </div>
        @endif

        <div class="field">
            <label for="email">Email address</label>
            <input
                id="email"
                type="email"
                autocomplete="{{ $this->registering() ? 'email' : 'username' }}"
                placeholder="you@example.com"
                wire:model="email"
                required
            >
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                id="password"
                type="password"
                autocomplete="{{ $this->registering() ? 'new-password' : 'current-password' }}"
                placeholder="{{ $this->registering() ? 'At least 8 characters' : '' }}"
                wire:model="password"
                required
            >
        </div>

        @error('name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        @error('email') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        @error('password') <p class="form-error" role="alert">{{ $message }}</p> @enderror

        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
            <span wire:loading.remove>{{ $this->registering() ? 'Create account' : 'Sign in' }}</span>
            <span wire:loading>One moment…</span>
        </button>
    </form>

    <p class="auth-note">
        @if ($this->registering())
            Your entries are stored on this server and only you can read them.
        @else
            No account yet?
            <a class="btn btn-quiet" href="{{ route('journal.register') }}" wire:navigate>Create one</a>
        @endif
    </p>
</section>
