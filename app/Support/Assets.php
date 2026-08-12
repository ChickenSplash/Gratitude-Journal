<?php

namespace App\Support;

/**
 * URLs for the two static files the page loads.
 *
 * There is no build step and so no fingerprinted filenames; the modification
 * time goes in the query string instead, which is what lets AssetController
 * answer with a year-long cache lifetime.
 */
class Assets
{
    public static function url(string $file): string
    {
        return route('journal.asset', [
            'file' => $file,
            'v' => @filemtime(resource_path("assets/{$file}")) ?: 0,
        ]);
    }
}
