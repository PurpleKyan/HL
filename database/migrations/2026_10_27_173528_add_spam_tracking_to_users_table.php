<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('spam_violation_count')->default(0)->after('is_active');
            $table->timestamp('spam_last_submission_at')->nullable()->after('spam_violation_count');
            $table->string('spam_validation_status')->default('clear')->after('spam_last_submission_at');
            $table->timestamp('spam_restriction_expires_at')->nullable()->after('spam_validation_status');
            $table->string('spam_last_request_hash')->nullable()->after('spam_restriction_expires_at');
            $table->timestamp('spam_flagged_at')->nullable()->after('spam_last_request_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'spam_violation_count',
                'spam_last_submission_at',
                'spam_validation_status',
                'spam_restriction_expires_at',
                'spam_last_request_hash',
                'spam_flagged_at',
            ]);
        });
    }
};
