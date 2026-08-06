<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('department_sections')->nullOnDelete();
            $table->string('role_title')->nullable();
            $table->string('status')->default('active'); // active, inactive, on_leave
            $table->date('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
