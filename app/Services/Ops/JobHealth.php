<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;

final class JobHealth
{
    public function __construct(
        public string $key,
        public string $label,
        public string $verdict,   // 'ok' | 'warn' | 'fail'
        public string $summary,
        public ?Carbon $lastRun = null,
    ) {}

    public function emoji(): string
    {
        return match ($this->verdict) {
            'ok' => '✅',
            'warn' => '⚠️',
            'fail' => '🔴',
            default => '❓',
        };
    }
}
