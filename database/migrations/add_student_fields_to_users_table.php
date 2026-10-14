<?php

   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           Schema::table('users', function (Blueprint $table) {
               foreach (['school_id', 'course', 'year_level', 'section', 'department'] as $col) {
                   if (! Schema::hasColumn('users', $col)) {
                       $table->string($col)->nullable();
                   }
               }
           });
       }

       public function down(): void
       {
           //
       }
   };