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
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->string('user_key_hash')->nullable()->index();
            $table->string('topic')->nullable()->index();
            $table->decimal('fluency_score', 4, 1)->nullable();
            $table->decimal('grammar_score', 4, 1)->nullable();
            $table->decimal('overall_score', 4, 1)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('feedback_generated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_key_hash']);
            $table->dropIndex(['topic']);
            $table->dropColumn([
                'user_key_hash',
                'topic',
                'fluency_score',
                'grammar_score',
                'overall_score',
                'feedback',
                'feedback_generated_at',
            ]);
        });
    }
};
