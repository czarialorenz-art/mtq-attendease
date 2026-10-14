<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One QR window (Time In or Time Out) inside an attendance session (ClassSession).
 * mode = 'manual' marks the hidden window that holds statuses the teacher set by hand.
 */
class AttendanceSession extends Model
{
    protected $fillable = ['classroom_id', 'class_session_id', 'mode', 'stage', 'qr_token',
                           'status', 'started_at', 'ended_at', 'expires_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function classroom() { return $this->belongsTo(Classroom::class); }
    public function records() { return $this->hasMany(AttendanceRecord::class); }
    public function classSession() { return $this->belongsTo(ClassSession::class); }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
