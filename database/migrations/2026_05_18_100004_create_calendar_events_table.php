<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->nullableMorphs('eventable'); // session, meeting, task…
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_url')->nullable();
            $table->string('external_provider')->nullable(); // google, outlook
            $table->string('external_event_id')->nullable();
            $table->string('sync_status')->nullable();       // synced, pending, failed
            $table->timestamps();

            $table->index(['user_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
