<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context_type')->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('title')->nullable();
            // student_assistant|course_tutor|admin_chat|etc.
            $table->string('type')->default('student_assistant');
            // private|shared
            $table->string('visibility')->default('private');
            // active|archived
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
