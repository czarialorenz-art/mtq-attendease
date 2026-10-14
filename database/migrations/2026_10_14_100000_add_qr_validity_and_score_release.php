<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scores: a new score record starts at 0 (earned points; the maximum is assessments.total_points)
        DB::table('submissions')->whereNull('score')->update(['score' => 0]);
        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedInteger('score')->default(0)->nullable(false)->change();
        });

        // Teacher decides when students may see the scores of an assessment
        Schema::table('assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('assessments', 'scores_released_at')) {
                $table->timestamp('scores_released_at')->nullable();
            }
        });

        // Attendance QR windows are valid only until the end of their session's date (Asia/Manila)
        DB::table('attendance_sessions')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendance_sessions.class_session_id')
            ->select('attendance_sessions.id', 'class_sessions.session_date')
            ->get()
            ->each(fn ($row) => DB::table('attendance_sessions')->where('id', $row->id)->update([
                'expires_at' => Carbon::parse($row->session_date, 'Asia/Manila')->endOfDay()->utc(),
            ]));
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('scores_released_at');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedInteger('score')->nullable()->default(null)->change();
        });
    }
};
