<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('class_group_id')->nullable()->after('lesson_id')
                ->constrained('class_groups')->nullOnDelete();
            $table->dateTime('opens_at')->nullable()->after('status');
            $table->dateTime('closes_at')->nullable()->after('opens_at');
            $table->unsignedTinyInteger('max_attempts')->default(1)->after('closes_at');
            $table->boolean('counts_toward_final_grade')->default(false)->after('max_attempts');
            $table->boolean('is_final_exam')->default(false)->after('counts_toward_final_grade');
            $table->boolean('required')->default(true)->after('is_final_exam');
            $table->decimal('weight', 5, 2)->nullable()->after('required');
            $table->boolean('shuffle_questions')->default(false)->after('weight');
            $table->boolean('shuffle_answers')->default(false)->after('shuffle_questions');
            $table->boolean('show_result_after_submission')->default(true)->after('shuffle_answers');
            $table->boolean('show_correct_answers')->default(false)->after('show_result_after_submission');
            $table->foreignId('created_by')->nullable()->after('show_correct_answers')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable()->after('created_by');
            $table->unsignedInteger('position')->default(0)->after('published_at');
            $table->softDeletes();

            $table->index(['course_id', 'class_group_id']);
            $table->index(['opens_at', 'closes_at']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt_number')->default(1)->after('user_id');
            $table->decimal('max_score', 6, 2)->nullable()->after('score');
            $table->boolean('passed')->nullable()->after('max_score');
            $table->dateTime('started_at')->nullable()->after('passed');

            $table->unique(['quiz_id', 'user_id', 'attempt_number'], 'quiz_attempts_quiz_user_attempt_unique');
            $table->index(['quiz_id', 'user_id']);
        });

        Schema::create('course_assessment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->unique()->constrained('courses')->cascadeOnDelete();
            $table->decimal('quiz_component_weight', 5, 2)->default(0);
            $table->decimal('final_exam_weight', 5, 2)->default(100);
            $table->string('quiz_weighting_mode')->default('equal'); // equal|custom
            // Deliberately NOT wired into CertificateEligibilityService yet — an
            // explicit future toggle, kept false so existing courses/certificates
            // are entirely unaffected until an instructor/admin opts in.
            $table->boolean('gates_certificate')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_assessment_settings');

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropUnique('quiz_attempts_quiz_user_attempt_unique');
            $table->dropIndex(['quiz_id', 'user_id']);
            $table->dropColumn(['attempt_number', 'max_score', 'passed', 'started_at']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex(['opens_at', 'closes_at']);
            $table->dropIndex(['course_id', 'class_group_id']);
            $table->dropConstrainedForeignId('class_group_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'opens_at', 'closes_at', 'max_attempts', 'counts_toward_final_grade',
                'is_final_exam', 'required', 'weight', 'shuffle_questions', 'shuffle_answers',
                'show_result_after_submission', 'show_correct_answers', 'published_at', 'position',
            ]);
        });
    }
};
