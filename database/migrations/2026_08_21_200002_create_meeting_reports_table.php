<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->string('title');
            $table->date('meeting_date');
            $table->text('achieved')->nullable();       // ماذا أنجزوا
            $table->text('planned')->nullable();        // ماذا سينجزون
            $table->text('needs')->nullable();          // ماذا يحتاجون/يريدون
            $table->text('needs_reason')->nullable();   // ولماذا
            $table->text('decisions')->nullable();      // قرارات المحضر
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['department_id', 'meeting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reports');
    }
};
