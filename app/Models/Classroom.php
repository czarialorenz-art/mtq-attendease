<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    protected $fillable = [
        'teacher_id', 'name', 'subject', 'join_code', 'year_level', 'section',
        'time_in_start', 'time_in_end', 'time_out_start', 'time_out_end',
    ];

    public function teacher() { return $this->belongsTo(User::class, 'teacher_id'); }
    public function enrollments() { return $this->hasMany(Enrollment::class); }
    public function attendanceSessions() { return $this->hasMany(AttendanceSession::class); }
    public function assessments() { return $this->hasMany(Assessment::class); }
    public function classSessions() { return $this->hasMany(ClassSession::class); }

    public function approvedStudents()
    {
        return User::whereIn('id', $this->enrollments()->where('status', 'approved')->select('student_id'))
            ->orderBy('name');
    }

    /** Subquery of the ids of students currently in the class (removed students' old records stay hidden). */
    public function approvedStudentIds()
    {
        return $this->enrollments()->where('status', 'approved')->select('student_id');
    }

    public function hasApprovedStudent(int $userId): bool
    {
        return $this->enrollments()->where('student_id', $userId)->where('status', 'approved')->exists();
    }
}
