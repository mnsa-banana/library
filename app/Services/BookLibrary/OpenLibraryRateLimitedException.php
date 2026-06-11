<?php

namespace App\Services\BookLibrary;

use RuntimeException;

/**
 * Open Library returned 429 on every retry. Commands honor it by stopping the
 * run cleanly (mirrors GoogleBooksRateLimitedException / NytRateLimitedException):
 * the interrupted row stays unstamped and is picked up on the next run. Any
 * other failure stays a plain RuntimeException and fails the run loudly.
 */
class OpenLibraryRateLimitedException extends RuntimeException {}
