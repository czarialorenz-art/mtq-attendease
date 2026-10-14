<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
    $table->foreignId('student_id')->constrained('users')->cascadeOnDelete(); // change if you have a students table
    $table->timestamp('time_in_at')->nullable();
    $table->timestamp('time_out_at')->nullable();
    $table->enum('status', ['present', 'late', 'absent', 'excused'])->default('absent');
    $table->timestamps();

    $table->unique(['attendance_session_id', 'student_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
