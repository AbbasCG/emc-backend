<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * No-op — superseded by 2026_08_16_000850_create_meeting_reports_table,
     * which was widened during the EMC-NEW-BACK merge to create the
     * `meeting_reports` table with columns for both report features (see
     * that migration's docblock). This file is kept only so the later
     * 2026_08_21_200003/2026_08_21_220001 migrations that depend on it
     * running first (by timestamp order) still find `meeting_reports`
     * already present.
     */
    public function up(): void
    {
        // Intentionally empty — table created earlier, see docblock above.
    }

    public function down(): void
    {
        // Intentionally empty — 2026_08_16_000850 owns dropping the table.
    }
};
