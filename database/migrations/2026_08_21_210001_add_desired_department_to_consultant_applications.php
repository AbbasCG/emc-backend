<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // المتقدم يختار أي إدارة يرغب أن يكون مستشارًا لها.
        Schema::table('consultant_applications', function (Blueprint $table) {
            $table->string('desired_department')->nullable()->after('specialty');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_applications', function (Blueprint $table) {
            $table->dropColumn('desired_department');
        });
    }
};
