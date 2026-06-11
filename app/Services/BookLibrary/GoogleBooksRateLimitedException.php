<?php

namespace App\Services\BookLibrary;

use RuntimeException;

/**
 * Google Books quota exhaustion: HTTP 429 (after retries) or a 403 whose body
 * reason is rateLimitExceeded. The authoritative stop signal for book:enrich —
 * the run ends cleanly and resumes next schedule. Any other failure stays a
 * plain RuntimeException and fails the run loudly.
 */
class GoogleBooksRateLimitedException extends RuntimeException {}
