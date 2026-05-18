<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->index();          // super_admin who initiated
            $table->string('event');                                   // impersonation_started, impersonation_stopped
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->unsignedBigInteger('sanctum_token_id')->nullable()->index(); // links to personal_access_tokens.id
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_logs');
    }
};
