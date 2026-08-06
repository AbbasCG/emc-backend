<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->longText('summary');
            $table->json('decisions')->nullable();
            $table->json('action_items')->nullable();
            $table->json('risks')->nullable();
            $table->json('blockers')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('token_usage')->nullable();
            $table->timestamps();

            $table->index('meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_ai_summaries');
    }
};
