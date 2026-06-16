<?php

namespace App\Mail;

use App\Services\Ops\HealthReport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NightlyDigest extends Mailable
{
    public function __construct(public HealthReport $report) {}

    public function envelope(): Envelope
    {
        $emoji = match ($this->report->overall) {
            'ok' => '✅',
            'warn' => '⚠️',
            'fail' => '🔴',
            default => '❓',
        };
        $headline = match ($this->report->overall) {
            'ok' => 'all green',
            'warn' => 'incomplete',
            'fail' => 'needs attention',
            default => 'unknown',
        };

        return new Envelope(subject: "{$emoji} Imbuo Library nightly — {$headline}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.nightly-digest', with: ['report' => $this->report]);
    }
}
