<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
   public function register(Request $request)
{
    $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'email' => ['required', 'email:rfc', 'ends_with:@mqcqc.ph.education', 'unique:users,email'],
        'password' => 'required|string|min:8',
        'role' => 'required|in:teacher,student',
        'school_id' => 'required|string|max:50|unique:users,school_id',
        'course' => ['required_if:role,student', 'nullable', Rule::in(User::COURSES)],
        'year_level' => ['required_if:role,student', 'nullable', Rule::in(User::YEAR_LEVELS)],
        'section' => ['required_if:role,student', 'nullable', Rule::in(User::SECTIONS)],
        'department' => 'nullable|string|max:150',
        ], [
        'email.email' => 'Please enter a valid email address.',
        'email.ends_with' => 'Please use your school email (@mqcqc.ph.education).',
        'section.required_if' => 'Please choose your section (A, B, or C).',
        'section.in' => 'Please choose your section (A, B, or C).',
        'course.required_if' => 'Please choose your course.',
        'course.in' => 'Please choose your course from the list.',
        'year_level.required_if' => 'Please choose your year level.',
        'year_level.in' => 'Please choose your year level from the list.',
    ]);


    $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
        'role' => $data['role'],
        'school_id' => $data['school_id'],
        'course' => $data['role'] === 'student' ? $data['course'] : null,
        'year_level' => $data['role'] === 'student' ? $data['year_level'] : null,
        'section' => $data['role'] === 'student' ? $data['section'] : null,
        'department' => $data['department'] ?? null,
    ]);

    // The account can't be used until the owner proves the mailbox is theirs
    try {
        EmailVerification::send($user, $user->email, 'register');
    } catch (\Throwable $e) {
        Log::error('Verification email failed: ' . $e->getMessage());
        $user->delete();

        return response()->json(['message' => "We couldn't send a verification email right now. Please try again later."], 503);
    }

    return response()->json([
        'message' => "We sent a 6-digit code to {$user->email}. Enter it to activate your account.",
        'verification_required' => true,
        'email' => $user->email,
    ], 201);
}

    /** Confirms a new account's email with its code, then signs the user in. */
    public function verifyEmail(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|string']);
        $user = User::where('email', strtolower(trim($data['email'])))->first();

        if (! $user) {
            return response()->json(['message' => 'That code is incorrect or has expired.'], 422);
        }
        if ($user->email_verified_at) {
            return response()->json(['message' => 'This email is already verified. Please log in.'], 409);
        }
        if ($error = EmailVerification::check($user, $data['code'])) {
            return response()->json(['message' => $error], 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        EmailVerification::alert($user->email, $user->name, 'Your account is ready',
            'Your email was verified and your MTQ-ATTENDEASE account is now active.', $request);

        return response()->json(['user' => $user->fresh(), 'token' => $user->createToken('api')->plainTextToken]);
    }

    /** Sends another code for an unverified account. Same reply whether or not the email exists. */
    public function resendCode(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', strtolower(trim($data['email'])))->whereNull('email_verified_at')->first();

        if ($user && EmailVerification::cooldown($user) === 0) {
            try {
                EmailVerification::send($user, $user->email, 'register');
            } catch (\Throwable $e) {
                Log::error('Verification email failed: ' . $e->getMessage());

                return response()->json(['message' => "We couldn't send the email right now. Please try again later."], 503);
            }
        }

        return response()->json([
            'message' => 'If this account still needs verification, a new code is on its way. You can ask again in a minute.',
            'retry_after' => $user ? max(EmailVerification::cooldown($user), 0) : EmailVerification::RESEND_SECONDS,
        ]);
    }

    public function login(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->email_verified_at) {
            if (EmailVerification::cooldown($user) === 0) {
                try {
                    EmailVerification::send($user, $user->email, 'register');
                } catch (\Throwable $e) {
                    Log::error('Verification email failed: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Please verify your email first. Enter the 6-digit code we sent to ' . $user->email . '.',
                'verification_required' => true,
                'email' => $user->email,
            ], 403);
        }

        EmailVerification::alert($user->email, $user->name, 'New sign-in to your account',
            'Your MTQ-ATTENDEASE account was just signed in to.', $request);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
        public function updateProfile(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email:rfc', 'ends_with:@mqcqc.ph.education', 'unique:users,email,' . $user->id],
            'school_id' => 'nullable|string|max:50|unique:users,school_id,' . $user->id,
            'course' => $user->isStudent() ? ['required', Rule::in(User::COURSES)] : 'nullable',
            'year_level' => $user->isStudent() ? ['required', Rule::in(User::YEAR_LEVELS)] : 'nullable',
            'section' => $user->isStudent() ? ['required', Rule::in(User::SECTIONS)] : 'nullable',
            'department' => 'nullable|string|max:150',
        ], [
            'email.email' => 'Please enter a valid email address.',
            'email.ends_with' => 'Please use your school email (@mqcqc.ph.education).',
            'section.required' => 'Please choose your section (A, B, or C).',
            'section.in' => 'Please choose your section (A, B, or C).',
            'course.required' => 'Please choose your course.',
            'course.in' => 'Please choose your course from the list.',
            'year_level.required' => 'Please choose your year level.',
            'year_level.in' => 'Please choose your year level from the list.',
        ]);

        if ($user->isTeacher()) {
            unset($data['course'], $data['year_level'], $data['section']);
        } else {
            unset($data['department']);
        }

        // A new email only takes effect after its owner enters the code sent to it
        $newEmail = $data['email'];
        unset($data['email']);
        $user->update($data);

        if ($newEmail === $user->email) {
            if ($user->pending_email) {
                $user->forceFill(['pending_email' => null, 'verification_code' => null, 'verification_expires_at' => null])->save();
            }
        } elseif ($newEmail !== $user->pending_email || EmailVerification::cooldown($user) === 0) {
            try {
                EmailVerification::send($user, $newEmail, 'email_change');
            } catch (\Throwable $e) {
                Log::error('Verification email failed: ' . $e->getMessage());

                return response()->json(['message' => "We couldn't send a code to {$newEmail} right now. Your other changes were saved."], 503);
            }
            $user->forceFill(['pending_email' => $newEmail])->save();
            EmailVerification::alert($user->email, $user->name, 'Email change requested',
                "Someone asked to change your account's email to {$newEmail}. It will only change after the code sent to that address is entered.", $request);
        }

        return response()->json($user->fresh());
    }

    /** Confirms a pending email change with the code sent to the new address. */
    public function verifyEmailChange(Request $request)
    {
        $data = $request->validate(['code' => 'required|string']);
        $user = $request->user();

        if (! $user->pending_email) {
            return response()->json(['message' => 'There is no email change to confirm.'], 422);
        }
        if (User::where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            $user->forceFill(['pending_email' => null])->save();

            return response()->json(['message' => 'That email is already used by another account.'], 422);
        }
        if ($error = EmailVerification::check($user, $data['code'])) {
            return response()->json(['message' => $error], 422);
        }

        $old = $user->email;
        $user->forceFill(['email' => $user->pending_email, 'pending_email' => null, 'email_verified_at' => now()])->save();
        EmailVerification::alert($old, $user->name, 'Your email was changed',
            "Your account's email was changed from {$old} to {$user->email}.", $request);

        return response()->json($user->fresh());
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($data['new_password'])]);

        EmailVerification::alert($user->email, $user->name, 'Your password was changed',
            'The password for your MTQ-ATTENDEASE account was just changed.', $request);

        return response()->json(['message' => 'Password updated.']);
    }
}
