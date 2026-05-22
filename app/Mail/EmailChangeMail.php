<?php

namespace App\Mail;

use App\Support\Brand;
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
        public Brand $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->brand->mailFromAddress, $this->brand->mailFromName),
            subject: "Confirm your new email for {$this->brand->name}",
        );
    }

    public function content(): Content
    {
        $link = $this->brand->spaOrigin()
            . '/email/confirm?id=' . $this->changeId
            . '&token=' . $this->token;

        return new Content(
            view: 'emails.email-change',
            with: [
                'brand' => $this->brand,
                'link' => $link,
            ],
        );
    }
}
