<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attendance sessions: one dated session per class meeting. Teachers create them, edit the date and
 * manage every enrolled student's status; students only see their own status.
 */
class ClassSessionController extends Controller
{
    public function index(Request $request, Classroom $class)
    {
        $user = $request->user();
        $isTeacher = $this->canView($request, $class);

        $sessions = $class->classSessions()
            ->orderByDesc('session_date')->orderByDesc('starts_at')->orderByDesc('id')
            ->get();

        // Teacher counts cover the enrolled students, optionally only one section (?section=…, "__none__" = no section)
        $studentIds = $isTeacher
            ? $this->sectionFilter($class->approvedStudents(), $request->query('section'))->pluck('id')
            : collect([$user->id]);

        $records = AttendanceRecord::query()
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
            ->whereIn('attendance_sessions.class_session_id', $sessions->pluck('id'))
            ->whereIn('attendance_records.student_id', $studentIds)
            ->get(['attendance_records.*', 'attendance_sessions.class_session_id', 'attendance_sessions.mode'])
            ->groupBy('class_session_id');

        $enrolled = $studentIds->count();

        return $sessions->map(function (ClassSession $s) use ($records, $isTeacher, $enrolled) {
            $recs = $records->get($s->id, collect());
            $row = [
                'id' => $s->id,
                'title' => $s->title,
                'date' => $s->dateString(),
                'starts_at' => $s->starts_at,
                'is_today' => $s->isToday(),
            ];

            if ($isTeacher) {
                $timeIn = $recs->where('stage', 1)->unique('student_id');
                $count = fn ($status) => $timeIn->where('status', $status)->count();
                $row += [
                    'present_count' => $count('present'),
                    'late_count' => $count('late'),
                    'excused_count' => $count('excused'),
                    // Enrolled students without a Time In record count as absent
                    'absent_count' => max(0, $enrolled - $timeIn->whereIn('status', ['present', 'late', 'excused'])->count()),
                    'enrolled_count' => $enrolled,
                ];
            } else {
                $in = $recs->firstWhere('stage', 1);
                $out = $recs->firstWhere('stage', 2);
                $row += [
                    'status' => $in?->status ?? 'absent',
                    'time_in' => $in && $in->mode !== 'manual' ? $in->scanned_at : null,
                    'time_out' => $out && $out->mode !== 'manual' ? $out->scanned_at : null,
                ];
            }

            return $row;
        });
    }

    public function store(Request $request, Classroom $class)
    {
        $this->authorizeTeacher($request, $class);

        $data = $request->validate([
            'session_date' => 'required|date_format:Y-m-d',
            'title' => 'nullable|string|max:255',
        ]);

        $session = $class->classSessions()->create([
            'session_date' => $data['session_date'],
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
        ]);

        return response()->json($this->show($request, $session), 201);
    }

    public function update(Request $request, ClassSession $classSession)
    {
        $this->authorizeTeacher($request, $classSession->classroom);

        $data = $request->validate([
            'session_date' => 'required|date_format:Y-m-d',
            'title' => 'nullable|string|max:255',
        ]);

        $classSession->update([
            'session_date' => $data['session_date'],
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
        ]);

        // QR codes of this session follow its (new) date
        $classSession->attendanceSessions()->update(['expires_at' => $classSession->qrExpiresAt()]);

        return response()->json($this->show($request, $classSession->fresh()));
    }

    /** Deletes the session with its QR windows and attendance records, so nothing is left orphaned. */
    public function destroy(Request $request, ClassSession $classSession)
    {
        $this->authorizeTeacher($request, $classSession->classroom);

        $records = $classSession->records()->count();

        DB::transaction(function () use ($classSession) {
            $classSession->attendanceSessions()->delete(); // attendance_records cascade
            $classSession->delete();
        });

        return response()->json(['message' => 'Session deleted.', 'deleted_records' => $records]);
    }

    public function show(Request $request, ClassSession $classSession)
    {
        $user = $request->user();
        $class = $classSession->classroom;
        $isTeacher = $this->canView($request, $class);

        $records = AttendanceRecord::with('session:id,mode')
            ->whereHas('session', fn ($q) => $q->where('class_session_id', $classSession->id))
            ->when(! $isTeacher, fn ($q) => $q->where('student_id', $user->id))
            ->get()->groupBy('student_id');

        $students = $isTeacher
            ? $class->approvedStudents()->get()
            : User::whereKey($user->id)->get();

        $rows = $students->map(function ($u) use ($records) {
            $recs = $records->get($u->id, collect());
            $in = $recs->firstWhere('stage', 1);
            $out = $recs->firstWhere('stage', 2);
            $scanned = fn ($r) => $r && $r->session?->mode !== 'manual' ? $r->scanned_at : null;

            return [
                'student_id' => $u->id,
                'name' => $u->name,
                'school_id' => $u->school_id,
                'section' => $u->section,
                'time_in' => $scanned($in),
                'time_out' => $scanned($out),
                'status' => $in?->status ?? 'absent',
                'record_id' => $in?->id,
            ];
        });

        return [
            'id' => $classSession->id,
            'class_id' => $class->id,
            'title' => $classSession->title,
            'date' => $classSession->dateString(),
            'starts_at' => $classSession->starts_at,
            'is_today' => $classSession->isToday(),
            'students' => $rows->values(),
        ];
    }

    /** Teacher sets a student's status for the session, creating the record if the student never scanned. */
    public function setStudentStatus(Request $request, ClassSession $classSession, User $student)
    {
        $class = $classSession->classroom;
        $this->authorizeTeacher($request, $class);
        abort_unless($class->hasApprovedStudent($student->id), 404, 'Student is not enrolled in this class.');

        $data = $request->validate(['status' => 'required|in:present,late,absent,excused']);

        $record = AttendanceRecord::where('student_id', $student->id)->where('stage', 1)
            ->whereHas('session', fn ($q) => $q->where('class_session_id', $classSession->id))
            ->first();

        if ($record) {
            $record->update(['status' => $data['status']]);
        } else {
            $manual = $classSession->attendanceSessions()->firstOrCreate(
                ['mode' => 'manual'],
                ['classroom_id' => $class->id, 'stage' => 1, 'status' => 'stopped',
                 'started_at' => now(), 'ended_at' => now()]
            );
            $record = AttendanceRecord::create([
                'attendance_session_id' => $manual->id,
                'student_id' => $student->id,
                'stage' => 1,
                'scanned_at' => now(),
                'status' => $data['status'],
            ]);
        }

        return response()->json(['record_id' => $record->id, 'student_id' => $student->id, 'status' => $record->status]);
    }

    private function sectionFilter($query, ?string $section)
    {
        if ($section === null || $section === '') {
            return $query;
        }

        return $section === '__none__'
            ? $query->where(fn ($q) => $q->whereNull('section')->orWhere('section', ''))
            : $query->where('section', $section);
    }

    /** True for the class teacher, false for an approved student; anyone else gets 403. */
    private function canView(Request $request, Classroom $class): bool
    {
        $user = $request->user();
        if ($class->teacher_id === $user->id) {
            return true;
        }
        abort_unless($user->isStudent() && $class->hasApprovedStudent($user->id), 403, 'Not your class.');

        return false;
    }

    private function authorizeTeacher(Request $request, Classroom $class): void
    {
        abort_unless($class->teacher_id === $request->user()->id, 403, 'Not your class.');
    }
}
