<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Read API tokens
    |--------------------------------------------------------------------------
    |
    | Comma-separated name:token pairs, e.g. "mnsa:abc,sponge:def,admin:ghi".
    | The name identifies the consumer in logs; one consumer can be rotated
    | or revoked without touching the others.
    |
    */

    'read_tokens' => env('LIBRARY_READ_TOKENS', ''),

];
