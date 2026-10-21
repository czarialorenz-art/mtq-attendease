<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's record for one assessment: the uploaded photo (answer), the score the student wrote on it
 * (claimed_score), the teacher's decision (status) and the official score. The maximum lives on the assessment.
 */
class Submission extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    protected $fillable = ['assessment_id', 'student_id', 'answer', 'score', 'claimed_score', 'status',
    'is_verified', 'verified_at', 'submitted_at', 'scanned_at'];

    protected $attributes = ['score' => 0, 'status' => self::PENDING];

    protected function casts(): array
    {
        return ['is_verified' => 'boolean', 'verified_at' => 'datetime', 'submitted_at' => 'datetime',
            'scanned_at' => 'datetime'];
    }

    public function assessment() { return $this->belongsTo(Assessment::class); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }

    public function hasImage(): bool { return is_string($this->answer) && $this->answer !== ''; }
    public function isAccepted(): bool { return $this->status === self::ACCEPTED; }

    /** Whole numbers stay integers (20, not "20.00"); halves and other decimals come back as floats. */
    protected function score(): Attribute { return Attribute::get(fn ($v) => self::number($v)); }
    protected function claimedScore(): Attribute { return Attribute::get(fn ($v) => self::number($v)); }

    private static function number($v): int|float|null
    {
        if ($v === null) {
            return null;
        }
        $f = (float) $v;

        return floor($f) === $f ? (int) $f : $f;
    }

    /** URL of the authenticated image endpoint (the file itself is not publicly served). */
    public function imageUrl(): ?string
    {
        return $this->hasImage() ? "/api/submissions/{$this->id}/image" : null;
    }
}
