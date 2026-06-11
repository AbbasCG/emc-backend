<?php

use Illuminate\Database\Migrations\Migration;

// Superseded by 2026_05_21_000005_create_course_materials_table.php
// (Moved to resolve FK ordering: course_modules and course_sessions
//  are created after this original timestamp.)
return new class extends Migration
{
    public function up(): void {}
    public function down(): void {}
};
