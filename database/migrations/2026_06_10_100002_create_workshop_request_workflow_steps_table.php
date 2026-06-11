<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_request_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_request_id')
                ->constrained('workshop_requests')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('step_number');
            $table->string('department_key', 50);
            $table->string('department_label', 120);
            $table->enum('status', ['pending', 'approved', 'rejected', 'returned'])->default('pending');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workshop_request_id', 'step_number'], 'wrws_request_step_idx');
            $table->index('department_key', 'wrws_dept_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_request_workflow_steps');
    }
};
