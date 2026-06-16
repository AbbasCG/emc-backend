<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oral_assessment_bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('pronunciation_score')->nullable()->after('oral_score');
            $table->unsignedTinyInteger('grammar_score')->nullable()->after('pronunciation_score');
            $table->unsignedTinyInteger('vocabulary_score')->nullable()->after('grammar_score');
            $table->unsignedTinyInteger('fluency_score')->nullable()->after('vocabulary_score');
            $table->unsignedTinyInteger('comprehension_score')->nullable()->after('fluency_score');
            $table->unsignedTinyInteger('confidence_score')->nullable()->after('comprehension_score');
        });
    }

    public function down(): void
    {
        Schema::table('oral_assessment_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'pronunciation_score',
                'grammar_score',
                'vocabulary_score',
                'fluency_score',
                'comprehension_score',
                'confidence_score',
            ]);
        });
    }
};
