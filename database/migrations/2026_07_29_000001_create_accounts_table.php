<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->enum('type', ['debit', 'credit']);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->onDelete('restrict');
            $table->boolean('is_selectable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
