<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('system_prompt');
            $table->json('variables')->nullable();  // ['name', 'course', 'date']
            $table->string('category');             // student, trainer, operations, reports…
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status')->default('active'); // active, draft, deprecated
            $table->timestamps();

            $table->index(['category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
