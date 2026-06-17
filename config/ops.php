<?php

// Watched by app/Services/Ops/HealthReport.php for the nightly digest.
// This list mirrors the daily schedule in routes/console.php — keep them in
// sync. v1 supports cadence 'daily' only; periodic jobs (book:weekly,
// streaming:refresh-services) are deferred until their exact sync_type strings
// are confirmed against real log rows.
return [
    'digest' => [
        'to' => env('OPS_DIGEST_TO', 'tdikun@gmail.com'),
        'pipeline_stale_hours' => 26,
    ],

    // Each entry: which log table + sync_type to look up, a label, cadence.
    'watch' => [
        ['key' => 'streaming',   'table' => 'streaming_sync_log', 'type' => 'pipeline',        'label' => 'Streaming pipeline',  'cadence' => 'daily'],
        ['key' => 'verify_kids', 'table' => 'streaming_sync_log', 'type' => 'verify_kids',      'label' => 'Netflix Kids verify', 'cadence' => 'daily'],
        ['key' => 'book_enrich', 'table' => 'book_sync_log',      'type' => 'enrich',           'label' => 'Book enrich',         'cadence' => 'daily'],
    ],
];
