<?php

// Watched by app/Services/Ops/HealthReport.php for the nightly digest.
// This list mirrors the schedule in routes/console.php — keep them in sync.
// Cadences 'daily', 'weekly', and 'monthly' are supported; each watch entry's
// staleness window is derived from its cadence via 'cadence_stale_hours' below,
// so a healthy periodic run never false-alarms as stale.
return [
    'digest' => [
        'to' => env('OPS_DIGEST_TO', 'tdikun@gmail.com'),
        'pipeline_stale_hours' => 26,
        // A job is "stale" (→ fail) if its last completed run is older than this, per cadence.
        // Weekly/monthly get a full cadence period + slack so a healthy periodic run never false-alarms.
        'cadence_stale_hours' => [
            'daily' => 26,     // ~1 day + slack
            'weekly' => 192,   // 8 days (7-day cadence + 1-day slack)
            'monthly' => 768,  // 32 days (31-day cadence + slack)
        ],
    ],

    // Each entry: which log table + sync_type to look up, a label, cadence.
    'watch' => [
        ['key' => 'streaming',       'table' => 'streaming_sync_log', 'type' => 'pipeline',          'label' => 'Streaming pipeline',  'cadence' => 'daily'],
        ['key' => 'verify_kids',     'table' => 'streaming_sync_log', 'type' => 'verify_kids',        'label' => 'Netflix Kids verify', 'cadence' => 'daily'],
        ['key' => 'book_enrich',     'table' => 'book_sync_log',      'type' => 'enrich',             'label' => 'Book enrich',         'cadence' => 'daily'],
        ['key' => 'discover_netflix', 'table' => 'streaming_sync_log', 'type' => 'discover_netflix',  'label' => 'Netflix Kids discover', 'cadence' => 'weekly'],
        ['key' => 'tmdb_backstop',    'table' => 'streaming_sync_log', 'type' => 'tmdb_backstop',     'label' => 'TMDB backstop',         'cadence' => 'monthly'],
    ],
];
