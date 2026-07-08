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
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('bank'); // bank|cash|paypal|stripe|ing|other
            $table->string('currency', 10)->default('EUR');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_accounts');
    }
};
