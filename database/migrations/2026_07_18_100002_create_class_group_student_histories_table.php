<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_student_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_group_student_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('from_class_group_id')->nullable();
            $table->unsignedBigInteger('to_class_group_id')->nullable();
            $table->string('action', 30); // assigned | moved | removed
            $table->string('reason', 30)->nullable(); // automatic | manual
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->foreign('class_group_student_id', 'cgs_hist_cgs_fk')
                ->references('id')->on('class_group_students')->cascadeOnDelete();
            $table->foreign('changed_by', 'cgs_hist_user_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index('class_group_student_id', 'cgs_hist_cgs_idx');
            $table->index('user_id', 'cgs_hist_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_student_histories');
    }
};
