<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'teacher')->update(['role' => 'instructor']);
    }

    public function down(): void
    {
        // Intentionally not reversing — 'teacher' is a deprecated alias; rolling back is unsafe.
    }
};
