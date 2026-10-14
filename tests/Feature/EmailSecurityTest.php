<?php

namespace Tests\Feature;

use App\Mail\SecurityAlertMail;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Runs on the MySQL copy of the schema: DB_CONNECTION=mysql DB_DATABASE=laravel_test php artisan test */
class EmailSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? '')) !== 'mysql') {
            $this->markTestSkipped('Needs the MySQL schema: DB_CONNECTION=mysql DB_DATABASE=laravel_test');
        }
        parent::setUp();
        Mail::fake();
    }

    private function lastCode(string $to): string
    {
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function ($m) use ($to, &$code) {
            if ($m->hasTo($to)) {
                $code = $m->code;
            }

            return true;
        });

        return $code;
    }

    private function register(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/register', [
            'name' => 'Eve Santos', 'email' => $email, 'password' => 'Secret#123', 'role' => 'student',
            'school_id' => 'E-' . uniqid(), 'course' => 'BSIT', 'year_level' => '1st Year', 'section' => 'A',
        ]);
    }

    public function test_new_account_must_verify_email_before_signing_in(): void
    {
        $email = 'eve' . uniqid() . '@mqcqc.ph.education';

        $this->register('not an email')->assertStatus(422);
        $this->register('eve@gmail.com')->assertStatus(422)->assertJsonPath('errors.email.0', 'Please use your school email (@mqcqc.ph.education).');

        $res = $this->register($email)->assertCreated()->assertJsonPath('verification_required', true)->json();
        $this->assertArrayNotHasKey('token', $res);
        $code = $this->lastCode($email);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, User::where('email', $email)->value('verification_code')); // stored hashed

        // Can't sign in yet
        $this->postJson('/api/login', ['email' => $email, 'password' => 'Secret#123'])
            ->assertStatus(403)->assertJsonPath('verification_required', true);

        // Wrong code counts down; right code activates and signs in
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/email/verify', ['email' => $email, 'code' => $wrong])->assertStatus(422)->assertJsonPath('message', 'That code is incorrect. 4 tries left.');
        $ok = $this->postJson('/api/email/verify', ['email' => $email, 'code' => $code])->assertOk()->json();
        $this->assertNotEmpty($ok['token']);
        $this->assertArrayNotHasKey('verification_code', $ok['user']);
        $this->assertNotNull(User::where('email', $email)->value('email_verified_at'));

        // The code can't be reused, and signing in now works and sends a sign-in alert
        $this->postJson('/api/email/verify', ['email' => $email, 'code' => $code])->assertStatus(409);
        $this->postJson('/api/login', ['email' => $email, 'password' => 'Secret#123'])->assertOk();
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($email) && $m->title === 'New sign-in to your account');
    }

    public function test_codes_expire_and_lock_after_too_many_tries(): void
    {
        $email = 'lock' . uniqid() . '@mqcqc.ph.education';
        $this->register($email)->assertCreated();
        $code = $this->lastCode($email);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/email/verify', ['email' => $email, 'code' => $wrong]);
        }
        $this->postJson('/api/email/verify', ['email' => $email, 'code' => $code])
            ->assertStatus(422)->assertJsonPath('message', 'Too many wrong codes. Tap "Send a new code".');

        // Resend is allowed after the cooldown; the new code works
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['email' => $email])->assertOk();
        $fresh = $this->lastCode($email);
        $this->travel(16)->minutes();
        $this->postJson('/api/email/verify', ['email' => $email, 'code' => $fresh])->assertStatus(422)->assertJsonPath('message', 'This code has expired. Tap "Send a new code".');
        $this->travel(-16)->minutes();
    }

    public function test_email_change_needs_the_new_mailbox_and_alerts_the_old_one(): void
    {
        $user = User::create(['name' => 'Fe Cruz', 'email' => 'fe' . uniqid() . '@mqcqc.ph.education', 'password' => 'Secret#123',
            'role' => 'teacher', 'school_id' => 'F-' . uniqid()]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $old = $user->email;
        $new = 'fe.new' . uniqid() . '@mqcqc.ph.education';
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/profile', ['name' => 'Fe Cruz', 'email' => $new])->assertOk()->json();
        $this->assertSame($old, $res['email']);          // unchanged until confirmed
        $this->assertSame($new, $res['pending_email']);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($old) && $m->title === 'Email change requested');

        $this->postJson('/api/profile/email/verify', ['code' => '12345'])->assertStatus(422);
        $this->postJson('/api/profile/email/verify', ['code' => $this->lastCode($new)])->assertOk()->assertJsonPath('email', $new)->assertJsonPath('pending_email', null);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($old) && $m->title === 'Your email was changed');

        // Password change alert
        $this->postJson('/api/change-password', ['current_password' => 'Secret#123', 'new_password' => 'Another#456'])->assertOk();
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($new) && $m->title === 'Your password was changed');
    }
}
