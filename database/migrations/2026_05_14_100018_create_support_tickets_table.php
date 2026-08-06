<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            // student, trainer, volunteer, technical, financial, partner
            $table->string('type')->default('student');
            // low, medium, high, urgent
            $table->string('priority')->default('medium');
            // new, in_progress, waiting_response, resolved, closed
            $table->string('status')->default('new');
            $table->string('subject');
            $table->text('message');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
