<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Validators;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    public function isEmailConfigured(): bool
    {
        $username = (string) config('mail.mailers.smtp.username', '');
        $password = (string) config('mail.mailers.smtp.password', '');
        $from = (string) config('mail.from.address', '');

        return $username !== ''
            && $password !== ''
            && $from !== ''
            && ! str_contains($username, '${')
            && ! str_contains($from, '${');
    }

    public function issue(string $email, string $purpose): array
    {
        $email = Validators::normalizeEmail($email);
        OtpCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->delete();

        $code = (string) random_int(100000, 999999);
        OtpCode::query()->create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        $emailConfigured = $this->sendEmail($email, $code, $purpose);

        return [
            'expiresInSeconds' => self::TTL_SECONDS,
            'emailConfigured' => $emailConfigured,
            // Only expose OTP in API when mail is not working (local/dev fallback).
            'debugCode' => (! $emailConfigured && ! app()->isProduction()) ? $code : null,
        ];
    }

    public function verify(string $email, string $code, string $purpose, bool $consume = true): array
    {
        $email = Validators::normalizeEmail($email);
        $otp = OtpCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (! $otp) {
            return ['ok' => false, 'message' => 'OTP not found or already used.'];
        }
        if ($otp->expires_at->isPast()) {
            return ['ok' => false, 'message' => 'OTP has expired.'];
        }
        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => 'Too many invalid attempts.'];
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return ['ok' => false, 'message' => 'Invalid OTP.'];
        }

        if ($consume) {
            $otp->consumed_at = now();
            $otp->save();
        }

        return ['ok' => true];
    }

    protected function sendEmail(string $email, string $code, string $purpose): bool
    {
        if (! $this->isEmailConfigured()) {
            Log::info('OTP (SMTP not configured)', [
                'email' => $email,
                'purpose' => $purpose,
                'code' => app()->isProduction() ? '[redacted]' : $code,
            ]);

            return false;
        }

        $fullName = (string) (User::query()->where('email', $email)->value('full_name') ?? '');

        try {
            Mail::to($email)->send(new OtpMail($code, $purpose, $fullName));

            return true;
        } catch (\Throwable $e) {
            Log::warning('OTP email failed: '.$e->getMessage(), [
                'email' => $email,
                'purpose' => $purpose,
            ]);

            return false;
        }
    }
}
