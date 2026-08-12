<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Sign in and create account, sharing one panel the way the original did.
 * The two tabs are separate routes rather than local state, so a refresh or
 * the back button lands where you'd expect.
 */
class AuthPanel extends Component
{
    public string $mode = 'login';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public function mount(?string $mode = null): void
    {
        $this->mode = $mode ?? (request()->routeIs('journal.register') ? 'register' : 'login');
    }

    public function registering(): bool
    {
        return $this->mode === 'register';
    }

    /**
     * Signing in only needs the credentials to be present — how long a password
     * has to be is the registration form's business, and enforcing it here would
     * tell an attacker which guesses were never worth trying.
     */
    protected function rules(): array
    {
        if (! $this->registering()) {
            return [
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['email' => 'email address'];
    }

    public function submit(): void
    {
        $this->validate();

        $this->registering() ? $this->register() : $this->login();
    }

    private function register(): void
    {
        $user = User::create([
            'name' => trim($this->name),
            'email' => $this->email,
            'password' => $this->password,
        ]);

        // Nothing listens for this yet. It is what Laravel's own verification
        // mail hangs off, so leaving it in place keeps switching verification on
        // a config change rather than a code change — see the README.
        event(new Registered($user));

        Auth::login($user, remember: true);

        $this->finish("Welcome, {$user->name}.");
    }

    private function login(): void
    {
        // Keyed on email *and* IP, so one account being hammered from elsewhere
        // can't lock its owner out.
        $key = 'login:'.strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 8)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'email' => "Too many attempts. Try again in {$minutes} minutes.",
            ]);
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];

        if (! Auth::attempt($credentials, remember: true)) {
            RateLimiter::hit($key, decaySeconds: 15 * 60);

            // The same message either way, so this can't be used to work out
            // which email addresses have accounts.
            throw ValidationException::withMessages([
                'email' => 'Wrong email address or password.',
            ]);
        }

        RateLimiter::clear($key);

        $this->finish('Signed in as '.Auth::user()->name.'.');
    }

    private function finish(string $message): void
    {
        // Guards against session fixation: whatever id an attacker managed to
        // plant before sign-in stops being the one that is now authenticated.
        Session::regenerate();
        Session::flash('toast', $message);

        $this->redirectRoute('journal.home', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth-panel');
    }
}
