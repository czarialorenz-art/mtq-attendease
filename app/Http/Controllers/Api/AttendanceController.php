<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSession;
use App\Models\Classroom;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    public function start(Request $request, Classroom $class)
    {
        $this->authorizeTeacher($request, $class);

        $stage = (int) $request->input('stage') === 2 ? 2 : 1; // 1 = Time In, 2 = Time Out

        if ($id = $request->input('class_session_id')) {
            $classSession = $class->classSessions()->findOrFail($id);
        } else {
            // No session picked: use (or create) today's session
            $classSession = $class->classSessions()->firstOrCreate(
                ['session_date' => now(ClassSession::TZ)->toDateString()],
                ['starts_at' => now(ClassSession::TZ)->format('H:i:s'), 'title' => $request->input('title')]
            );
        }

        if (! $classSession->isToday()) {
            return response()->json([
                'message' => 'QR attendance can only be started on the session date ('
                    . $classSession->session_date->format('M j, Y') . ').',
            ], 422);
        }

        // Stop any currently active window
        $class->attendanceSessions()->where('status', 'active')
            ->update(['status' => 'stopped', 'ended_at' => now(), 'qr_token' => null]);

        $session = $class->attendanceSessions()->create([
            'class_session_id' => $classSession->id,
            'stage' => $stage,
            'qr_token' => Str::random(32),
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => $classSession->qrExpiresAt(),
        ]);

        return response()->json($this->format($session, true), 201);
    }

    public function current(Request $request, Classroom $class)
    {
        $user = $request->user();

        if ($user->isTeacher()) {
            $this->authorizeTeacher($request, $class);
        } else {
            abort_unless($this->isApproved($class->id, $user->id), 403, 'Not enrolled in this class.');
        }

        $session = $class->attendanceSessions()->where('status', 'active')->latest()->first();

        // A QR window past its day is closed (records are kept); the token stays so scans get an "expired" message
        if ($session?->isExpired()) {
            $session->update(['status' => 'stopped', 'ended_at' => $session->expires_at]);
            $session = null;
        }

        return response()->json($session ? $this->format($session, $user->isTeacher()) : null);
    }

    public function records(Request $request, Classroom $class)
    {
        $this->authorizeTeacher($request, $class);

        $records = AttendanceRecord::whereHas('session', fn ($q) => $q->where('classroom_id', $class->id))
            ->whereIn('student_id', $class->approvedStudentIds())
            ->with(['student:id,name,email,school_id,section', 'session.classSession'])
            ->latest('scanned_at')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'session_id' => $r->attendance_session_id,
                'class_session_id' => $r->session?->class_session_id,
                'session_date' => $r->session?->classSession?->dateString(),
                'student_id' => $r->student_id,
                'student' => $r->student?->name,
                'school_id' => $r->student?->school_id,
                'section' => $r->student?->section,
                'email' => $r->student?->email,
                'stage' => $r->stage,
                'scanned_at' => $r->scanned_at,
                'status' => $r->status,
                'manual' => $r->session?->mode === 'manual',
            ]);

        return response()->json($records);
    }

    public function nextStage(Request $request, AttendanceSession $session)
    {
        $this->authorizeSession($request, $session);
        abort_unless($session->status === 'active' && ! $session->isExpired(), 422, 'Session is not active.');

        $session->update(['stage' => $session->stage + 1, 'qr_token' => Str::random(32)]);

        return response()->json($this->format($session->fresh(), true));
    }

    public function refresh(Request $request, AttendanceSession $session)
    {
        $this->authorizeSession($request, $session);
        abort_unless($session->status === 'active', 422, 'Session is not active.');
        abort_if($session->isExpired(), 422, 'This QR code has expired. Start a new one for today\'s session.');

        $session->update(['qr_token' => Str::random(32)]);

        return response()->json($this->format($session->fresh(), true));
    }

    public function updateStatus(Request $request, AttendanceRecord $record)
    {
        $this->authorizeSession($request, $record->session);

        $data = $request->validate(['status' => 'required|in:present,late,absent,excused']);

        $record->update(['status' => $data['status']]);

        return response()->json(['id' => $record->id, 'status' => $record->status]);
    }

    public function stop(Request $request, AttendanceSession $session)
    {
        $this->authorizeSession($request, $session);

        $session->update(['status' => 'stopped', 'ended_at' => now(), 'qr_token' => null]);

        return response()->json(['message' => 'Attendance stopped.']);
    }

    public function scan(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isStudent(), 403, 'Only students can scan attendance.');

        $token = $request->input('token') ?? $request->input('qr_token') ?? $request->input('code');
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'QR code is required.'], 422);
        }

        $session = AttendanceSession::where('qr_token', $token)->with('classSession')->first();
        if (! $session) {
            return response()->json(['message' => 'Invalid QR code, or your teacher has already closed it.'], 404);
        }

        abort_unless($this->isApproved($session->classroom_id, $user->id), 403, 'You are not enrolled in this class.');

        $date = $session->classSession?->session_date?->format('M j, Y');
        if ($session->isExpired() || ($session->classSession && ! $session->classSession->isToday())) {
            return response()->json([
                'message' => 'This QR code has expired. Attendance QR codes are valid only on the session date'
                    . ($date ? " ({$date})" : '') . '.',
            ], 410);
        }
        if ($session->status !== 'active') {
            return response()->json(['message' => 'Invalid QR code, or your teacher has already closed it.'], 404);
        }

        // One record per student per stage per attendance session, even if the teacher started several QR windows
        $existing = AttendanceRecord::where('student_id', $user->id)->where('stage', $session->stage)
            ->whereHas('session', fn ($q) => $q->where('class_session_id', $session->class_session_id))
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already recorded for this stage.', 'stage' => $session->stage]);
        }

        AttendanceRecord::create([
            'attendance_session_id' => $session->id,
            'student_id' => $user->id,
            'stage' => $session->stage,
            'scanned_at' => now(),
        ]);

        return response()->json(['message' => 'Attendance recorded.', 'stage' => $session->stage], 201);
    }

    public function myRecords(Request $request)
    {
        $records = AttendanceRecord::where('student_id', $request->user()->id)
            ->with(['session.classroom:id,name', 'session.classSession'])
            ->latest('scanned_at')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'class_id' => $r->session?->classroom_id,
                'class' => $r->session?->classroom?->name,
                'class_session_id' => $r->session?->class_session_id,
                'session_date' => $r->session?->classSession?->dateString(),
                'session_title' => $r->session?->classSession?->title,
                'stage' => $r->stage,
                'scanned_at' => $r->session?->mode === 'manual' ? null : $r->scanned_at,
                'status' => $r->status,
            ]);

        return response()->json($records);
    }

    private function isApproved(int $classId, int $userId): bool
    {
        return Enrollment::where('classroom_id', $classId)
            ->where('student_id', $userId)->where('status', 'approved')->exists();
    }

    private function authorizeTeacher(Request $request, Classroom $class): void
    {
        abort_unless($class->teacher_id === $request->user()->id, 403, 'Not your class.');
    }

    private function authorizeSession(Request $request, ?AttendanceSession $session): void
    {
        abort_unless($session && $session->classroom?->teacher_id === $request->user()->id, 403, 'Not your class.');
    }

    private function format(AttendanceSession $s, bool $withToken): array
    {
        $data = [
            'id' => $s->id,
            'class_id' => $s->classroom_id,
            'class_session_id' => $s->class_session_id,
            'session_date' => $s->classSession?->dateString(),
            'stage' => $s->stage,
            'status' => $s->status,
            'started_at' => $s->started_at,
            'ended_at' => $s->ended_at,
            'expires_at' => $s->expires_at,
            'scanned_count' => $s->records()->where('stage', $s->stage)->count(),
        ];

        if ($withToken) {
            $data['qr_token'] = $s->qr_token;
        }

        return $data;
    }
}
