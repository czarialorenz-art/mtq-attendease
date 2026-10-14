<?php

   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           Schema::table('attendance_sessions', function (Blueprint $table) {
               if (! Schema::hasColumn('attendance_sessions', 'mode')) {
                   $table->string('mode')->default('f2f')->after('classroom_id');
               }
               if (! Schema::hasColumn('attendance_sessions', 'stages')) {
                   $table->json('stages')->nullable()->after('mode');
               }
               if (! Schema::hasColumn('attendance_sessions', 'exp_seconds')) {
                   $table->unsignedInteger('exp_seconds')->default(60)->after('stages');
               }
               if (! Schema::hasColumn('attendance_sessions', 'expires_at')) {
                   $table->timestamp('expires_at')->nullable()->after('exp_seconds');
               }
           });
       }

       public function down(): void
       {
           //
       }
   };