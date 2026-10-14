<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A dated attendance session of a class (subject). Its QR windows are AttendanceSession rows. */
class ClassSession extends Model
{
    public const TZ = 'Asia/Manila';

    protected $fillable = ['classroom_id', 'title', 'session_date', 'starts_at', 'ends_at'];
    protected $casts = ['session_date' => 'date'];

    public function classroom() { return $this->belongsTo(Classroom::class); }
    public function attendanceSessions() { return $this->hasMany(AttendanceSession::class); }
    public function records() { return $this->hasManyThrough(AttendanceRecord::class, AttendanceSession::class); }

    public function dateString(): string
    {
        return $this->session_date->toDateString();
    }

    public function isToday(): bool
    {
        return $this->dateString() === now(self::TZ)->toDateString();
    }

    /** QR codes for this session stop working when the session's day ends. */
    public function qrExpiresAt(): Carbon
    {
        return Carbon::parse($this->dateString(), self::TZ)->endOfDay()->utc();
    }
}
