<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SecurityAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    return response()->json([
        'user' => $user,
        'token' => $user->createToken('api')->plainTextToken,
    ], 201);
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

        SecurityAlerts::send($user->email, $user->name, 'New sign-in to your account',
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

        $oldEmail = $user->email;
        $user->update($data);

        // Tell the previous address, so the owner notices if someone else changed it
        if ($user->email !== $oldEmail) {
            SecurityAlerts::send($oldEmail, $user->name, 'Your email was changed',
                "Your account's email was changed from {$oldEmail} to {$user->email}.", $request);
        }

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

        SecurityAlerts::send($user->email, $user->name, 'Your password was changed',
            'The password for your MTQ-ATTENDEASE account was just changed.', $request);

        return response()->json(['message' => 'Password updated.']);
    }
}
