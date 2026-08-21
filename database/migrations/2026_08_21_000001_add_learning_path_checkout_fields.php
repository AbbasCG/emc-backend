<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('learning_path_id')
                ->nullable()
                ->after('course_id')
                ->constrained('learning_paths')
                ->nullOnDelete();

            $table->index(['user_id', 'learning_path_id', 'status'], 'orders_user_path_status_index');
        });

        Schema::table('learning_path_enrollments', function (Blueprint $table) {
            $table->timestamp('enrolled_at')->nullable()->change();
            $table->foreignId('order_id')
                ->nullable()
                ->after('user_id')
                ->constrained('orders')
                ->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('registration_id')->nullable()->change();
            $table->foreignId('learning_path_enrollment_id')
                ->nullable()
                ->after('registration_id')
                ->constrained('learning_path_enrollments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learning_path_enrollment_id');
        });

        Schema::table('learning_path_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->timestamp('enrolled_at')->useCurrent()->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_path_status_index');
            $table->dropConstrainedForeignId('learning_path_id');
        });
    }
};
