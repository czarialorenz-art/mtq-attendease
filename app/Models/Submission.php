<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A student's record for one assessment: the uploaded photo (answer) and the earned score.
 * The maximum score lives on the assessment (total_points).
 */
class Submission extends Model
{
    protected $fillable = ['assessment_id', 'student_id', 'answer', 'score',
    'is_verified', 'verified_at', 'submitted_at', 'scanned_at'];

    protected $attributes = ['score' => 0];

    protected function casts(): array
    {
        return ['is_verified' => 'boolean', 'verified_at' => 'datetime', 'submitted_at' => 'datetime',
            'scanned_at' => 'datetime', 'score' => 'integer'];
    }

    public function assessment() { return $this->belongsTo(Assessment::class); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }

    public function hasImage(): bool { return is_string($this->answer) && $this->answer !== ''; }

    /** URL of the authenticated image endpoint (the file itself is not publicly served). */
    public function imageUrl(): ?string
    {
        return $this->hasImage() ? "/api/submissions/{$this->id}/image" : null;
    }
}
