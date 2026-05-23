<?php

namespace App\Mail;

use App\Models\User;
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
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Reset your {$appName} password",
        );
    }

    public function content(): Content
    {
        $link = rtrim((string) config('app.url'), '/')
            .'/reset?token='.$this->token
            .'&email='.urlencode($this->user->email);

        return new Content(
            view: 'emails.password-reset',
            with: [
                'user' => $this->user,
                'link' => $link,
            ],
        );
    }
}
