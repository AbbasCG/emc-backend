<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')
                  ->constrained('placement_tests')
                  ->cascadeOnDelete();
            $table->text('question_text');
            $table->string('option_a');
            $table->string('option_b');
            $table->string('option_c');
            $table->string('option_d');
            $table->char('correct_option', 1); // 'a' | 'b' | 'c' | 'd'
            $table->unsignedSmallInteger('order')->default(1);
            $table->string('level_band')->nullable(); // beginner | elementary | pre_intermediate | intermediate | upper_intermediate | advanced
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_test_questions');
    }
};
