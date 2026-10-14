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
        Schema::create('attendance_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
    $table->string('title')->nullable();          // e.g. "Lecture 5"
    $table->date('session_date');
    $table->time('starts_at');
    $table->time('ends_at')->nullable();
    $table->timestamp('time_in_opened_at')->nullable();
    $table->timestamp('time_out_opened_at')->nullable();
    $table->enum('status', ['open', 'closed'])->default('open');
    $table->timestamps();

    $table->index(['subject_id', 'session_date']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
