<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the stylesheet and the theme script.
 *
 * They come through a route rather than sitting in public/ because the app is
 * mounted at /gratitude-journal, and a directory of that name inside public/
 * would shadow the page itself — the web server would try to list the folder
 * instead of handing the request to Laravel.
 *
 * The cost is one PHP request per asset per release: Assets::url() stamps the
 * modification time into the query string, so the response can be cached hard
 * and an edited file simply arrives under a new URL.
 */
class AssetController extends Controller
{
    private const TYPES = [
        'app.css' => 'text/css',
        'app.js' => 'text/javascript',
    ];

    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        abort_unless(isset(self::TYPES[$file]), 404);

        $response = response()->file(resource_path("assets/{$file}"), [
            'Content-Type' => self::TYPES[$file].'; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        // Turns a repeat visit into a 304 with no body, for the case where the
        // browser has the file but revalidates anyway.
        $response->setAutoEtag()->isNotModified($request);

        return $response;
    }
}
