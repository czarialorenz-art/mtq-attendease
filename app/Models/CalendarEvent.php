<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = ['user_id', 'classroom_id', 'title', 'description', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function classroom() { return $this->belongsTo(Classroom::class); }
}