<?php

use Illuminate\Database\Migrations\Migration;

// Status defaults corrected in create migrations:
//   courses.status  default 'published' (was 'active')
//   registrations.status  default 'registered' (was 'Registered')
//   payments.status  default 'pending_payment' (was 'Pending Payment')
// On a fresh database these UPDATE statements are always no-ops.
return new class extends Migration {
    public function up(): void {}
    public function down(): void {}
};
