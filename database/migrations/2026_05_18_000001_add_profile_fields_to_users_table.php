<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('department')->nullable()->after('is_active');
            $table->string('avatar')->nullable()->after('department');          // storage path
            $table->string('city')->nullable()->after('avatar');
            $table->string('country', 100)->nullable()->after('city');
            $table->string('how_did_you_hear_about_us')->nullable()->after('country');
            $table->timestamp('last_login_at')->nullable()->after('how_did_you_hear_about_us');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'is_active', 'department', 'avatar',
                'city', 'country', 'how_did_you_hear_about_us', 'last_login_at',
            ]);
        });
    }
};
