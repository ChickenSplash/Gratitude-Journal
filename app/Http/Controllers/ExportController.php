<?php

namespace App\Http\Controllers;

use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The whole journal as the JSON file the importer reads back.
 *
 * A plain download rather than anything Livewire does, so it survives having
 * JavaScript switched off and can be scripted with curl and a session cookie.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request, Journal $journal): JsonResponse
    {
        $filename = 'gratitude-journal-'.now()->format('Y-m-d').'.json';

        return response()->json(
            $journal->export($request->user()),
            headers: [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store',
            ],
            options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
