<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Assessment extends Model
{
    protected $fillable = ['classroom_id', 'title', 'description', 'total_points', 'type',
    'qr_token', 'qr_expires_at', 'opens_at', 'closes_at', 'scores_released_at'];

    protected function casts(): array
    {
        return ['qr_expires_at' => 'datetime', 'opens_at' => 'datetime', 'closes_at' => 'datetime',
            'scores_released_at' => 'datetime', 'total_points' => 'integer'];
    }

    public function classroom() { return $this->belongsTo(Classroom::class); }
    public function submissions() { return $this->hasMany(Submission::class); }

    public function scoresReleased(): bool { return $this->scores_released_at !== null; }
}
