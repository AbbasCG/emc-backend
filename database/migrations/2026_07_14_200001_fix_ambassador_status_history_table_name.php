<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $singular = 'ambassador_application_status_history';
        $plural   = 'ambassador_application_status_histories';

        if (Schema::hasTable($plural)) {
            return; // Already correct — nothing to do
        }

        if (Schema::hasTable($singular)) {
            // Rename the existing singular table to the canonical plural name
            Schema::rename($singular, $plural);
        } else {
            // Fresh install — create the table from scratch
            Schema::create($plural, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ambassador_application_id');
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->text('reason')->nullable();
                $table->timestamp('changed_at')->useCurrent();
                $table->timestamps();

                $table->foreign('ambassador_application_id', 'amb_stat_hist_app_fk')
                    ->references('id')
                    ->on('ambassador_applications')
                    ->cascadeOnDelete();

                $table->foreign('changed_by', 'amb_stat_hist_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index('ambassador_application_id', 'amb_stat_hist_app_idx');
            });
        }
    }

    public function down(): void
    {
        $plural   = 'ambassador_application_status_histories';
        $singular = 'ambassador_application_status_history';

        if (Schema::hasTable($plural) && ! Schema::hasTable($singular)) {
            Schema::rename($plural, $singular);
        }
    }
};
