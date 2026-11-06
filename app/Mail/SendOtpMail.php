<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $otp,
        public string $purpose = 'REGISTRATION',
        public int $expiresInMinutes = 5
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = ($this->purpose === 'FORGOT_PASSWORD')
            ? 'Reset Your NubiBilling Password'
            : 'Verify Your NubiBilling Account';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'otp' => $this->otp,
                'purpose' => $this->purpose,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
