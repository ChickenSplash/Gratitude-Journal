<?php

namespace App\Providers;

use App\Support\Assets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::shouldBeStrict($this->app->isLocal());

        Blade::directive(
            'journalAsset',
            fn (string $expression) => '<?php echo e('.Assets::class."::url({$expression})); ?>"
        );

        $this->mountLivewireUnderPrefix();
    }

    /**
     * Livewire serves its JavaScript and takes its component updates from paths
     * of its own choosing, at the root of the site. Everything the browser asks
     * for has to sit under the app's prefix — a tunnel may only forward that one
     * path here — so both are re-registered where they can actually be reached.
     */
    private function mountLivewireUnderPrefix(): void
    {
        $prefix = config('journal.prefix');

        Livewire::setUpdateRoute(
            fn ($handle) => Route::post("/{$prefix}/livewire/update", $handle)
                ->name('journal.livewire.update')
        );

        Livewire::setScriptRoute(
            fn ($handle) => Route::get("/{$prefix}/livewire/livewire.js", $handle)
                ->name('journal.livewire.script')
        );
    }
}
