<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a team member to a departmental_units row (the EMC Tickets "technical
 * unit" concept) — mirrors the existing section_id column's shape/behavior
 * exactly (nullable, nullOnDelete) so a unit going away never deletes the
 * membership itself, only the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('section_id')
                ->constrained('departmental_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
