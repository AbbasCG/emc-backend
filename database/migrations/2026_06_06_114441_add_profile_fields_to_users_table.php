<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'country_code')) {
                $table->string('country_code', 5)->nullable()->after('country');
            }
            if (! Schema::hasColumn('users', 'phone_country_code')) {
                $table->string('phone_country_code', 10)->nullable()->after('country_code');
            }
            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 20)->nullable()->after('city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('users', 'country_code')       ? 'country_code' : null,
                Schema::hasColumn('users', 'phone_country_code') ? 'phone_country_code' : null,
                Schema::hasColumn('users', 'gender')             ? 'gender' : null,
            ]));
        });
    }
};
