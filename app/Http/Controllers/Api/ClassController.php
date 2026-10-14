<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClassController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isTeacher()) {
            $classes = Classroom::where('teacher_id', $user->id)
                ->withCount([
                    'enrollments as approved_count' => fn ($q) => $q->where('status', 'approved'),
                    'enrollments as pending_count' => fn ($q) => $q->where('status', 'pending'),
                ])
                ->latest()->get();
        } else {
            $classes = Classroom::whereHas('enrollments', fn ($q) => $q->where('student_id', $user->id))
                ->with([
                    'teacher:id,name',
                    'enrollments' => fn ($q) => $q->where('student_id', $user->id),
                ])
                ->latest()->get();
        }

        return response()->json($classes->map(fn ($c) => $this->format($c)));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isTeacher(), 403, 'Only teachers can create classes.');

        $name = $request->input('name')
            ?? $request->input('subject')
            ?? $request->input('subject_name')
            ?? $request->input('title');

        if (! is_string($name) || trim($name) === '') {
            return response()->json([
                'message' => 'Subject name is required.',
                'errors' => ['name' => ['Subject name is required.']],
            ], 422);
        }

        $name = trim($name);

        do {
            $code = strtoupper(Str::random(6));
        } while (Classroom::where('join_code', $code)->exists());

        $class = Classroom::create([
            'teacher_id' => $user->id,
            'name' => $name,
            'subject' => $name,
            'join_code' => $code,
            'year_level' => trim((string) $request->input('year_level')) ?: null,
            'section' => trim((string) $request->input('section')) ?: null,
        ]);

        return response()->json($this->format($class), 201);
    }

    public function join(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isStudent(), 403, 'Only students can join classes.');

        $code = strtoupper(trim((string) ($request->input('code') ?? $request->input('join_code'))));

        $class = Classroom::where('join_code', $code)->first();
        if (! $class) {
            return response()->json(['message' => 'Invalid class code.'], 404);
        }

        $existing = Enrollment::where('classroom_id', $class->id)
            ->where('student_id', $user->id)->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already requested to join this class.',
                'status' => $existing->status,
            ], 409);
        }

        Enrollment::create([
            'classroom_id' => $class->id,
            'student_id' => $user->id,
            'status' => 'pending',
        ]);

        UserNotification::create([
            'user_id' => $class->teacher_id,
            'title' => 'New join request',
            'message' => "{$user->name} requested to join {$class->name}.",
        ]);

        return response()->json(['message' => 'Join request sent. Waiting for teacher approval.'], 201);
    }

    public function update(Request $request, Classroom $class)
{
    $this->authorizeTeacher($request, $class);

    $data = $request->validate([
        'name' => 'required|string|max:255',
        'year_level' => 'nullable|string|max:50',
        'section' => 'nullable|string|max:50',
    ]);

    $class->update([
        'name' => trim($data['name']),
        'subject' => trim($data['name']),
        'year_level' => $data['year_level'] ?? null,
        'section' => $data['section'] ?? null,
    ]);

    return response()->json($this->format($class->fresh()));
}

public function schedule(Request $request, Classroom $class)
{
    $this->authorizeTeacher($request, $class);

    $data = $request->validate([
        'time_in_start' => 'nullable|date_format:H:i',
        'time_in_end' => 'nullable|date_format:H:i',
        'time_out_start' => 'nullable|date_format:H:i',
        'time_out_end' => 'nullable|date_format:H:i',
    ]);

    foreach ([['time_in_start', 'time_in_end', 'Time In'], ['time_out_start', 'time_out_end', 'Time Out']] as [$from, $to, $label]) {
        if (! empty($data[$from]) && ! empty($data[$to]) && $data[$to] <= $data[$from]) {
            return response()->json(['message' => "{$label} closing time must be after its opening time."], 422);
        }
    }

    $class->update(array_map(fn ($v) => $v ?: null, $data));

    return response()->json($this->format($class->fresh()));
}

    public function students(Request $request, Classroom $class)
    {
        $this->authorizeTeacher($request, $class);

        $rows = $class->enrollments()
            ->with('student:id,name,email,school_id,course,year_level,section')
            ->get()
            ->sortBy(fn ($e) => mb_strtolower((string) $e->student?->name))->values()
            ->map(fn ($e) => [
                'id' => $e->id,
                'status' => $e->status,
                'student' => $e->student,
                'created_at' => $e->created_at,
            ]);

        return response()->json($rows);
    }

    public function approveStudent(Request $request, Classroom $class, Enrollment $enrollment)
    {
        return $this->setStatus($request, $class, $enrollment, 'approved');
    }

    public function rejectStudent(Request $request, Classroom $class, Enrollment $enrollment)
    {
        return $this->setStatus($request, $class, $enrollment, 'rejected');
    }

    /**
     * Teacher removes a student from the class. Their attendance, scores and photos are kept (hidden while
     * they're not in the class), so rejoining with the class code and being approved again restores them.
     */
    public function removeStudent(Request $request, Classroom $class, Enrollment $enrollment)
    {
        $this->authorizeTeacher($request, $class);
        abort_unless($enrollment->classroom_id === $class->id, 404);

        $name = $enrollment->student?->name;
        $enrollment->delete();

        UserNotification::create([
            'user_id' => $enrollment->student_id,
            'title' => 'Removed from class',
            'message' => "You were removed from {$class->name} by your teacher.",
        ]);

        return response()->json(['message' => ($name ?: 'Student') . " was removed from {$class->name}."]);
    }

    private function setStatus(Request $request, Classroom $class, Enrollment $enrollment, string $status)
    {
        $this->authorizeTeacher($request, $class);
        abort_unless($enrollment->classroom_id === $class->id, 404);

        $enrollment->update(['status' => $status]);

        UserNotification::create([
            'user_id' => $enrollment->student_id,
            'title' => 'Class request ' . $status,
            'message' => "Your request to join {$class->name} was {$status}.",
        ]);

        return response()->json(['message' => "Student {$status}.", 'status' => $status]);
    }

    private function authorizeTeacher(Request $request, Classroom $class): void
    {
        abort_unless($class->teacher_id === $request->user()->id, 403, 'Not your class.');
    }

    private function format(Classroom $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'subject' => $c->subject ?? $c->name,
            'join_code' => $c->join_code,
            'code' => $c->join_code,
            'approved_count' => $c->approved_count ?? 0,
            'pending_count' => $c->pending_count ?? 0,
            'students_count' => $c->approved_count ?? 0,
            'teacher' => $c->relationLoaded('teacher') ? $c->teacher?->name : null,
            'status' => $c->relationLoaded('enrollments') ? optional($c->enrollments->first())->status : null,
            'year_level' => $c->year_level,
            'section' => $c->section,
            'time_in_start' => $this->hm($c->time_in_start),
            'time_in_end' => $this->hm($c->time_in_end),
            'time_out_start' => $this->hm($c->time_out_start),
            'time_out_end' => $this->hm($c->time_out_end),
        ];
    }
        private function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }
}