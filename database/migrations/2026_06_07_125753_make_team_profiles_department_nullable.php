<?php

use Illuminate\Database\Migrations\Migration;

// Superseded by 2026_05_20_100002_create_team_profiles_table.php
// department_id is nullable nullOnDelete in the create migration from the start.
return new class extends Migration
{
    public function up(): void {}
    public function down(): void {}
};
