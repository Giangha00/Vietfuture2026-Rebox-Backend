<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\JwtService;
use App\Services\OtpService;
use App\Support\Serializers;
use App\Support\Validators;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        protected JwtService $jwt,
        protected OtpService $otp,
        protected AdminNotificationService $adminNotifications,
    ) {}

    public function register(Request $request)
    {
        $fullName = trim((string) $request->input('fullName', ''));
        $email = Validators::normalizeEmail($request->input('email'));
        $phone = Validators::normalizePhone($request->input('phone'));
        $password = (string) $request->input('password', '');

        if (mb_strlen($fullName) < 2) {
            return response()->json(['message' => 'Full name must be at least 2 characters.'], 400);
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Valid email is required.'], 400);
        }
        if (! Validators::isValidPhone($phone)) {
            return response()->json(['message' => 'Phone number must be exactly 10 digits.'], 400);
        }
        if (! Validators::isValidPassword($password)) {
            return response()->json([
                'message' => 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.',
            ], 400);
        }
        if (User::query()->where('email', $email)->exists()) {
            return response()->json(['message' => 'Email already registered.'], 409);
        }

        $user = User::query()->create([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
            'role' => 'user',
            'avatar_url' => '/default-avatar.svg',
            'bio' => '',
            'email_verified' => false,
            'delivery_address' => Validators::emptyAddress(),
            'pickup_address' => Validators::emptyAddress(),
            'fcm_tokens' => [],
        ]);

        $otp = $this->otp->issue($email, 'verify_email');
        $this->adminNotifications->userRegistered($user);

        return response()->json([
            'message' => 'Register successful. Please verify your email with the OTP we sent.',
            'needsVerification' => true,
            'email' => $email,
            'expiresInSeconds' => $otp['expiresInSeconds'],
            'emailConfigured' => $otp['emailConfigured'],
            'debugCode' => $otp['debugCode'],
            'user' => Serializers::user($user),
        ], 201);
    }

    public function login(Request $request)
    {
        $email = Validators::normalizeEmail($request->input('email'));
        $password = (string) $request->input('password', '');
        if (! $email || ! $password) {
            return response()->json(['message' => 'Email and password are required.'], 400);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user || ! Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (! $user->email_verified) {
            $otp = $this->otp->issue($email, 'verify_email');

            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'needsVerification' => true,
                'email' => $email,
                'expiresInSeconds' => $otp['expiresInSeconds'],
                'emailConfigured' => $otp['emailConfigured'],
                'debugCode' => $otp['debugCode'],
            ], 403);
        }

        return response()->json([
            'message' => 'Login successful.',
            'token' => $this->jwt->sign($user->id),
            'user' => Serializers::user($user),
        ]);
    }

    public function verifyEmail(Request $request)
    {
        $email = Validators::normalizeEmail($request->input('email'));
        $code = (string) ($request->input('otp') ?? $request->input('code') ?? '');
        if (! $email || ! $code) {
            return response()->json(['message' => 'Email and OTP are required.'], 400);
        }

        $result = $this->otp->verify($email, $code, 'verify_email', true);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 400);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->email_verified = true;
        $user->email_verified_at = now();
        $user->save();

        return response()->json([
            'message' => 'Email verified.',
            'token' => $this->jwt->sign($user->id),
            'user' => Serializers::user($user),
        ]);
    }

    public function resendVerification(Request $request)
    {
        $email = Validators::normalizeEmail($request->input('email'));
        if (! $email) {
            return response()->json(['message' => 'Email is required.'], 400);
        }
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        if ($user->email_verified) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        $otp = $this->otp->issue($email, 'verify_email');

        return response()->json([
            'message' => 'Verification OTP resent.',
            'email' => $email,
            'expiresInSeconds' => $otp['expiresInSeconds'],
            'emailConfigured' => $otp['emailConfigured'],
            'debugCode' => $otp['debugCode'],
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $email = Validators::normalizeEmail($request->input('email'));
        if (! $email) {
            return response()->json(['message' => 'Email is required.'], 400);
        }

        $user = User::query()->where('email', $email)->first();
        $payload = [
            'message' => 'If that email exists, an OTP has been sent.',
            'email' => $email,
            'expiresInSeconds' => OtpService::TTL_SECONDS,
            'emailConfigured' => $this->otp->isEmailConfigured(),
            'debugCode' => null,
        ];

        if ($user) {
            $otp = $this->otp->issue($email, 'reset_password');
            $payload['expiresInSeconds'] = $otp['expiresInSeconds'];
            $payload['emailConfigured'] = $otp['emailConfigured'];
            $payload['debugCode'] = $otp['debugCode'];
        }

        return response()->json($payload);
    }

    public function verifyResetOtp(Request $request)
    {
        $email = Validators::normalizeEmail($request->input('email'));
        $code = (string) ($request->input('otp') ?? $request->input('code') ?? '');
        $result = $this->otp->verify($email, $code, 'reset_password', false);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 400);
        }

        return response()->json([
            'message' => 'OTP verified.',
            'email' => $email,
            'verified' => true,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $email = Validators::normalizeEmail($request->input('email'));
        $code = (string) ($request->input('otp') ?? $request->input('code') ?? '');
        $password = (string) ($request->input('password') ?? $request->input('newPassword') ?? '');

        if (! Validators::isValidPassword($password)) {
            return response()->json([
                'message' => 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.',
            ], 400);
        }

        $result = $this->otp->verify($email, $code, 'reset_password', true);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 400);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->password = $password;
        if (! $user->email_verified) {
            $user->email_verified = true;
            $user->email_verified_at = now();
        }
        $user->save();

        return response()->json([
            'message' => 'Password reset successful.',
            'email' => $email,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => Serializers::user($request->attributes->get('authUser')),
        ]);
    }

    public function updateMe(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');

        if ($request->has('fullName')) {
            $fullName = trim((string) $request->input('fullName'));
            if ($fullName === '') {
                return response()->json(['message' => 'Full name cannot be empty.'], 400);
            }
            $user->full_name = $fullName;
        }
        if ($request->has('phone')) {
            $phone = Validators::normalizePhone($request->input('phone'));
            if (! Validators::isValidPhone($phone)) {
                return response()->json(['message' => 'Phone number must be exactly 10 digits.'], 400);
            }
            $user->phone = $phone;
        }
        if ($request->has('bio')) {
            $user->bio = mb_substr(trim((string) $request->input('bio')), 0, 280);
        }
        if ($request->has('avatarUrl')) {
            $user->avatar_url = (string) $request->input('avatarUrl');
        }
        if ($request->has('deliveryAddress')) {
            $addr = Validators::normalizeAddress($request->input('deliveryAddress'));
            if (($addr['line1'] || $addr['city']) && ! Validators::isValidPhone($addr['phone'])) {
                return response()->json(['message' => 'Delivery phone must be exactly 10 digits.'], 400);
            }
            $user->delivery_address = $addr;
        }
        if ($request->has('pickupAddress')) {
            $addr = Validators::normalizeAddress($request->input('pickupAddress'));
            if (($addr['line1'] || $addr['city']) && ! Validators::isValidPhone($addr['phone'])) {
                return response()->json(['message' => 'Pickup phone must be exactly 10 digits.'], 400);
            }
            $user->pickup_address = $addr;
        }

        $emailChanged = false;
        if ($request->has('email')) {
            $email = Validators::normalizeEmail($request->input('email'));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['message' => 'Valid email is required.'], 400);
            }
            if ($email !== $user->email) {
                if (User::query()->where('email', $email)->where('id', '!=', $user->id)->exists()) {
                    return response()->json(['message' => 'Email already registered.'], 409);
                }
                $user->email = $email;
                $user->email_verified = false;
                $user->email_verified_at = null;
                $emailChanged = true;
            }
        }

        $user->save();

        $payload = [
            'message' => 'Profile updated.',
            'user' => Serializers::user($user->fresh()),
        ];

        if ($emailChanged) {
            $otp = $this->otp->issue($user->email, 'verify_email');
            $payload['needsVerification'] = true;
            $payload['expiresInSeconds'] = $otp['expiresInSeconds'];
            $payload['emailConfigured'] = $otp['emailConfigured'];
            $payload['debugCode'] = $otp['debugCode'];
        }

        return response()->json($payload);
    }
}
