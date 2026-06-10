<?php

namespace App\Console\Commands\Concerns;

trait ValidatesHoursOption
{
    /**
     * Validate the --hours option as an integer within [1, $maxHours].
     * Prints an error and returns null when invalid; callers should exit
     * with self::INVALID so exit code 2 consistently means "bad input"
     * across the streaming commands.
     */
    protected function validatedHoursOption(int $maxHours = 720): ?int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);

        if ($hours === false || $hours < 1 || $hours > $maxHours) {
            $this->error(sprintf(
                '--hours must be an integer between 1 and %d, got "%s".',
                $maxHours,
                $this->option('hours')
            ));

            return null;
        }

        return $hours;
    }
}
