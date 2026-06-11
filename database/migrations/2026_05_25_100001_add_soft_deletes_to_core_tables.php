<?php

use Illuminate\Database\Migrations\Migration;

// softDeletes() consolidated into all 12 create migrations:
// users, courses, registrations, payments, instructors, course_modules,
// course_sessions, course_materials, course_assignments, certificates, tracks, workshops
return new class extends Migration {
    public function up(): void {}
    public function down(): void {}
};
