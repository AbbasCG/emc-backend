<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnership_requests', function (Blueprint $table) {
            $table->id();
            $table->string('partner_name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone')->nullable();
            // university, school, company, institution, donor, community
            $table->string('type')->default('company');
            $table->text('message');
            // new, under_review, approved, rejected, archived
            $table->string('status')->default('new');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnership_requests');
    }
};
