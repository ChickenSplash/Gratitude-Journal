<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LogoutController;
use App\Livewire\AuthPanel;
use App\Livewire\Journal;
use Illuminate\Support\Facades\Route;

$prefix = config('journal.prefix');

// Nothing lives at the root: the app is mounted under its prefix so a tunnel
// can forward that one path to this container. See config/journal.php.
Route::redirect('/', "/{$prefix}");

Route::prefix($prefix)->name('journal.')->group(function () {
    // Before the auth groups: the sign-in page needs the stylesheet too.
    Route::get('/assets/{file}', AssetController::class)->name('asset');

    Route::middleware('guest')->group(function () {
        Route::get('/login', AuthPanel::class)->name('login');
        Route::get('/register', AuthPanel::class)->name('register');
    });

    Route::middleware('auth')->group(function () {
        Route::get('/', Journal::class)->name('home');
        Route::get('/export', ExportController::class)->name('export');
        Route::post('/import', ImportController::class)->name('import');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});
