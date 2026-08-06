<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('action')->index();           // created, updated, deleted, status_changed, role_changed, approved, rejected, restored
            $table->string('entity_type')->index();      // class basename e.g. User, TeamMember, Workshop
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_label')->nullable();  // human-readable name/title
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();        // e.g. impersonated_as_id
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
