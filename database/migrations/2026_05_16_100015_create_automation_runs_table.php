<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            // pending|success|failed
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
