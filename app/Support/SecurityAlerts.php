<?php

namespace App\Support;

use App\Mail\SecurityAlertMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Security notices emailed to an account owner (new sign-in, password or email changed). */
class SecurityAlerts
{
    /** Never blocks the action if the email can't be sent. */
    public static function send(string $to, string $name, string $subject, string $message, ?Request $request = null): void
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
