<!DOCTYPE html>
<html lang="en-GB" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $title ?? 'Gratitude Journal' }}</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Lora:ital,wght@0,400;1,400&display=swap" rel="stylesheet">

<link rel="stylesheet" href="@journalAsset('app.css')">

{{-- Inline and first, so the stored theme is on <html> before anything paints
     and a dark-mode visitor never sees a flash of the light palette.

     `data-theme` above is only the no-JavaScript default. It is what the browser
     is left with after a wire:navigate swap too, since this script is in the
     head of every page and so is not re-run; app.js re-applies the preference
     when that happens. --}}
<script>
  try {
    var stored = localStorage.getItem('gratitudeTheme');
    document.documentElement.dataset.theme = (stored === 'light' || stored === 'dark')
      ? stored
      : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  } catch (e) {}
</script>

<script src="@journalAsset('app.js')" defer></script>
</head>

<body>
<div class="shell">
    <div class="topbar">
        <span class="who">
            @auth
                Signed in as <strong>{{ auth()->user()->name }}</strong>
            @endauth
        </span>

        @auth
            <form method="POST" action="{{ route('journal.logout') }}">
                @csrf
                <button type="submit" class="btn btn-icon">Sign out</button>
            </form>
        @endauth

        <button type="button" class="btn btn-icon" data-theme-toggle aria-pressed="false">☾ Dark</button>
    </div>

    <header class="masthead">
        <h1>Gratitude Journal</h1>
        <p>What are you grateful for today?</p>
    </header>

    {{-- Announced to screen readers without stealing focus. Messages arrive
         either as a session flash or as a `toast` browser event from Livewire;
         either way app.js clears them after a few seconds. --}}
    <div role="status" aria-live="polite" data-toast-region data-toast="{{ session('toast') }}"></div>

    {{ $slot }}
</div>

@livewireScripts
</body>
</html>
