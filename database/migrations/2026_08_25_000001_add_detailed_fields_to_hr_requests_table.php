<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_requests', 'employment_type')) {
                $table->string('employment_type', 50)->nullable()->after('department_id');
            }
            if (! Schema::hasColumn('hr_requests', 'job_title')) {
                $table->string('job_title', 150)->nullable()->after('employment_type');
            }
            if (! Schema::hasColumn('hr_requests', 'qualification')) {
                $table->string('qualification', 255)->nullable()->after('required_count');
            }
            if (! Schema::hasColumn('hr_requests', 'responsibilities')) {
                $table->text('responsibilities')->nullable()->after('qualification');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_requests', function (Blueprint $table) {
            $cols = array_filter(
                ['employment_type', 'job_title', 'qualification', 'responsibilities'],
                fn (string $c) => Schema::hasColumn('hr_requests', $c)
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
