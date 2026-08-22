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
        Schema::table('partners', function (Blueprint $table) {
            $table->string('project_scope', 50)->default('EMC_GENERAL')->after('id');
            $table->string('classification', 50)->nullable()->after('type');
            $table->text('field_of_work')->nullable()->after('classification');
            $table->string('contact_position', 150)->nullable()->after('contact_person');
            $table->string('assigned_to', 100)->nullable()->after('status');
            $table->date('first_contact_date')->nullable()->after('assigned_to');
            $table->date('last_contact_date')->nullable()->after('first_contact_date');
            $table->text('rejection_reason')->nullable()->after('last_contact_date');
            $table->text('attachment_url')->nullable()->after('rejection_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'project_scope',
                'classification',
                'field_of_work',
                'contact_position',
                'assigned_to',
                'first_contact_date',
                'last_contact_date',
                'rejection_reason',
                'attachment_url',
            ]);
        });
    }
};
