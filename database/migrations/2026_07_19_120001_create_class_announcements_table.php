<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->string('priority', 20)->default('normal'); // normal | important | urgent
            $table->string('status', 20)->default('draft'); // draft | published | archived
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_announcements');
    }
};
