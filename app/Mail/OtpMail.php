<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $type;
    public ?string $userName;

    public function __construct(string $otp, string $type, ?string $userName = null)
    {
        $this->otp = $otp;
        $this->type = $type;
        $this->userName = $userName;
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'password_reset' => 'رمز إعادة تعيين كلمة المرور — تراكسيرا',
            'email_verification' => 'رمز تأكيد البريد الإلكتروني — تراكسيرا',
        ];

        return new Envelope(
            subject: $subjects[$this->type] ?? 'رمز التحقق — تراكسيرا',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
        );
    }
}
