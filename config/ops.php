<?php

// Watched by app/Services/Ops/HealthReport.php for the nightly digest.
// This list mirrors the daily schedule in routes/console.php — keep them in
// sync: when the temporary book seed/enrich backfill is removed there, drop the
// matching entries here too. v1 supports cadence 'daily' only; periodic jobs
// (book:weekly, streaming:refresh-services) are deferred until their exact
// sync_type strings are confirmed against real log rows.
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
        ['key' => 'book_seed',   'table' => 'book_sync_log',      'type' => 'seed_nyt_history', 'label' => 'Book seed (NYT hist)','cadence' => 'daily'],
    ],
];
