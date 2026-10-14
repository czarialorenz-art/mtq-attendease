<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Enrollment extends Model
{
    protected $fillable = ['classroom_id', 'student_id', 'status'];

    public function classroom() { return $this->belongsTo(Classroom::class); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
}