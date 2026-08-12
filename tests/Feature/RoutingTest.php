<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The app is reached through a tunnel that may forward one path prefix and
 * nothing else, so every URL the browser is given has to start with it.
 */
class RoutingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_url_the_page_hands_the_browser_sits_under_the_prefix(): void
    {
        $prefix = '/'.config('journal.prefix');

        $html = $this->actingAs(User::factory()->create())
            ->get(route('journal.home'))
            ->assertOk()
            ->content();

        preg_match_all('~(?:href|src|action)="([^"]+)"~', $html, $matches);

        $local = array_filter(
            array_unique($matches[1]),
            fn (string $url) => ! str_starts_with($url, 'https://fonts.')
        );

        $this->assertNotEmpty($local, 'Expected the page to reference at least one local URL.');

        foreach ($local as $url) {
            $this->assertStringStartsWith(
                $prefix,
                parse_url($url, PHP_URL_PATH) ?? '',
                "{$url} would never reach the container."
            );
        }
    }

    #[Test]
    public function livewire_takes_its_updates_under_the_prefix(): void
    {
        $this->assertStringStartsWith(
            '/'.config('journal.prefix'),
            Livewire::getUpdateUri()
        );
    }

    #[Test]
    public function the_stylesheet_and_script_are_served_and_cacheable(): void
    {
        foreach (['app.css' => 'text/css', 'app.js' => 'text/javascript'] as $file => $type) {
            $this->get(route('journal.asset', ['file' => $file]))
                ->assertOk()
                ->assertHeader('Content-Type', $type.'; charset=utf-8')
                ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        }
    }

    #[Test]
    public function the_asset_route_serves_nothing_but_those_two_files(): void
    {
        $this->get(route('journal.asset', ['file' => 'app.php']))->assertNotFound();
    }

    #[Test]
    public function the_health_check_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
