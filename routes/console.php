<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mail:test {to}', function (string $to) {
    $mailer = config('mail.default');
    $this->line("Mailer: {$mailer}   From: " . config('mail.from.address'));

    if ($mailer === 'log') {
        $this->warn('MAIL_MAILER=log: the email is only written to storage/logs/laravel.log, not delivered.');
    }

    try {
        Mail::raw("This is a test from MTQ-ATTENDEASE. If you can read this, verification codes and security alerts will be delivered.",
            fn ($m) => $m->to($to)->subject('MTQ-ATTENDEASE test email'));
    } catch (\Throwable $e) {
        $this->error('Sending failed: ' . $e->getMessage());
        $msg = $e->getMessage();
        if (str_contains($msg, '535') || str_contains($msg, 'Username and Password not accepted')) {
            $this->line('Hint: Google rejected the login. MAIL_PASSWORD must be a 16-character App Password (not the normal password), and MAIL_USERNAME the full address.');
        } elseif (str_contains($msg, 'Connection could not be established') || str_contains($msg, 'timed out')) {
            $this->line('Hint: could not reach smtp.gmail.com:587. Check the internet connection or a firewall/antivirus blocking port 587.');
        }

        return 1;
    }

    $this->info($mailer === 'log'
        ? "Written to storage/logs/laravel.log (not delivered). Set MAIL_MAILER=smtp to send real emails."
        : "Sent to {$to}. Check that inbox (and Spam).");

    return 0;
})->purpose('Send a test email to check the mail settings');
