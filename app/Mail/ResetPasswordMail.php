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

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
        public Brand $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->brand->mailFromAddress, $this->brand->mailFromName),
            subject: "Reset your {$this->brand->name} password",
        );
    }

    public function content(): Content
    {
        $link = $this->brand->spaOrigin()
            . '/reset?token=' . $this->token
            . '&email=' . urlencode($this->user->email);

        return new Content(
            view: 'emails.password-reset',
            with: [
                'user' => $this->user,
                'brand' => $this->brand,
                'link' => $link,
            ],
        );
    }
}
