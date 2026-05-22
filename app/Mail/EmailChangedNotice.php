<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangedNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $newEmail,
        public Brand $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->brand->mailFromAddress, $this->brand->mailFromName),
            subject: "Your {$this->brand->name} email was changed",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-changed-notice',
            with: [
                'user' => $this->user,
                'brand' => $this->brand,
                'newEmailMasked' => $this->mask($this->newEmail),
            ],
        );
    }

    private function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $len = mb_strlen($local);
        // Show at most the first character; always emit at least 3 stars so
        // short locals are never disclosed in full.
        $shown = $len > 1 ? mb_substr($local, 0, 1) : '';
        return $shown . str_repeat('*', max(3, $len - 1)) . '@' . $domain;
    }
}
