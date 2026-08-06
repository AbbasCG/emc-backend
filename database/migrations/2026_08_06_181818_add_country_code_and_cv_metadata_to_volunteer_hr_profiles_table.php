<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds canonical country/phone metadata (matching the signup page's
 * CountrySelector: ISO code + calling code) and CV file metadata needed to
 * render a complete "documents" section without exposing the raw storage
 * path. All nullable — no backfill required, existing rows keep working
 * with `country` (free text) and a filename derived from `cv_path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_hr_profiles', function (Blueprint $table) {
            $table->string('country_code', 5)->nullable()->after('country');
            $table->string('phone_country_code', 10)->nullable()->after('phone');
            $table->string('cv_original_name')->nullable()->after('cv_path');
            $table->string('cv_mime_type', 100)->nullable()->after('cv_original_name');
            $table->unsignedBigInteger('cv_size')->nullable()->after('cv_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_hr_profiles', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'phone_country_code', 'cv_original_name', 'cv_mime_type', 'cv_size']);
        });
    }
};
