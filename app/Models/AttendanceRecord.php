<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class AttendanceRecord extends Model
{
    protected $fillable = ['attendance_session_id', 'student_id', 'stage', 'scanned_at', 'status'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'status' => 'string'];
    }

    public function session() { return $this->belongsTo(AttendanceSession::class, 'attendance_session_id'); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
}