<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'school_id', 'course', 'year_level', 'section', 'department'];

    protected $hidden = ['password', 'remember_token', 'verification_code', 'verification_attempts'];

    /** Choices a student picks when signing up or editing their profile. */
    public const SECTIONS = ['A', 'B', 'C'];
    public const COURSES = ['BSIT'];
    public const YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'verification_sent_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isTeacher(): bool { return $this->role === 'teacher'; }
    public function isStudent(): bool { return $this->role === 'student'; }

    public function classrooms() { return $this->hasMany(Classroom::class, 'teacher_id'); }
    public function enrollments() { return $this->hasMany(Enrollment::class, 'student_id'); }
    public function submissions() { return $this->hasMany(Submission::class, 'student_id'); }
    public function attendanceRecords() { return $this->hasMany(AttendanceRecord::class, 'student_id'); }
    public function calendarEvents() { return $this->hasMany(CalendarEvent::class); }
    public function userNotifications() { return $this->hasMany(UserNotification::class); }
}