<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('verification_code')->nullable();          // hashed 6-digit code
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('verification_sent_at')->nullable();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
            $table->string('pending_email')->nullable();              // new address waiting for its code
        });

        // Accounts that already exist keep working; only new sign-ups and email changes need a code
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_code', 'verification_expires_at', 'verification_sent_at',
                'verification_attempts', 'pending_email']);
        });
    }
};
