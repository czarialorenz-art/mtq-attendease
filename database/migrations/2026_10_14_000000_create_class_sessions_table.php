<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->date('session_date');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->timestamps();

            $table->index(['classroom_id', 'session_date']);
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->foreignId('class_session_id')->nullable()
                ->constrained('class_sessions')->nullOnDelete();
        });

        // Backfill: group existing QR windows into one session per class per day
        DB::table('attendance_sessions')->orderBy('started_at')->get()->each(function ($s) {
            $started = Carbon::parse($s->started_at)->timezone('Asia/Manila');
            $date = $started->toDateString();

            $existing = DB::table('class_sessions')
                ->where('classroom_id', $s->classroom_id)
                ->where('session_date', $date)->first();

            $id = $existing->id ?? DB::table('class_sessions')->insertGetId([
                'classroom_id' => $s->classroom_id,
                'session_date' => $date,
                'starts_at' => $started->format('H:i:s'),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('attendance_sessions')->where('id', $s->id)
                ->update(['class_session_id' => $id]);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_session_id');
        });
        Schema::dropIfExists('class_sessions');
    }
};