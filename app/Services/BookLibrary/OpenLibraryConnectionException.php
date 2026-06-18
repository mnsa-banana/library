<?php

namespace App\Services\BookLibrary;

use RuntimeException;

/**
 * Open Library was unreachable (DNS/connect/read timeout) on every retry — a
 * transient external outage rather than a logic error. Commands honor it by
 * stopping the run cleanly (mirrors OpenLibraryRateLimitedException): the
 * interrupted row stays unstamped and is picked up on the next run, so a
 * passing blip never paints the nightly digest red.
 */
class OpenLibraryConnectionException extends RuntimeException {}
