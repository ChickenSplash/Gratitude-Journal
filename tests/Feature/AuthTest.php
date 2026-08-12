<?php

namespace Tests\Feature;

use App\Livewire\AuthPanel;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_redirects_into_the_app(): void
    {
        $this->get('/')->assertRedirect('/gratitude-journal');
    }

    #[Test]
    public function a_guest_is_sent_to_the_sign_in_page(): void
    {
        $this->get('/gratitude-journal')->assertRedirect(route('journal.login'));
    }

    #[Test]
    public function the_sign_in_page_renders_both_tabs(): void
    {
        $this->get(route('journal.login'))
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('Create account');
    }

    #[Test]
    public function the_register_route_opens_on_the_register_tab(): void
    {
        Livewire::test(AuthPanel::class, ['mode' => 'register'])
            ->assertSet('mode', 'register')
            ->assertSee('At least 8 characters');
    }

    #[Test]
    public function registering_creates_an_account_and_signs_in(): void
    {
        Event::fake([Registered::class]);

        Livewire::test(AuthPanel::class, ['mode' => 'register'])
            ->set('name', 'Grateful Human')
            ->set('email', 'grateful@example.com')
            ->set('password', 'a-good-password')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('journal.home'));

        $this->assertDatabaseHas('users', [
            'name' => 'Grateful Human',
            'email' => 'grateful@example.com',
        ]);

        $this->assertAuthenticated();

        // What Laravel's own verification mail hangs off, once it's switched on.
        Event::assertDispatched(Registered::class);
    }

    #[Test]
    public function registering_rejects_a_short_password(): void
    {
        Livewire::test(AuthPanel::class, ['mode' => 'register'])
            ->set('name', 'Grateful Human')
            ->set('email', 'grateful@example.com')
            ->set('password', 'short')
            ->call('submit')
            ->assertHasErrors(['password' => 'min']);

        $this->assertGuest();
    }

    #[Test]
    public function registering_rejects_an_email_already_in_use(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(AuthPanel::class, ['mode' => 'register'])
            ->set('name', 'Someone Else')
            ->set('email', 'taken@example.com')
            ->set('password', 'a-good-password')
            ->call('submit')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertGuest();
    }

    #[Test]
    public function signing_in_with_the_right_password_works(): void
    {
        $user = User::factory()->create(['email' => 'grateful@example.com']);

        Livewire::test(AuthPanel::class, ['mode' => 'login'])
            ->set('email', 'grateful@example.com')
            ->set('password', 'password')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('journal.home'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_wrong_password_says_the_same_thing_as_a_missing_account(): void
    {
        User::factory()->create(['email' => 'grateful@example.com']);

        $wrongPassword = Livewire::test(AuthPanel::class, ['mode' => 'login'])
            ->set('email', 'grateful@example.com')
            ->set('password', 'not-the-password')
            ->call('submit')
            ->assertHasErrors('email');

        $noSuchUser = Livewire::test(AuthPanel::class, ['mode' => 'login'])
            ->set('email', 'nobody@example.com')
            ->set('password', 'not-the-password')
            ->call('submit')
            ->assertHasErrors('email');

        $this->assertSame(
            $wrongPassword->errors()->first('email'),
            $noSuchUser->errors()->first('email'),
            'The two failures must be indistinguishable, or the form enumerates accounts.'
        );

        $this->assertGuest();
    }

    #[Test]
    public function repeated_failures_are_throttled(): void
    {
        User::factory()->create(['email' => 'grateful@example.com']);

        foreach (range(1, 8) as $ignored) {
            Livewire::test(AuthPanel::class, ['mode' => 'login'])
                ->set('email', 'grateful@example.com')
                ->set('password', 'not-the-password')
                ->call('submit');
        }

        // Even the right password is refused once the limit is hit.
        Livewire::test(AuthPanel::class, ['mode' => 'login'])
            ->set('email', 'grateful@example.com')
            ->set('password', 'password')
            ->call('submit')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function signing_out_returns_to_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('journal.logout'))
            ->assertRedirect(route('journal.login'));

        $this->assertGuest();
    }

    #[Test]
    public function a_signed_in_visitor_is_kept_away_from_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('journal.login'))
            ->assertRedirect(route('journal.home'));
    }
}
