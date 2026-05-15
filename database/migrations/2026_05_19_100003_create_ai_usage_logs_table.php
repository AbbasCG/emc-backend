<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider');              // openai, anthropic, mock
            $table->string('model');
            $table->string('action');                // chat, embed, generate_outline, summarize_meeting…
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            $table->string('status')->default('success'); // success, failed
            $table->timestamps();

            $table->index(['provider', 'model']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
