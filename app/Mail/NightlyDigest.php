<?php

namespace App\Mail;

use App\Services\Ops\HealthReport;
use App\Services\Ops\JobHealth;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NightlyDigest extends Mailable
{
    public function __construct(public HealthReport $report) {}

    public function envelope(): Envelope
    {
        $emoji = JobHealth::emojiFor($this->report->overall);
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
