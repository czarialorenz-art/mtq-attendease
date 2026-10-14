<?php

namespace App\Support;

use App\Mail\SecurityAlertMail;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Six-digit email codes (sign-up and email changes) and security alert emails.
 * Codes are stored hashed, expire after 15 minutes and allow 5 wrong tries.
 */
class EmailVerification
{
    public const MINUTES = 15;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 60;

    /** Seconds until another code may be sent (0 = now). */
    public static function cooldown(User $user): int
    {
        if (! $user->verification_sent_at) {
            return 0;
        }

        return max(0, self::RESEND_SECONDS - (int) $user->verification_sent_at->diffInSeconds(now()));
    }

    /** Creates a new code and emails it to $to. Throws if the email can't be sent. */
    public static function send(User $user, string $to, string $purpose): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'verification_code' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(self::MINUTES),
            'verification_sent_at' => now(),
            'verification_attempts' => 0,
        ])->save();

        Mail::to($to)->send(new VerificationCodeMail($user->name, $code, $purpose, self::MINUTES));
    }

    /** Checks a code; returns null when correct, otherwise the message to show. Clears the code on success. */
    public static function check(User $user, string $code): ?string
    {
        if (! $user->verification_code || ! $user->verification_expires_at || $user->verification_expires_at->isPast()) {
            return 'This code has expired. Tap "Send a new code".';
        }
        if ($user->verification_attempts >= self::MAX_ATTEMPTS) {
            return 'Too many wrong codes. Tap "Send a new code".';
        }
        if (! preg_match('/^\d{6}$/', trim($code)) || ! Hash::check(trim($code), $user->verification_code)) {
            $user->increment('verification_attempts');
            $left = self::MAX_ATTEMPTS - $user->verification_attempts;

            return $left > 0 ? "That code is incorrect. {$left} tries left." : 'Too many wrong codes. Tap "Send a new code".';
        }

        $user->forceFill(['verification_code' => null, 'verification_expires_at' => null, 'verification_attempts' => 0])->save();

        return null;
    }

    /** Security notice to the account owner. Never blocks the action if the email fails. */
    public static function alert(string $to, string $name, string $subject, string $message, ?Request $request = null): void
    {
        try {
            Mail::to($to)->send(new SecurityAlertMail($name, $subject, $message, [
                'Time' => now('Asia/Manila')->format('M j, Y g:i A') . ' (Philippine time)',
                'IP address' => $request?->ip(),
                'Device' => $request ? mb_strimwidth((string) $request->userAgent(), 0, 160, '…') : null,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Security alert email failed: ' . $e->getMessage());
        }
    }
}
