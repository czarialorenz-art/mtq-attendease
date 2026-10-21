<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ClassSessionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

// ---------- Public ----------
// Rate-limited per account (see AppServiceProvider) to slow down password guessing
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// ---------- Authenticated ----------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/classes/{class}/schedule', [ClassController::class, 'schedule']);

    // Classes
    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store']);
    Route::post('/classes/join', [ClassController::class, 'join']);
    Route::put('/classes/{class}', [ClassController::class, 'update']);
    Route::get('/classes/{class}/students', [ClassController::class, 'students']);
    Route::post('/classes/{class}/students/{enrollment}/approve', [ClassController::class, 'approveStudent']);
    Route::post('/classes/{class}/students/{enrollment}/reject', [ClassController::class, 'rejectStudent']);
    Route::delete('/classes/{class}/students/{enrollment}', [ClassController::class, 'removeStudent']);

    // Attendance sessions (dated; teacher manages, approved students view their own status)
    Route::get('/classes/{class}/sessions', [ClassSessionController::class, 'index']);
    Route::post('/classes/{class}/sessions', [ClassSessionController::class, 'store']);
    Route::get('/class-sessions/{classSession}', [ClassSessionController::class, 'show']);
    Route::put('/class-sessions/{classSession}', [ClassSessionController::class, 'update']);
    Route::delete('/class-sessions/{classSession}', [ClassSessionController::class, 'destroy']);
    Route::post('/class-sessions/{classSession}/students/{student}/status', [ClassSessionController::class, 'setStudentStatus']);

    // Attendance
    Route::post('/classes/{class}/attendance/start', [AttendanceController::class, 'start']);
    Route::get('/classes/{class}/attendance/current', [AttendanceController::class, 'current']);
    Route::get('/classes/{class}/attendance/records', [AttendanceController::class, 'records']);
    Route::post('/attendance-sessions/{session}/next', [AttendanceController::class, 'nextStage']);
    Route::post('/attendance-sessions/{session}/refresh', [AttendanceController::class, 'refresh']);
    Route::post('/attendance-sessions/{session}/stop', [AttendanceController::class, 'stop']);
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
    Route::post('/attendance-records/{record}/status', [AttendanceController::class, 'updateStatus']);
    Route::get('/my-attendance', [AttendanceController::class, 'myRecords']);

    // Assessments
    Route::get('/classes/{class}/assessments', [AssessmentController::class, 'index']);
    Route::post('/classes/{class}/assessments', [AssessmentController::class, 'store']);
    Route::put('/assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy']);
    Route::post('/assessments/{assessment}/submit', [AssessmentController::class, 'submit']);
    Route::get('/assessments/{assessment}/submissions', [AssessmentController::class, 'submissions']);
    Route::post('/submissions/{submission}/verify', [AssessmentController::class, 'verify']);
    Route::post('/submissions/{submission}/decision', [AssessmentController::class, 'decision']);
    Route::get('/submissions/{submission}/image', [AssessmentController::class, 'image']);
    Route::put('/assessments/{assessment}/scores/{student}', [AssessmentController::class, 'setScore']);
    Route::get('/my-assessments', [AssessmentController::class, 'myAssessments']);

    // Calendar
    Route::get('/calendar', [CalendarController::class, 'index']);
    Route::post('/calendar', [CalendarController::class, 'store']);
    Route::put('/calendar/{event}', [CalendarController::class, 'update']);
    Route::delete('/calendar/{event}', [CalendarController::class, 'destroy']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-read', [NotificationController::class, 'markAllRead']);

    // Reports
    Route::get('/classes/{class}/reports/attendance.csv', [ReportController::class, 'attendanceCsv']);
    Route::get('/classes/{class}/reports/scores.csv', [ReportController::class, 'scoresCsv']);
    Route::get('/classes/{class}/report-card', [ReportController::class, 'reportCard']);
});
