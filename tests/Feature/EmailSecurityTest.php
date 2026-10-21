<?php

namespace Tests\Feature;

use App\Mail\SecurityAlertMail;
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

    public function test_new_account_can_sign_in_right_away_and_gets_sign_in_alerts(): void
    {
        $email = 'eve' . uniqid() . '@mqcqc.ph.education';
        $reg = fn ($e) => $this->postJson('/api/register', [
            'name' => 'Eve Santos', 'email' => $e, 'password' => 'Secret#123', 'role' => 'student',
            'school_id' => 'E-' . uniqid(), 'course' => 'BSIT', 'year_level' => '1st Year', 'section' => 'A',
        ]);

        $reg('not an email')->assertStatus(422);
        $reg('eve@gmail.com')->assertStatus(422)->assertJsonPath('errors.email.0', 'Please use your school email (@mqcqc.ph.education).');

        // No code step: registering returns a working login
        $res = $reg($email)->assertCreated()->json();
        $this->assertNotEmpty($res['token']);
        $this->assertSame($email, $res['user']['email']);

        $this->postJson('/api/login', ['email' => $email, 'password' => 'Secret#123'])->assertOk()->assertJsonStructure(['token', 'user']);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($email) && $m->title === 'New sign-in to your account');

        // The old code endpoints are gone
        $this->postJson('/api/email/verify', ['email' => $email, 'code' => '123456'])->assertNotFound();
        $this->postJson('/api/email/resend', ['email' => $email])->assertNotFound();
    }

    public function test_email_change_applies_at_once_and_alerts_the_old_address(): void
    {
        $user = User::create(['name' => 'Fe Cruz', 'email' => 'fe' . uniqid() . '@mqcqc.ph.education', 'password' => 'Secret#123',
            'role' => 'teacher', 'school_id' => 'F-' . uniqid()]);
        $old = $user->email;
        $new = 'fe.new' . uniqid() . '@mqcqc.ph.education';
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/profile', ['name' => 'Fe Cruz', 'email' => $new])->assertOk()->json();
        $this->assertSame($new, $res['email']);
        $this->assertArrayNotHasKey('pending_email', $res);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($old) && $m->title === 'Your email was changed');

        // Saving without changing the email sends no alert
        Mail::fake();
        $this->putJson('/api/profile', ['name' => 'Fe C.', 'email' => $new])->assertOk();
        Mail::assertNothingSent();

        $this->postJson('/api/change-password', ['current_password' => 'Secret#123', 'new_password' => 'Another#456'])->assertOk();
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($new) && $m->title === 'Your password was changed');
    }

    public function test_sign_in_still_works_when_the_alert_email_cannot_be_sent(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $this->app->forgetInstance('mail.manager');
        \Illuminate\Support\Facades\Mail::clearResolvedInstances();

        $user = User::create(['name' => 'Gil', 'email' => 'gil' . uniqid() . '@mqcqc.ph.education', 'password' => 'Secret#123',
            'role' => 'student', 'school_id' => 'G-' . uniqid(), 'course' => 'BSIT', 'year_level' => '2nd Year', 'section' => 'B']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret#123'])->assertOk();
    }
}
