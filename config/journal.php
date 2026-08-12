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

    'max_items' => 10,

    'max_item_length' => 2000,

    'max_import' => 1000,

];
