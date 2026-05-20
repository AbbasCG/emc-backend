<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicate profiles for the same user before adding the constraint.
        // Keep the row with the smallest id (oldest profile) for each user_id.
        \Illuminate\Support\Facades\DB::statement('
            DELETE FROM instructors
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM instructors
                WHERE user_id IS NOT NULL
                GROUP BY user_id
            )
            AND user_id IS NOT NULL
        ');

        Schema::table('instructors', function (Blueprint $table) {
            $table->unique('user_id', 'instructors_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            $table->dropUnique('instructors_user_id_unique');
        });
    }
};
