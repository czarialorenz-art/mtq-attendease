<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->time('time_in_start')->nullable();
            $table->time('time_in_end')->nullable();
            $table->time('time_out_start')->nullable();
            $table->time('time_out_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn(['time_in_start', 'time_in_end', 'time_out_start', 'time_out_end']);
        });
    }
};