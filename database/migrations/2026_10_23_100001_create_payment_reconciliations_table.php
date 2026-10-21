<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmed payment whose enrollment could not be granted (today: the payer became the instructor of the program between starting
 * checkout and the payment webhook). The money stays recorded as received; this row is the open item somebody must settle. Nothing is
 * refunded or cancelled automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_reconciliations')) {
            return;
        }

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->id();
            // One open item per payment: a webhook delivered twice must not create two.
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->unsignedBigInteger('learning_path_enrollment_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('learning_path_id')->nullable();
            $table->string('reason', 40);                    // instructor_conflict
            $table->string('status', 20)->default('open');   // open | resolved
            $table->json('details')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 40)->nullable();    // enrollment_activated | closed_without_enrollment
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            // Leads with a non-foreign-key column on purpose: an index that starts with an FK column replaces the FK's own index
            // on MariaDB and breaks the rollback.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
    }
};
