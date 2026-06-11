<?php

namespace App\Services\BookLibrary;

use RuntimeException;

/**
 * NYT Books API returned 429. Commands honor it by stopping the run cleanly:
 * persist the cursor, complete the sync log with metadata.exhausted=false.
 */
class NytRateLimitedException extends RuntimeException {}
