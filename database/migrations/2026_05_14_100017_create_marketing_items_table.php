<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            // instagram, linkedin, facebook, tiktok, website, email, whatsapp, other
            $table->string('platform')->default('other');
            // idea, writing, design, review, approved, scheduled, published, archived
            $table->string('status')->default('idea');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->date('publish_date')->nullable();
            $table->text('content_text')->nullable();
            $table->json('assets')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_items');
    }
};
