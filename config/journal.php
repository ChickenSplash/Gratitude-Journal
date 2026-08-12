<?php

return [

    /*
    |---------------------------------------------------------------------------
    | URL prefix
    |---------------------------------------------------------------------------
    |
    | Every route the browser touches lives under this path — the pages, the
    | Livewire update endpoint, and the static CSS/JS. That is deliberate: the
    | app sits behind a Cloudflare Tunnel that may forward a single path prefix
    | rather than a whole hostname, and anything served outside the prefix would
    | never reach the container.
    |
    */

    'prefix' => 'gratitude-journal',

    /*
    |---------------------------------------------------------------------------
    | Entry shape
    |---------------------------------------------------------------------------
    |
    | `slots` is how many gratitude lines the composer offers each day. The rest
    | are the limits applied to anything arriving from an import file, which is
    | the only place entries are not typed into those slots.
    |
    */

    'slots' => 3,

    /*
    |---------------------------------------------------------------------------
    | The Express version's database
    |---------------------------------------------------------------------------
    |
    | Where journal:import-legacy looks unless it is given a path. The app that
    | came before this one wrote /data/journal.sqlite, and its tables collide
    | with the ones Laravel migrates — same names, different shapes — so this
    | version deliberately writes a differently named file and never opens that
    | one except to read entries out of it.
    |
    */

    'legacy_database' => env('JOURNAL_LEGACY_DATABASE', '/data/journal.sqlite'),

    'max_items' => 10,

    'max_item_length' => 2000,

    'max_import' => 1000,

];
