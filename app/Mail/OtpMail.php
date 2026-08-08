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

    public function __construct(
        public string $code,
        public string $purpose,
        public string $recipientName = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'reset_password'
                ? 'Reset your ReBox password'
                : 'Verify your ReBox account',
        );
    }

    public function content(): Content
    {
        $isReset = $this->purpose === 'reset_password';
        $name = trim($this->recipientName);
        $intro = $isReset
            ? ($name !== ''
                ? "Hi {$name}, use this one-time code to reset your password."
                : 'Use this one-time code to reset your password.')
            : ($name !== ''
                ? "Hi {$name}, use this one-time code to verify your email."
                : 'Use this one-time code to verify your email.');

        return new Content(
            htmlString: $this->htmlBody(
                title: $isReset ? 'Password reset code' : 'Verify your email',
                intro: $intro,
                outro: 'This code expires in 10 minutes.',
            ),
        );
    }

    protected function htmlBody(string $title, string $intro, string $outro): string
    {
        $safeTitle = e($title);
        $safeIntro = e($intro);
        $safeOutro = e($outro);
        $safeCode = e($this->code);

        return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f1a17;background:#fff8f5;border:1px solid #f0ddd7;border-radius:16px;">
  <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#9b4a3c;font-weight:700;">ReBox</p>
  <h1 style="margin:0 0 12px;font-size:22px;">{$safeTitle}</h1>
  <p style="margin:0 0 20px;line-height:1.5;color:#6b5e58;">{$safeIntro}</p>
  <div style="display:inline-block;padding:14px 22px;border-radius:12px;background:#9b4a3c;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:0.35em;">
    {$safeCode}
  </div>
  <p style="margin:20px 0 0;line-height:1.5;color:#6b5e58;font-size:14px;">{$safeOutro}</p>
  <p style="margin:24px 0 0;font-size:12px;color:#9a8c85;">If you did not request this, you can ignore this email.</p>
</div>
HTML;
    }
}
