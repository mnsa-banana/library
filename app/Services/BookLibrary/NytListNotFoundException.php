<?php

namespace App\Services\BookLibrary;

use RuntimeException;

/**
 * NYT 404 "list not found" — the slug is retired (pre-2015 split lists were
 * removed from the API entirely in mid-2026) or otherwise unknown. Callers
 * skip the list and continue rather than failing the whole run.
 */
class NytListNotFoundException extends RuntimeException {}
