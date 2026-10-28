<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Re-runnable column by column: two tables are altered (DDL cannot be rolled back on MariaDB), so a failure between
        // them must not make the next run fail with "duplicate column".
        Schema::table('meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('meetings', 'moderator_id')) {
                $table->foreignId('moderator_id')->nullable()->after('organizer_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('meetings', 'top_interactive_user_id')) {
                $table->foreignId('top_interactive_user_id')->nullable()->after('moderator_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('meetings', 'discipline_rate')) {
                $table->decimal('discipline_rate', 5, 2)->nullable()->after('status'); // Auto calculated attendance %
            }
        });

        Schema::table('meeting_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('meeting_participants', 'excuse_status')) {
                $table->string('excuse_status', 20)->nullable()->after('status'); // 'with_excuse', 'without_excuse'
            }
            if (! Schema::hasColumn('meeting_participants', 'excuse_reason')) {
                $table->text('excuse_reason')->nullable()->after('excuse_status');
            }
        });
    }

    public function down(): void
    {
        $participantColumns = array_values(array_filter(
            ['excuse_status', 'excuse_reason'],
            fn ($column) => Schema::hasColumn('meeting_participants', $column),
        ));
        if ($participantColumns !== []) {
            Schema::table('meeting_participants', fn (Blueprint $table) => $table->dropColumn($participantColumns));
        }

        // MySQL/MariaDB refuse to drop a column while a foreign key still uses it: drop the keys first.
        $foreign = collect(Schema::getForeignKeys('meetings'))->pluck('columns')->flatten()->all();
        foreach (['moderator_id', 'top_interactive_user_id'] as $column) {
            if (in_array($column, $foreign, true)) {
                Schema::table('meetings', fn (Blueprint $table) => $table->dropForeign([$column]));
            }
        }
        $meetingColumns = array_values(array_filter(
            ['moderator_id', 'top_interactive_user_id', 'discipline_rate'],
            fn ($column) => Schema::hasColumn('meetings', $column),
        ));
        if ($meetingColumns !== []) {
            Schema::table('meetings', fn (Blueprint $table) => $table->dropColumn($meetingColumns));
        }
    }
};
