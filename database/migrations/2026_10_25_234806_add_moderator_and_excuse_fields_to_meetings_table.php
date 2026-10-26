<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreignId('moderator_id')->nullable()->after('organizer_id')->constrained('users')->nullOnDelete();
            $table->foreignId('top_interactive_user_id')->nullable()->after('moderator_id')->constrained('users')->nullOnDelete();
            $table->decimal('discipline_rate', 5, 2)->nullable()->after('status'); // Auto calculated attendance %
        });

        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->string('excuse_status', 20)->nullable()->after('status'); // 'with_excuse', 'without_excuse'
            $table->text('excuse_reason')->nullable()->after('excuse_status');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->dropColumn(['excuse_status', 'excuse_reason']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['moderator_id']);
            $table->dropForeign(['top_interactive_user_id']);
            $table->dropColumn(['moderator_id', 'top_interactive_user_id', 'discipline_rate']);
        });
    }
};
