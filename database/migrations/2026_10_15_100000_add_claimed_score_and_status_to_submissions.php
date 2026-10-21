<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Score the student wrote on their paper, entered when submitting the photo
            $table->decimal('claimed_score', 8, 2)->nullable()->after('score');
            // Teacher's decision on the submission: pending, accepted or rejected
            $table->string('status', 20)->default('pending')->after('claimed_score');
            // An accepted claimed score (e.g. 17.5) becomes the official score, so it must hold decimals
            $table->decimal('score', 12, 2)->default(0)->change();
        });

        // Submissions the teacher already checked count as accepted
        DB::table('submissions')->where('is_verified', true)->update(['status' => 'accepted']);

        // Students now see a score once it is accepted; per-assessment release is gone
        if (Schema::hasColumn('assessments', 'scores_released_at')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->dropColumn('scores_released_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestamp('scores_released_at')->nullable();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['claimed_score', 'status']);
            $table->unsignedInteger('score')->default(0)->change();
        });
    }
};
