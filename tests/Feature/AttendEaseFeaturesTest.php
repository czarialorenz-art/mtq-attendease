<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Runs against a MySQL copy of the real schema (the old migration files don't rebuild it on SQLite):
 *   DB_CONNECTION=mysql DB_DATABASE=laravel_test php artisan test --filter=AttendEaseFeaturesTest
 */
class AttendEaseFeaturesTest extends TestCase
{
    use DatabaseTransactions;

    private User $teacher;
    private User $otherTeacher;
    private User $alice;
    private User $bob;
    private Classroom $class;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? '')) !== 'mysql') {
            $this->markTestSkipped('Needs the MySQL schema: DB_CONNECTION=mysql DB_DATABASE=laravel_test');
        }

        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $make = fn ($role, $n) => User::create([
            'name' => $n, 'email' => strtolower($n) . uniqid() . '@mqcqc.ph.education',
            'password' => 'Secret#123', 'role' => $role, 'school_id' => 'T-' . uniqid(),
        ]);
        $this->teacher = $make('teacher', 'Teacher');
        $this->otherTeacher = $make('teacher', 'Other');
        $this->alice = $make('student', 'Alice');
        $this->bob = $make('student', 'Bob');

        $this->class = Classroom::create(['teacher_id' => $this->teacher->id, 'name' => 'Math', 'join_code' => strtoupper(substr(uniqid(), -6))]);
        foreach ([$this->alice, $this->bob] as $s) {
            Enrollment::create(['classroom_id' => $this->class->id, 'student_id' => $s->id, 'status' => 'approved']);
        }
    }

    private function today(): string
    {
        return now('Asia/Manila')->toDateString();
    }

    public function test_teacher_creates_session_edits_date_and_records_land_in_it(): void
    {
        Sanctum::actingAs($this->teacher);
        $session = $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => '2026-01-05', 'title' => 'Lecture 1'])
            ->assertCreated()->assertJsonPath('date', '2026-01-05')->json();
        $this->assertCount(2, $session['students']);
        $this->assertSame('absent', $session['students'][0]['status']);

        $this->putJson("/api/class-sessions/{$session['id']}", ['session_date' => $this->today(), 'title' => 'Lecture 1'])
            ->assertOk()->assertJsonPath('date', $this->today());

        // QR for that session, Alice scans it
        $qr = $this->postJson("/api/classes/{$this->class->id}/attendance/start", ['stage' => 1, 'class_session_id' => $session['id']])
            ->assertCreated()->json();
        Sanctum::actingAs($this->alice);
        $this->postJson('/api/attendance/scan', ['token' => $qr['qr_token']])->assertCreated();
        $this->postJson('/api/attendance/scan', ['token' => $qr['qr_token']])->assertOk()->assertJsonPath('message', 'Already recorded for this stage.');

        // Teacher sees Alice present, Bob absent; marks Bob excused without a scan
        Sanctum::actingAs($this->teacher);
        $rows = collect($this->getJson("/api/class-sessions/{$session['id']}")->assertOk()->json('students'))->keyBy('name');
        $this->assertSame('present', $rows['Alice']['status']);
        $this->assertSame('absent', $rows['Bob']['status']);
        $this->postJson("/api/class-sessions/{$session['id']}/students/{$this->bob->id}/status", ['status' => 'excused'])->assertOk();
        $this->postJson("/api/class-sessions/{$session['id']}/students/{$this->bob->id}/status", ['status' => 'late'])->assertOk();
        $rows = collect($this->getJson("/api/class-sessions/{$session['id']}")->json('students'))->keyBy('name');
        $this->assertSame('late', $rows['Bob']['status']);
        $this->assertSame(1, \App\Models\AttendanceRecord::where('student_id', $this->bob->id)->count());

        // Student sees only their own row; cannot edit
        Sanctum::actingAs($this->bob);
        $own = $this->getJson("/api/class-sessions/{$session['id']}")->assertOk()->json('students');
        $this->assertCount(1, $own);
        $this->assertSame('Bob', $own[0]['name']);
        $this->getJson("/api/classes/{$this->class->id}/sessions")->assertOk()->assertJsonPath('0.status', 'late');
        $this->postJson("/api/class-sessions/{$session['id']}/students/{$this->bob->id}/status", ['status' => 'present'])->assertForbidden();
        $this->putJson("/api/class-sessions/{$session['id']}", ['session_date' => '2026-01-01'])->assertForbidden();
        $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => '2026-01-01'])->assertForbidden();
        $this->postJson("/api/classes/{$this->class->id}/attendance/start", ['stage' => 1])->assertForbidden();
        $this->getJson("/api/classes/{$this->class->id}/attendance/records")->assertForbidden();

        // Report card counts attendance one per session held; future sessions don't count yet
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => '2026-01-02'])->assertCreated();
        $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => now('Asia/Manila')->addDays(3)->toDateString()])->assertCreated();
        $report = $this->getJson("/api/classes/{$this->class->id}/report-card")->assertOk()->json();
        $this->assertSame(2, $report['sessions_held']);
        $att = collect($report['students'])->keyBy('name')->map(fn ($r) => $r['attendance']);
        $this->assertSame(['present' => 1, 'late' => 0, 'excused' => 0, 'absent' => 1, 'total' => 2], $att['Alice']);
        $this->assertSame(['present' => 0, 'late' => 1, 'excused' => 0, 'absent' => 1, 'total' => 2], $att['Bob']);

        Sanctum::actingAs($this->bob);
        $own = $this->getJson("/api/classes/{$this->class->id}/report-card")->assertOk()->json('students');
        $this->assertCount(1, $own);
        $this->assertSame(1, $own[0]['attendance']['late']);

        // Section filter: sessions list counts only that section's students; roster carries each student's section
        $this->alice->update(['section' => 'IT-4A']);
        $this->bob->update(['section' => 'IT 4-B']);
        Sanctum::actingAs($this->teacher);
        $bySection = fn ($sec) => collect($this->getJson("/api/classes/{$this->class->id}/sessions?section=" . urlencode($sec))->assertOk()->json())->firstWhere('id', $session['id']);
        $a = $bySection('IT-4A');
        $this->assertSame([1, 0, 0, 1], [$a['present_count'], $a['late_count'], $a['absent_count'], $a['enrolled_count']]);
        $b = $bySection('IT 4-B');
        $this->assertSame([0, 1, 0, 1], [$b['present_count'], $b['late_count'], $b['absent_count'], $b['enrolled_count']]);
        $this->assertSame(0, $bySection('__none__')['enrolled_count']);
        $this->assertSame(2, collect($this->getJson("/api/classes/{$this->class->id}/sessions")->json())->firstWhere('id', $session['id'])['enrolled_count']);
        $roster = collect($this->getJson("/api/class-sessions/{$session['id']}")->json('students'))->keyBy('name');
        $this->assertSame('IT 4-B', $roster['Bob']['section']);
        $this->assertSame('IT-4A', collect($this->getJson("/api/classes/{$this->class->id}/attendance/records")->json())->firstWhere('student', 'Alice')['section']);
        Sanctum::actingAs($this->bob);

        // Deleting: only the class teacher; removes the session's QR windows and records too
        $this->deleteJson("/api/class-sessions/{$session['id']}")->assertForbidden();
        Sanctum::actingAs($this->otherTeacher);
        $this->deleteJson("/api/class-sessions/{$session['id']}")->assertForbidden();

        Sanctum::actingAs($this->teacher);
        $windowIds = AttendanceSession::where('class_session_id', $session['id'])->pluck('id');
        $this->assertNotEmpty($windowIds);
        $this->deleteJson("/api/class-sessions/{$session['id']}")->assertOk()->assertJsonPath('deleted_records', 2);
        $this->assertDatabaseMissing('class_sessions', ['id' => $session['id']]);
        $this->assertSame(0, AttendanceSession::whereIn('id', $windowIds)->count());
        $this->assertSame(0, \App\Models\AttendanceRecord::whereIn('attendance_session_id', $windowIds)->count());
        $this->scan404AfterDelete($qr['qr_token']);
        $this->assertSame(1, $this->getJson("/api/classes/{$this->class->id}/report-card")->json('sessions_held'));
        $this->getJson("/api/class-sessions/{$session['id']}")->assertNotFound();
    }

    private function scan404AfterDelete(string $token): void
    {
        Sanctum::actingAs($this->alice);
        $this->postJson('/api/attendance/scan', ['token' => $token])->assertNotFound();
        Sanctum::actingAs($this->teacher);
    }

    public function test_qr_is_valid_only_on_its_session_date(): void
    {
        Sanctum::actingAs($this->teacher);
        $qr = $this->postJson("/api/classes/{$this->class->id}/attendance/start", ['stage' => 1])->assertCreated()->json();
        $this->assertNotEmpty($qr['qr_token']);
        $this->assertNotNull($qr['expires_at']);

        // Cannot start QR for a session that is not today
        $past = $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => '2026-01-05'])->json();
        $this->postJson("/api/classes/{$this->class->id}/attendance/start", ['stage' => 1, 'class_session_id' => $past['id']])->assertStatus(422);

        // Next day: the same QR is rejected, even though the teacher never stopped it
        $this->travel(1)->days();
        Sanctum::actingAs($this->alice);
        $this->postJson('/api/attendance/scan', ['token' => $qr['qr_token']])
            ->assertStatus(410)->assertJsonFragment(['message' => 'This QR code has expired. Attendance QR codes are valid only on the session date (' . now('Asia/Manila')->subDay()->format('M j, Y') . ').']);

        // Records and the session itself are kept
        $this->assertDatabaseHas('attendance_sessions', ['id' => $qr['id']]);

        // Editing a session's date to the past expires its QR too
        $this->travelBack();
        Sanctum::actingAs($this->teacher);
        $this->putJson("/api/class-sessions/{$qr['class_session_id']}", ['session_date' => '2026-01-04'])->assertOk();
        Sanctum::actingAs($this->bob);
        $this->postJson('/api/attendance/scan', ['token' => $qr['qr_token']])->assertStatus(410);
        $this->postJson('/api/attendance/scan', ['token' => 'not-a-real-token'])->assertNotFound();
    }

    public function test_scores_default_to_zero_parse_fractions_and_feed_report_card(): void
    {
        Sanctum::actingAs($this->teacher);
        $quiz = $this->postJson("/api/classes/{$this->class->id}/assessments", ['title' => 'Quiz 1', 'type' => 'quiz', 'total_points' => 50])->assertCreated()->json();
        $exam = $this->postJson("/api/classes/{$this->class->id}/assessments", ['title' => 'Midterm', 'type' => 'exam', 'total_points' => 100])->assertCreated()->json();

        // The teacher must set the maximum; large values are fine
        $this->postJson("/api/classes/{$this->class->id}/assessments", ['title' => 'No max', 'type' => 'quiz'])
            ->assertStatus(422)->assertJsonPath('errors.total_points.0', 'Enter the maximum score for this assessment.');
        $big = $this->postJson("/api/classes/{$this->class->id}/assessments", ['title' => 'Big', 'type' => 'activity', 'total_points' => 250000])->assertCreated()->json();
        $this->putJson("/api/assessments/{$big['id']}", ['title' => 'Big', 'total_points' => 1000000])->assertOk()->assertJsonPath('total_points', 1000000);
        $this->putJson("/api/assessments/{$big['id']}/scores/{$this->bob->id}", ['score' => '750000/1000000'])->assertOk()->assertJsonPath('score', 750000);
        \App\Models\Assessment::destroy($big['id']);

        // Default 0 on a new record
        $sub = Submission::create(['assessment_id' => $quiz['id'], 'student_id' => $this->alice->id]);
        $this->assertSame(0, $sub->fresh()->score);

        // Report includes every enrolled student, with 0 for no record yet
        $report = $this->getJson("/api/classes/{$this->class->id}/report-card")->assertOk()->json();
        $this->assertCount(2, $report['students']);
        $this->assertNotNull($report['students'][0]['school_id']);
        $this->assertSame(0, $report['students'][1]['scores'][$quiz['id']]['score']);

        // "20/50" format, validation, update in place
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '20/50'])->assertOk()->assertJsonPath('score', 20);
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '45'])->assertOk();
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '51/50'])->assertStatus(422);
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '60'])->assertStatus(422);
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '-1'])->assertStatus(422);
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '20/40'])->assertStatus(422);
        $this->putJson("/api/assessments/{$exam['id']}/scores/{$this->bob->id}", ['score' => '88/100'])->assertOk();
        $this->assertSame(1, Submission::where('assessment_id', $quiz['id'])->where('student_id', $this->alice->id)->count());

        $rows = collect($this->getJson("/api/classes/{$this->class->id}/report-card")->json('students'))->keyBy('name');
        $this->assertSame(45, $rows['Alice']['scores'][$quiz['id']]['score']);
        $this->assertEquals(['earned' => 45, 'max' => 150, 'percent' => 30.0], $rows['Alice']['totals']['overall']);
        $this->assertSame(88, $rows['Bob']['scores'][$exam['id']]['score']);

        // Other subject's report stays separate
        $other = Classroom::create(['teacher_id' => $this->teacher->id, 'name' => 'Science', 'join_code' => strtoupper(substr(uniqid(), -6))]);
        $this->assertSame([], $this->getJson("/api/classes/{$other->id}/report-card")->json('assessments'));

        // Students: unreleased scores hidden everywhere; cannot modify or release
        Sanctum::actingAs($this->alice);
        $mine = $this->getJson("/api/classes/{$this->class->id}/report-card")->assertOk()->json();
        $this->assertCount(1, $mine['students']);
        $this->assertNull($mine['students'][0]['scores'][$quiz['id']]['score']);
        $this->assertSame(0, $mine['students'][0]['totals']['overall']['max']);
        $this->assertNull(collect($this->getJson('/api/my-assessments')->json())->firstWhere('id', $quiz['id'])['score']);
        $this->assertNull(collect($this->getJson("/api/classes/{$this->class->id}/assessments")->json())->firstWhere('id', $quiz['id'])['score']);
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '50'])->assertForbidden();
        $this->postJson("/api/submissions/{$sub->id}/verify", ['score' => 50])->assertForbidden();
        $this->postJson("/api/assessments/{$quiz['id']}/release")->assertForbidden();
        $this->getJson("/api/assessments/{$quiz['id']}/submissions")->assertForbidden();

        // Release the quiz only
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/assessments/{$quiz['id']}/release", ['released' => true])->assertOk()->assertJsonPath('scores_released', true);
        Sanctum::actingAs($this->alice);
        $mine = $this->getJson("/api/classes/{$this->class->id}/report-card")->json('students.0');
        $this->assertSame(45, $mine['scores'][$quiz['id']]['score']);
        $this->assertNull($mine['scores'][$exam['id']]['score']);
        $this->assertEquals(['earned' => 45, 'max' => 50, 'percent' => 90.0], $mine['totals']['overall']);
        $this->assertSame(45, collect($this->getJson('/api/my-assessments')->json())->firstWhere('id', $quiz['id'])['score']);

        // Another teacher has no access
        Sanctum::actingAs($this->otherTeacher);
        $this->getJson("/api/classes/{$this->class->id}/report-card")->assertForbidden();
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->alice->id}", ['score' => '1'])->assertForbidden();
    }

    public function test_teacher_can_remove_a_student_and_their_history_is_kept(): void
    {
        Sanctum::actingAs($this->teacher);
        $session = $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => '2026-01-05'])->json();
        $this->postJson("/api/class-sessions/{$session['id']}/students/{$this->bob->id}/status", ['status' => 'late'])->assertOk();
        $quiz = $this->postJson("/api/classes/{$this->class->id}/assessments", ['title' => 'Q', 'type' => 'quiz', 'total_points' => 10])->json();
        $this->putJson("/api/assessments/{$quiz['id']}/scores/{$this->bob->id}", ['score' => '7'])->assertOk();
        $bobEnrollment = Enrollment::where('classroom_id', $this->class->id)->where('student_id', $this->bob->id)->first();

        // Only the class teacher can remove
        Sanctum::actingAs($this->alice);
        $this->deleteJson("/api/classes/{$this->class->id}/students/{$bobEnrollment->id}")->assertForbidden();
        Sanctum::actingAs($this->otherTeacher);
        $this->deleteJson("/api/classes/{$this->class->id}/students/{$bobEnrollment->id}")->assertForbidden();
        $otherClass = Classroom::create(['teacher_id' => $this->teacher->id, 'name' => 'Other', 'join_code' => strtoupper(substr(uniqid(), -6))]);
        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/classes/{$otherClass->id}/students/{$bobEnrollment->id}")->assertNotFound();

        $this->deleteJson("/api/classes/{$this->class->id}/students/{$bobEnrollment->id}")->assertOk();
        $this->assertDatabaseMissing('enrollments', ['id' => $bobEnrollment->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->bob->id, 'title' => 'Removed from class']);

        // Gone from every class view
        $names = fn ($rows, $key = 'name') => array_column($rows, $key);
        $this->assertNotContains('Bob', array_column(array_column($this->getJson("/api/classes/{$this->class->id}/students")->json(), 'student'), 'name'));
        $this->assertSame(['Alice'], $names($this->getJson("/api/class-sessions/{$session['id']}")->json('students')));
        $this->assertSame(['Alice'], $names($this->getJson("/api/classes/{$this->class->id}/report-card")->json('students')));
        $this->assertNotContains('Bob', $names($this->getJson("/api/classes/{$this->class->id}/attendance/records")->json(), 'student'));
        $this->assertSame([], $this->getJson("/api/assessments/{$quiz['id']}/submissions")->json());
        $this->assertSame(0, collect($this->getJson("/api/classes/{$this->class->id}/assessments")->json())->firstWhere('id', $quiz['id'])['submissions_count']);

        // Bob lost access; his data is still stored
        Sanctum::actingAs($this->bob);
        $this->getJson("/api/classes/{$this->class->id}/sessions")->assertForbidden();
        $this->getJson("/api/classes/{$this->class->id}/assessments")->assertForbidden();
        $this->assertSame(1, \App\Models\AttendanceRecord::where('student_id', $this->bob->id)->count());
        $this->assertSame(7, Submission::where('student_id', $this->bob->id)->value('score'));

        // Rejoin + approval brings the history back
        $this->postJson('/api/classes/join', ['code' => $this->class->join_code])->assertCreated();
        Sanctum::actingAs($this->teacher);
        $enr = Enrollment::where('classroom_id', $this->class->id)->where('student_id', $this->bob->id)->first();
        $this->postJson("/api/classes/{$this->class->id}/students/{$enr->id}/approve")->assertOk();
        $bob = collect($this->getJson("/api/classes/{$this->class->id}/report-card")->json('students'))->firstWhere('name', 'Bob');
        $this->assertSame(7, $bob['scores'][$quiz['id']]['score']);
        $this->assertSame(1, $bob['attendance']['late']);
    }

    public function test_student_lists_are_alphabetical(): void
    {
        // Enrolled after Alice and Bob, one lowercase: newest-first or case-sensitive ordering would misplace them
        foreach (['Zed Uy', 'carl Dizon'] as $n) {
            $u = User::create(['name' => $n, 'email' => uniqid() . '@mqcqc.ph.education', 'password' => 'Secret#123', 'role' => 'student', 'school_id' => 'Z-' . uniqid()]);
            Enrollment::create(['classroom_id' => $this->class->id, 'student_id' => $u->id, 'status' => 'approved']);
        }
        $expected = ['Alice', 'Bob', 'carl Dizon', 'Zed Uy'];

        Sanctum::actingAs($this->teacher);
        $this->assertSame($expected, array_column(array_column($this->getJson("/api/classes/{$this->class->id}/students")->json(), 'student'), 'name'));

        $session = $this->postJson("/api/classes/{$this->class->id}/sessions", ['session_date' => '2026-01-05'])->json();
        $this->assertSame($expected, array_column($session['students'], 'name'));
        $this->assertSame($expected, array_column($this->getJson("/api/classes/{$this->class->id}/report-card")->json('students'), 'name'));
    }

    public function test_students_choose_section_a_b_or_c(): void
    {
        $reg = fn (array $extra) => $this->postJson('/api/register', $extra + [
            'name' => 'New Student', 'email' => 'new' . uniqid() . '@mqcqc.ph.education', 'password' => 'Secret#123',
            'role' => 'student', 'school_id' => 'S-' . uniqid(), 'course' => 'BSIT', 'year_level' => '4th Year',
        ]);

        $reg([])->assertStatus(422)->assertJsonPath('errors.section.0', 'Please choose your section (A, B, or C).');
        $reg(['section' => 'IT-4A'])->assertStatus(422);
        $reg(['section' => 'D'])->assertStatus(422);
        // Sign-up now returns "verify your email" instead of the account; check what was saved
        $newest = fn () => User::latest('id')->first();
        $reg(['section' => 'B'])->assertCreated()->assertJsonPath('verification_required', true);
        $this->assertSame('B', $newest()->section);
        // Teachers have no section
        $reg(['role' => 'teacher', 'department' => 'IT'])->assertCreated();
        $this->assertNull($newest()->section);

        // Course and year level are chosen from fixed lists too
        $reg(['section' => 'A', 'course' => null])->assertStatus(422)->assertJsonPath('errors.course.0', 'Please choose your course.');
        $reg(['section' => 'A', 'course' => 'BS Something'])->assertStatus(422);
        $reg(['section' => 'A', 'year_level' => '4th year'])->assertStatus(422);
        $reg(['section' => 'A', 'year_level' => null])->assertStatus(422)->assertJsonPath('errors.year_level.0', 'Please choose your year level.');
        $reg(['section' => 'A', 'year_level' => '1st Year'])->assertCreated();
        $this->assertSame(['1st Year', 'BSIT'], [$newest()->year_level, $newest()->course]);

        Sanctum::actingAs($this->alice);
        $base = ['name' => 'Alice', 'email' => $this->alice->email];
        $this->putJson('/api/profile', $base + ['section' => 'A', 'year_level' => '2nd Year'])->assertStatus(422);
        $this->putJson('/api/profile', $base + ['section' => 'A', 'course' => 'BSIT', 'year_level' => '5th Year'])->assertStatus(422);
        $profile = $base + ['course' => 'BSIT', 'year_level' => '3rd Year'];
        $this->putJson('/api/profile', $profile + ['section' => 'Z'])->assertStatus(422);
        $this->putJson('/api/profile', $profile)->assertStatus(422);
        $this->putJson('/api/profile', $profile + ['section' => 'C'])->assertOk()->assertJsonPath('section', 'C');
    }

    public function test_submitted_picture_is_viewable_by_teacher_and_owner_only(): void
    {
        Sanctum::actingAs($this->teacher);
        $a = $this->postJson("/api/classes/{$this->class->id}/assessments", ['title' => 'Activity 1', 'type' => 'activity', 'total_points' => 10])->json();

        $png = base64_encode(imagepng_bytes());
        Sanctum::actingAs($this->alice);
        $res = $this->postJson("/api/assessments/{$a['id']}/submit", ['image' => 'data:image/png;base64,' . $png])->assertCreated()->json();
        $this->assertSame("/api/submissions/{$res['id']}/image", $res['image_url']);
        $this->assertSame(0, Submission::find($res['id'])->score);
        $this->getJson($res['image_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $this->getJson($res['image_url'])->headers->get('Cache-Control'));

        Sanctum::actingAs($this->teacher);
        $subs = $this->getJson("/api/assessments/{$a['id']}/submissions")->assertOk()->json();
        $this->assertSame($res['image_url'], $subs[0]['image_url']);
        $this->get($subs[0]['image_url'])->assertOk();

        Sanctum::actingAs($this->bob);
        $this->getJson($res['image_url'])->assertForbidden();
        Sanctum::actingAs($this->otherTeacher);
        $this->getJson($res['image_url'])->assertForbidden();

        // Missing file -> clear 404 message, not a broken image
        Storage::disk('local')->delete(Submission::find($res['id'])->answer);
        Sanctum::actingAs($this->teacher);
        $this->getJson($res['image_url'])->assertNotFound()->assertJsonPath('message', 'The submitted image could not be found.');

        // Legacy "/storage/..." rows on the public disk are still served
        Storage::disk('public')->put('submissions/legacy/x.png', imagepng_bytes());
        $legacy = Submission::create(['assessment_id' => $a['id'], 'student_id' => $this->bob->id, 'answer' => '/storage/submissions/legacy/x.png']);
        $this->get("/api/submissions/{$legacy->id}/image")->assertOk();
        $legacy->update(['answer' => '/storage/../../.env']);
        $this->getJson("/api/submissions/{$legacy->id}/image")->assertNotFound();
    }
}

/** A tiny valid 1x1 PNG. */
function imagepng_bytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}
