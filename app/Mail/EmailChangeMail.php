<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $newEmail,
        public string $token,
        public int $changeId,
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Confirm your new email for {$appName}",
        );
    }

    public function content(): Content
    {
        $link = rtrim((string) config('app.url'), '/')
            .'/email/confirm?id='.$this->changeId
            .'&token='.$this->token;

        return new Content(
            view: 'emails.email-change',
            with: ['link' => $link],
        );
    }
}
