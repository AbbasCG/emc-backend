<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // مهمة مقفلة = مُسلَّمة نهائيًا: لا يعدلها ولا يحذفها أحد سوى فتحها
        // أولًا من مدير مخوَّل. يدعم لوحة التشغيل المرئية للجميع.
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('status');
            $table->foreignId('locked_by')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn('locked_at');
        });
    }
};
