<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AssessmentController extends Controller
{
    // The teacher sets each assessment's maximum; the only ceiling is what the database column can hold
    private const MAX_POINTS = 4294967295;
    private const POINTS_MESSAGES = [
        'total_points.required' => 'Enter the maximum score for this assessment.',
        'total_points.min' => 'The maximum score must be at least 1.',
        'total_points.max' => 'The maximum score is too large.',
        'total_points.integer' => 'The maximum score must be a whole number.',
    ];

    public function index(Request $request, Classroom $class)
    {
        $user = $request->user();

        if ($user->isTeacher()) {
            abort_unless($class->teacher_id === $user->id, 403, 'Not your class.');
        } else {
            abort_unless($this->isApproved($class->id, $user->id), 403, 'Not enrolled in this class.');
        }

        $assessments = $class->assessments()
            ->withCount(['submissions' => fn ($q) => $q->whereIn('student_id', $class->approvedStudentIds())])
            ->latest()->get()
            ->map(function ($a) use ($user) {
                $data = $this->format($a, $user->isTeacher());
                if ($user->isStudent()) {
                    $data += $this->studentResult($a, $a->submissions()->where('student_id', $user->id)->first());
                }
                return $data;
            });

        return response()->json($assessments);
    }

    public function store(Request $request, Classroom $class)
    {
        abort_unless($class->teacher_id === $request->user()->id, 403, 'Not your class.');

        $data = $request->validate([
    'title' => 'required|string|max:255',
    'description' => 'nullable|string',
    'total_points' => 'required|integer|min:1|max:' . self::MAX_POINTS,
    'opens_at' => 'nullable|date',
    'closes_at' => 'nullable|date',
    'type' => 'nullable|in:quiz,exam,activity',
    'due_date' => 'nullable|date',
    ], self::POINTS_MESSAGES);

        $data['type'] = $data['type'] ?? 'quiz';
        if (! empty($data['due_date'])) {
        $data['closes_at'] = $data['due_date'];
        }
        unset($data['due_date']);

        $assessment = $class->assessments()->create($data);

        return response()->json($this->format($assessment, true), 201);
    }

    public function update(Request $request, Assessment $assessment)
{
    $this->authorizeTeacher($request, $assessment);

    $data = $request->validate([
        'title' => 'required|string|max:255',
        'opens_at' => 'nullable|date',
        'closes_at' => 'nullable|date',
        'total_points' => 'nullable|integer|min:1|max:' . self::MAX_POINTS,
    ], self::POINTS_MESSAGES);

    $maxGiven = (int) $assessment->submissions()->max('score');
    if (! empty($data['total_points']) && $data['total_points'] < $maxGiven) {
        return response()->json(['message' => "Maximum points can't be lower than an existing score ({$maxGiven})."], 422);
    }

    if (! empty($data['opens_at']) && ! empty($data['closes_at'])
        && Carbon::parse($data['closes_at'])->lte(Carbon::parse($data['opens_at']))) {
        return response()->json(['message' => 'The end time must be after the start time.'], 422);
    }

    $assessment->update([
        'title' => trim($data['title']),
        'opens_at' => $data['opens_at'] ?? null,
        'closes_at' => $data['closes_at'] ?? null,
        'total_points' => $data['total_points'] ?? $assessment->total_points,
    ]);

    return response()->json($this->format($assessment->fresh(), true));
}

private function windowError(Assessment $a): ?string
{
    $fmt = fn ($t) => Carbon::parse($t)->timezone('Asia/Manila')->format('M j, Y g:i A');

    if ($a->opens_at && Carbon::parse($a->opens_at)->isFuture()) {
        return $a->title . ' opens on ' . $fmt($a->opens_at) . '.';
    }
    if ($a->closes_at && Carbon::parse($a->closes_at)->isPast()) {
        return $a->title . ' ended on ' . $fmt($a->closes_at) . '.';
    }

    return null;
}

    public function destroy(Request $request, Assessment $assessment)
    {
        $this->authorizeTeacher($request, $assessment);

        $assessment->submissions->each(fn ($s) => $this->deleteImage($s->answer));
        $assessment->submissions()->delete();
        $assessment->delete();

        return response()->json(['message' => 'Deleted.']);
    }
    
    public function submit(Request $request, Assessment $assessment)
    {
        $user = $request->user();
        abort_unless($user->isStudent(), 403, 'Only students can submit.');
        abort_unless($this->isApproved($assessment->classroom_id, $user->id), 403, 'You are not enrolled in this class.');

        if ($message = $this->windowError($assessment)) {
            return response()->json(['message' => $message], 403);
        }

        $data = $request->validate([
            'image' => 'required|string',
            'answer' => 'nullable|string',
        ]);

        if (! preg_match('#^data:image/(jpeg|png|webp);base64,#', $data['image'], $m)) {
            return response()->json(['message' => 'Invalid image.'], 422);
        }

        $binary = base64_decode(substr($data['image'], strpos($data['image'], ',') + 1), true);
        if ($binary === false || strlen($binary) > 8 * 1024 * 1024) {
            return response()->json(['message' => 'The photo is invalid or too large.'], 422);
        }

        $existing = Submission::where('assessment_id', $assessment->id)->where('student_id', $user->id)->first();
        if ($existing?->is_verified) {
            return response()->json(['message' => 'Already verified by your teacher.'], 409);
        }

        if (@getimagesizefromstring($binary) === false) {
            return response()->json(['message' => 'The photo could not be read. Please retake it.'], 422);
        }

        // Private disk: photos are only served through the authenticated image endpoint
        $path = 'submissions/' . $assessment->id . '/' . $user->id . '-' . time() . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        if (! Storage::disk('local')->put($path, $binary)) {
            return response()->json(['message' => 'Could not save the photo. Please try again.'], 500);
        }

        if ($existing) {
            $this->deleteImage($existing->answer);
        }

        $submission = Submission::updateOrCreate(
            ['assessment_id' => $assessment->id, 'student_id' => $user->id],
            ['answer' => $path, 'submitted_at' => now()]
        );

        UserNotification::create([
            'user_id' => $assessment->classroom->teacher_id,
            'title' => 'New submission',
            'message' => "{$user->name} submitted {$assessment->title}.",
        ]);

        return response()->json([
            'id' => $submission->id,
            'assessment_id' => $submission->assessment_id,
            'submitted_at' => $submission->submitted_at,
            'image_url' => $submission->imageUrl(),
        ], 201);
    }

    public function submissions(Request $request, Assessment $assessment)
    {
        $this->authorizeTeacher($request, $assessment);

        $rows = $assessment->submissions()->with('student:id,name,email')
            ->whereIn('student_id', $assessment->classroom->approvedStudentIds())->get()
            ->sortBy(fn ($s) => mb_strtolower((string) $s->student?->name))->values()
            ->map(fn ($s) => [
                'id' => $s->id,
                'student_id' => $s->student_id,
                'student' => ['id' => $s->student_id, 'name' => $s->student?->name, 'email' => $s->student?->email],
                'email' => $s->student?->email,
                'answer' => $s->imageUrl(),
                'image_url' => $s->imageUrl(),
                'has_image' => $s->hasImage(),
                'verification' => $s->is_verified ? 'verified' : 'pending',
                'score' => $s->score,
                'is_verified' => $s->is_verified,
                'submitted_at' => $s->submitted_at,
            ]);

        return response()->json($rows);
    }

    public function verify(Request $request, Submission $submission)
    {
        abort_unless($submission->assessment->classroom->teacher_id === $request->user()->id, 403, 'Not your class.');

        $score = $request->input('score');
        if ($score !== null && $score !== '') {
            [$score, $error] = $this->parseScore($score, $submission->assessment);
            if ($error) {
                return response()->json(['message' => $error, 'errors' => ['score' => [$error]]], 422);
            }
        } else {
            $score = $submission->score;
        }

        $submission->update([
            'score' => $score,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        UserNotification::create([
            'user_id' => $submission->student_id,
            'title' => 'Submission verified',
            'message' => "Your submission for {$submission->assessment->title} was verified.",
        ]);

        return response()->json(['message' => 'Submission verified.', 'submission' => $submission->fresh()]);
    }

    public function myAssessments(Request $request)
    {
        $user = $request->user();

        $assessments = Assessment::whereHas('classroom.enrollments', fn ($q) => $q->where('student_id', $user->id)->where('status', 'approved'))
            ->with('classroom:id,name')->latest()->get()
            ->map(function ($a) use ($user) {
                $sub = $a->submissions()->where('student_id', $user->id)->first();
                $result = $this->studentResult($a, $sub);
                return $this->format($a, false) + $result + [
                'class' => $a->classroom->name,
                'due_date' => $a->closes_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
                'my_submission' => $sub && $sub->hasImage() ? [
                    'verification' => $sub->is_verified ? 'verified' : 'pending',
                    'score' => $result['score'],
                    'image_url' => $sub->imageUrl(),
                ] : null,
            ];
            });

        return response()->json($assessments);
    }

    /** Teacher creates or updates one student's score ("20", "20/50"). Never creates a duplicate record. */
    public function setScore(Request $request, Assessment $assessment, User $student)
    {
        $this->authorizeTeacher($request, $assessment);
        abort_unless($this->isApproved($assessment->classroom_id, $student->id), 404, 'Student is not enrolled in this class.');

        [$score, $error] = $this->parseScore($request->input('score'), $assessment);
        if ($error) {
            return response()->json(['message' => $error, 'errors' => ['score' => [$error]]], 422);
        }

        $submission = Submission::firstOrNew(['assessment_id' => $assessment->id, 'student_id' => $student->id]);
        $submission->fill(['score' => $score, 'is_verified' => true, 'verified_at' => now()]);
        $submission->save();

        return response()->json([
            'id' => $submission->id,
            'student_id' => $student->id,
            'assessment_id' => $assessment->id,
            'score' => $submission->score,
            'total_points' => $assessment->total_points,
        ]);
    }

    /** Teacher releases (or hides again) the scores of an assessment to students. */
    public function release(Request $request, Assessment $assessment)
    {
        $this->authorizeTeacher($request, $assessment);

        $released = $request->boolean('released', true);
        $assessment->update(['scores_released_at' => $released ? ($assessment->scores_released_at ?? now()) : null]);

        if ($released && $assessment->wasChanged('scores_released_at')) {
            $studentIds = Enrollment::where('classroom_id', $assessment->classroom_id)
                ->where('status', 'approved')->pluck('student_id');
            foreach ($studentIds as $id) {
                UserNotification::create([
                    'user_id' => $id,
                    'title' => 'Scores released',
                    'message' => "Scores for {$assessment->title} are now available.",
                ]);
            }
        }

        return response()->json($this->format($assessment->fresh(), true));
    }

    /** Streams a submission photo to the class teacher or the student who submitted it. */
    public function image(Request $request, Submission $submission)
    {
        $user = $request->user();
        abort_unless(
            $submission->student_id === $user->id || $submission->assessment?->classroom?->teacher_id === $user->id,
            403, 'You cannot view this submission.'
        );

        [$disk, $path] = $this->locateImage($submission->answer);
        if (! $disk) {
            return response()->json(['message' => 'The submitted image could not be found.'], 404);
        }

        $mime = Storage::disk($disk)->mimeType($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return response()->json(['message' => 'Unsupported image format.'], 415);
        }

        return Storage::disk($disk)->response($path, basename($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Authorization',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Accepts 20, "20" or "20/50". The maximum is the assessment's total points; a different
     * denominator is rejected so one assessment never mixes scales.
     * @return array{0: ?int, 1: ?string} [score, error]
     */
    private function parseScore($input, Assessment $assessment): array
    {
        $max = (int) $assessment->total_points;
        $raw = trim((string) $input);

        if (! preg_match('#^(\d+)(?:\s*/\s*(\d+))?$#', $raw, $m)) {
            return [null, "Enter a whole-number score such as 20 or 20/{$max}. Scores can't be negative."];
        }
        if (isset($m[2]) && (int) $m[2] !== $max) {
            return [null, "This assessment is out of {$max} points. Enter a score out of {$max}, or edit the assessment's maximum points first."];
        }
        if ((int) $m[1] > $max) {
            return [null, "The score can't be higher than the maximum of {$max}."];
        }

        return [(int) $m[1], null];
    }

    /** What a student may see about their own result: the score only once the teacher releases it. */
    private function studentResult(Assessment $a, ?Submission $sub): array
    {
        $released = $a->scoresReleased();

        return [
            'submitted' => (bool) $sub?->hasImage(),
            'is_verified' => $sub?->is_verified ?? false,
            'score_released' => $released,
            'score' => $released ? ($sub?->score ?? 0) : null,
        ];
    }

    /** Old rows store "/storage/submissions/..." on the public disk; new rows store "submissions/..." on the private disk. */
    private function locateImage(?string $answer): array
    {
        if (! is_string($answer) || $answer === '') {
            return [null, null];
        }
        $path = ltrim(preg_replace('#^/?storage/#', '', $answer), '/');
        if (str_contains($path, '..') || ! str_starts_with($path, 'submissions/')) {
            return [null, null];
        }
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return [$disk, $path];
            }
        }

        return [null, null];
    }

    private function deleteImage(?string $answer): void
    {
        [$disk, $path] = $this->locateImage($answer);
        if ($disk) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function isApproved(int $classId, int $userId): bool
    {
        return Enrollment::where('classroom_id', $classId)
            ->where('student_id', $userId)->where('status', 'approved')->exists();
    }

    private function authorizeTeacher(Request $request, Assessment $assessment): void
    {
        abort_unless($assessment->classroom->teacher_id === $request->user()->id, 403, 'Not your class.');
    }

    private function format(Assessment $a, bool $withToken): array
    {
        $data = [
            'id' => $a->id,
            'class_id' => $a->classroom_id,
            'title' => $a->title,
            'created_at' => $a->created_at,
            'type' => $a->type,
            'description' => $a->description,
            'total_points' => $a->total_points,
            'opens_at' => $a->opens_at,
            'closes_at' => $a->closes_at,
            'submissions_count' => $a->submissions_count ?? null,
            'scores_released' => $a->scoresReleased(),
            'scores_released_at' => $a->scores_released_at,
        ];

        return $data;
    }
}