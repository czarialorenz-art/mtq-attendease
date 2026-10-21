<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Classroom;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private const STATUS_LABELS = ['pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Not Accepted'];

    public function attendanceCsv(Request $request, Classroom $class)
    {
        abort_unless($class->teacher_id === $request->user()->id, 403, 'Not your class.');

        $records = AttendanceRecord::whereHas('session', fn ($q) => $q->where('classroom_id', $class->id))
            ->whereIn('student_id', $class->approvedStudentIds())
            ->with('student')->get()
            // by date, then student name A-Z, then Time In before Time Out
            ->sortBy(fn ($r) => [$r->scanned_at?->copy()->timezone('Asia/Manila')->toDateString(), mb_strtolower((string) $r->student?->name), (int) $r->stage])
            ->values();

        return $this->csv("attendance-{$class->id}.csv",
            ['Subject', 'Year', 'Section', 'Student', 'ID Number', 'Email', 'Type', 'Date', 'Time', 'Status'],
            $records->map(function ($r) use ($class) {
                $at = $r->scanned_at?->copy()->timezone('Asia/Manila');

                return [
                    $class->name, $class->year_level, $class->section,
                    $r->student?->name, $r->student?->school_id, $r->student?->email,
                    (int) $r->stage === 2 ? 'Time Out' : 'Time In',
                    $at?->format('Y-m-d'), $at?->format('h:i A'),
                    ucfirst($r->status ?? 'present'),
                ];
            }));
    }

    public function scoresCsv(Request $request, Classroom $class)
    {
        abort_unless($class->teacher_id === $request->user()->id, 403, 'Not your class.');

        $subs = Submission::whereHas('assessment', fn ($q) => $q->where('classroom_id', $class->id))
            ->whereIn('student_id', $class->approvedStudentIds())
            ->with(['student', 'assessment'])->get()
            // by assessment (oldest first), then student name A-Z
            ->sortBy(fn ($s) => [(string) $s->assessment?->created_at, $s->assessment_id, mb_strtolower((string) $s->student?->name)])
            ->values();

        return $this->csv("scores-{$class->id}.csv",
            ['Subject', 'Type', 'Assessment', 'Student', 'ID Number', 'Email', 'Score', 'Total Points', 'Status', 'Submitted At'],
            $subs->map(fn ($s) => [
                $class->name,
                ucfirst($s->assessment?->type ?? 'quiz'),
                $s->assessment?->title,
                $s->student?->name, $s->student?->school_id, $s->student?->email,
                // only accepted scores are official
                $s->isAccepted() ? $s->score : '', $s->assessment?->total_points,
                self::STATUS_LABELS[$s->status] ?? 'Pending',
                $s->submitted_at?->copy()->timezone('Asia/Manila')->format('Y-m-d h:i A'),
            ]));
    }

    /**
     * Subject report card, built from the assessment records every time (no separate copy of scores).
     * Only accepted scores are official. Teacher: every approved student; assessments without an accepted
     * score count as 0. Student: only their own row, and only accepted scores are shown and counted.
     */
    public function reportCard(Request $request, Classroom $class)
    {
        $user = $request->user();
        $isTeacher = $class->teacher_id === $user->id;
        abort_unless($isTeacher || ($user->isStudent() && $class->hasApprovedStudent($user->id)), 403, 'Not your class.');

        $assessments = $class->assessments()->orderBy('created_at')->orderBy('id')->get();
        $students = $isTeacher ? $class->approvedStudents()->get() : User::whereKey($user->id)->get();

        $subs = Submission::whereIn('assessment_id', $assessments->pluck('id'))
            ->whereIn('student_id', $students->pluck('id'))
            ->get()->keyBy(fn ($s) => $s->assessment_id . ':' . $s->student_id);

        $types = ['quiz', 'exam', 'activity'];
        $attendance = $this->attendanceCounts($class, $students->pluck('id')->all());

        $rows = $students->map(function ($u) use ($assessments, $subs, $isTeacher, $types, $attendance) {
            $scores = [];
            $totals = array_fill_keys([...$types, 'overall'], ['earned' => 0, 'max' => 0]);

            foreach ($assessments as $a) {
                $sub = $subs->get($a->id . ':' . $u->id);
                $accepted = (bool) $sub?->isAccepted();
                $visible = $isTeacher || $accepted;
                $score = $accepted ? $sub->score : 0; // no record yet / pending / not accepted = 0

                $scores[$a->id] = [
                    'score' => $accepted || ($isTeacher && ! $sub) ? $score : null,
                    'status' => $sub?->status,
                    'recorded' => (bool) $sub,
                    'submitted' => (bool) $sub?->hasImage(),
                    'submission_id' => $sub?->id,
                ];

                if ($visible) {
                    $type = in_array($a->type, $types, true) ? $a->type : 'quiz';
                    foreach ([$type, 'overall'] as $key) {
                        $totals[$key]['earned'] += $score;
                        $totals[$key]['max'] += (int) $a->total_points;
                    }
                }
            }

            foreach ($totals as &$t) {
                $t['percent'] = $t['max'] > 0 ? round($t['earned'] / $t['max'] * 100, 1) : null;
            }

            return [
                'student_id' => $u->id,
                'name' => $u->name,
                'school_id' => $u->school_id,
                'scores' => $scores,
                'totals' => $totals,
                'attendance' => $attendance['students'][$u->id],
            ];
        });

        return response()->json([
            'class' => ['id' => $class->id, 'name' => $class->name, 'year_level' => $class->year_level, 'section' => $class->section],
            'assessments' => $assessments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'total_points' => (int) $a->total_points,
            ])->values(),
            'sessions_held' => $attendance['held'],
            'students' => $rows->values(),
        ]);
    }

    /**
     * Counts each student's attendance over the class's sessions, one per session, using the Time In status
     * (no Time In record = absent). Sessions dated in the future haven't happened yet and are not counted.
     * @return array{held: int, students: array<int, array{present: int, late: int, excused: int, absent: int, total: int}>}
     */
    private function attendanceCounts(Classroom $class, array $studentIds): array
    {
        $sessionIds = $class->classSessions()
            ->where('session_date', '<=', now(ClassSession::TZ)->toDateString())
            ->pluck('id');

        // status per student per session (first Time In record wins, matching the session roster)
        $statuses = AttendanceRecord::query()
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
            ->whereIn('attendance_sessions.class_session_id', $sessionIds)
            ->whereIn('attendance_records.student_id', $studentIds)
            ->where('attendance_records.stage', 1)
            ->orderBy('attendance_records.id')
            ->get(['attendance_records.student_id', 'attendance_records.status', 'attendance_sessions.class_session_id'])
            ->unique(fn ($r) => $r->student_id . ':' . $r->class_session_id)
            ->groupBy('student_id');

        $held = $sessionIds->count();
        $students = [];
        foreach ($studentIds as $id) {
            $mine = $statuses->get($id, collect())->countBy('status');
            $present = $mine->get('present', 0);
            $late = $mine->get('late', 0);
            $excused = $mine->get('excused', 0);
            $students[$id] = [
                'present' => $present,
                'late' => $late,
                'excused' => $excused,
                'absent' => $held - $present - $late - $excused,
                'total' => $held,
            ];
        }

        return ['held' => $held, 'students' => $students];
    }

    private function csv(string $filename, array $header, $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'cell'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet formulas (=, +, -, @) in user-entered text from running. */
    private function cell($value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}