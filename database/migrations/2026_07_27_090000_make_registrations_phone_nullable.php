<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `phone` was NOT NULL with no default since the original registrations
     * table (city/country are already nullable) — but CheckoutController's
     * one-click "pay now" flow creates a Registration straight from
     * $request->user() with no form to collect a phone number, so any
     * authenticated user without a phone already on their profile hit a
     * SQLSTATE 23000 "Field 'phone' doesn't have a default value" on every
     * paid-course checkout attempt. Nullable brings it in line with
     * city/country, which are optional profile fields for the same reason.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
        });
    }
};
