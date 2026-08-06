<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('dynamic_forms')->cascadeOnDelete();
            $table->string('label');
            // text, textarea, email, phone, number, select, multiselect, radio, checkbox, date, file
            $table->string('type')->default('text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_questions');
    }
};
