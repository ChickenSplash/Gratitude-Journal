<?php

namespace App\Http\Controllers;

use App\Support\ExportFile;
use App\Support\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adds the entries from an export file, skipping any already in the account.
 *
 * The counterpart to ExportController, and a plain form post for the same
 * reasons: no temporary upload directory to keep tidy, and it works without
 * JavaScript.
 */
class ImportController extends Controller
{
    public function __invoke(Request $request, Journal $journal): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:application/json,text/plain'],
        ], attributes: ['file' => 'file']);

        try {
            $incoming = ExportFile::parse($request->file('file')->get());
        } catch (\JsonException) {
            return back()->with('toast', "Couldn't read that file — is it a journal export?");
        }

        $added = $journal->import($request->user(), $incoming);

        return back()->with('toast', match (true) {
            $incoming === [] => 'No entries found in that file.',
            $added === 0 => 'Nothing new in that file.',
            default => "Imported {$added} ".($added === 1 ? 'entry.' : 'entries.'),
        });
    }
}
